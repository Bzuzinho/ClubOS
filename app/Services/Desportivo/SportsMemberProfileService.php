<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\AthleteAgeGroupOverride;
use App\Models\AthleteSportsData;
use App\Models\Season;
use App\Models\SportsAthleteFederationAffiliation;
use App\Models\SportsAthleteLimitation;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsFederation;
use App\Models\SportsLimitationType;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class SportsMemberProfileService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberSportsIdentityProvider $memberIdentityProvider,
        private readonly AgeGroupPlacementService $ageGroupPlacementService,
        private readonly SportsStructureService $sportsStructureService,
        private readonly UserTypeAccessControlService $accessControlService,
    ) {
    }

    public function syncFromMemberWrite(User $user, array $payload, ?string $actorId = null): ?AthleteSportsData
    {
        if (! Schema::hasTable('sports_athlete_participations')) {
            return $this->legacyProfileOnly($user, $payload);
        }

        $identity = $this->memberIdentityProvider->forSports($user->fresh());

        DB::transaction(function () use ($user, $payload, $actorId, $identity): void {
            if (! $identity['is_athlete']) {
                $this->deactivateAllParticipations(
                    $user,
                    $actorId,
                    'Tipo Atleta removido ou desativado em Membros.'
                );
            } elseif (array_key_exists('ativo_desportivo', $payload)) {
                if ((bool) $payload['ativo_desportivo']) {
                    $this->ensureDefaultParticipation($user, $payload, $actorId);
                } else {
                    $this->deactivateAllParticipations(
                        $user,
                        $actorId,
                        'Atividade desportiva desativada em Membros.'
                    );
                }
            }

            $this->refreshSeasonProfiles($user, $actorId);
            $this->projectLegacyCompatibility($user, $payload);
        }, 3);

        return AthleteSportsData::query()->where('user_id', $user->id)->first();
    }

    /**
     * Canonical write contract used by the Sports section inside the Membros UI.
     * Membros supplies identity; every sporting mutation remains owned here.
     */
    public function updateFromMemberSurface(User $member, array $data, User $actor): void
    {
        $identity = $this->memberIdentityProvider->forSports($member);

        DB::transaction(function () use ($member, $data, $actor, $identity): void {
            foreach ($data['participations'] ?? [] as $row) {
                $modality = SportsModality::query()
                    ->where('club_id', $this->clubId())
                    ->findOrFail($row['sports_modality_id']);

                if ((bool) ($row['active'] ?? false)) {
                    if (! $identity['is_athlete']) {
                        throw ValidationException::withMessages([
                            'participations' => 'A participação desportiva só pode ser ativada para um membro do tipo Atleta.',
                        ]);
                    }

                    $this->activateParticipation(
                        $member,
                        $modality,
                        $row['starts_at'] ?? null,
                        $row['reason'] ?? 'Ativação pela ficha de Membros.',
                        $actor->id,
                    );
                } else {
                    $this->deactivateParticipation(
                        $member,
                        $modality,
                        $row['ends_at'] ?? null,
                        $row['reason'] ?? 'Desativação pela ficha de Membros.',
                        $actor->id,
                    );
                }
            }

            if (($data['age_group_overrides'] ?? []) !== []) {
                $this->assertCanManageStructure($actor, 'O override de escalão exige permissão de gestão da Estrutura Desportiva.');

                foreach ($data['age_group_overrides'] as $override) {
                    if ((bool) ($override['end_override'] ?? false)) {
                        $active = AthleteAgeGroupOverride::query()
                            ->where('club_id', $this->clubId())
                            ->where('user_id', $member->id)
                            ->where('season_id', $override['season_id'])
                            ->where('sports_modality_id', $override['sports_modality_id'])
                            ->where('active', true)
                            ->latest('effective_at')
                            ->first();

                        if ($active) {
                            $this->sportsStructureService->endAgeGroupOverride($active, $actor->id);
                        }

                        continue;
                    }

                    $this->sportsStructureService->createAgeGroupOverride([
                        'user_id' => $member->id,
                        'season_id' => $override['season_id'],
                        'sports_modality_id' => $override['sports_modality_id'],
                        'age_group_id' => $override['age_group_id'],
                        'reason' => $override['reason'],
                        'effective_at' => $override['effective_at'] ?? now(),
                    ], $actor->id);
                }
            }

            $this->syncFederationAffiliations($member, $data['federation_affiliations'] ?? [], $actor);
            $this->syncOperationalLimitations($member, $data['limitations'] ?? [], $actor);
            $this->refreshSeasonProfiles($member, $actor->id);
            $this->projectLegacyCompatibility($member, $data['legacy_identifiers'] ?? []);
        }, 3);
    }

    public function refreshSeasonProfiles(User $member, ?string $actorId = null): void
    {
        if (! Schema::hasTable('sports_athlete_season_profiles')) {
            return;
        }

        $identity = $this->memberIdentityProvider->forSports($member);
        if (! $identity['birth_date']) {
            return;
        }

        $participations = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->get();

        foreach ($participations as $participation) {
            $seasons = Season::query()
                ->where('club_id', $this->clubId())
                ->where('sports_modality_id', $participation->sports_modality_id)
                ->orderBy('data_inicio')
                ->get();

            foreach ($seasons as $season) {
                if ($participation->starts_at && $season->data_fim && $participation->starts_at->gt($season->data_fim)) {
                    continue;
                }
                if ($participation->ends_at && $season->data_inicio && $participation->ends_at->lt($season->data_inicio)) {
                    continue;
                }

                $placement = $this->ageGroupPlacementService->resolve(
                    $this->clubId(),
                    (string) $member->id,
                    (string) $season->id,
                    (string) $participation->sports_modality_id,
                    $identity['birth_date'],
                    $identity['sex'],
                );

                $calculatedGroup = $placement['calculated_age_group'] ?? null;
                $officialGroup = $placement['age_group'] ?? null;
                $calculatedId = $calculatedGroup?->id;
                if ($calculatedId === null && ($placement['source'] ?? null) === 'rule') {
                    $calculatedId = $officialGroup?->id;
                }

                SportsAthleteSeasonProfile::query()->updateOrCreate(
                    [
                        'user_id' => $member->id,
                        'season_id' => $season->id,
                        'sports_modality_id' => $participation->sports_modality_id,
                    ],
                    [
                        'club_id' => $this->clubId(),
                        'sports_athlete_participation_id' => $participation->id,
                        'calculated_age_group_id' => $calculatedId,
                        'official_age_group_id' => $officialGroup?->id,
                        'placement_source' => $placement['source'] ?? null,
                        'season_age_group_rule_id' => $placement['rule_id'] ?? null,
                        'athlete_age_group_override_id' => $placement['override_id'] ?? null,
                        'reference_date' => $placement['reference_date'] ?? null,
                        'evaluated_at' => now(),
                        'evaluated_by' => $actorId,
                    ]
                );
            }
        }
    }

    public function activateParticipation(
        User $member,
        SportsModality $modality,
        ?string $startsAt,
        ?string $reason,
        ?string $actorId
    ): SportsAthleteParticipation {
        $this->assertSameClub($modality);

        $existing = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('sports_modality_id', $modality->id)
            ->where('current_slot', 'current')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        return SportsAthleteParticipation::query()->create([
            'club_id' => $this->clubId(),
            'user_id' => $member->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => $startsAt ?: now()->toDateString(),
            'source' => 'sports',
            'start_reason' => $reason,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function deactivateParticipation(
        User $member,
        SportsModality $modality,
        ?string $endsAt,
        ?string $reason,
        ?string $actorId
    ): void {
        $this->assertSameClub($modality);

        SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('sports_modality_id', $modality->id)
            ->where('current_slot', 'current')
            ->lockForUpdate()
            ->get()
            ->each(function (SportsAthleteParticipation $participation) use ($endsAt, $reason, $actorId): void {
                $participation->forceFill([
                    'active' => false,
                    'current_slot' => null,
                    'ends_at' => $endsAt ?: now()->toDateString(),
                    'end_reason' => $reason,
                    'updated_by' => $actorId,
                ])->save();
            });
    }

    private function ensureDefaultParticipation(User $member, array $payload, ?string $actorId): void
    {
        if (SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->exists()) {
            return;
        }

        $modalities = SportsModality::query()
            ->where('club_id', $this->clubId())
            ->where('active', true)
            ->whereNull('archived_at')
            ->get();

        if ($modalities->count() !== 1) {
            return;
        }

        $this->activateParticipation(
            $member,
            $modalities->first(),
            $payload['data_inscricao'] ?? null,
            'Ativação proveniente da escrita de Membros durante o cutover F3.',
            $actorId,
        );
    }

    private function deactivateAllParticipations(User $member, ?string $actorId, string $reason): void
    {
        SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->lockForUpdate()
            ->get()
            ->each(function (SportsAthleteParticipation $participation) use ($actorId, $reason): void {
                $participation->forceFill([
                    'active' => false,
                    'current_slot' => null,
                    'ends_at' => now()->toDateString(),
                    'end_reason' => $reason,
                    'updated_by' => $actorId,
                ])->save();
            });
    }

    private function syncFederationAffiliations(User $member, array $rows, User $actor): void
    {
        foreach ($rows as $row) {
            $participation = SportsAthleteParticipation::query()
                ->where('club_id', $this->clubId())
                ->where('user_id', $member->id)
                ->findOrFail($row['sports_athlete_participation_id']);
            $federation = SportsFederation::query()
                ->where('club_id', $this->clubId())
                ->findOrFail($row['sports_federation_id']);

            if ((string) $participation->sports_modality_id !== (string) $row['sports_modality_id']) {
                throw ValidationException::withMessages([
                    'federation_affiliations' => 'A afiliação federativa não pertence à modalidade da participação.',
                ]);
            }

            SportsAthleteFederationAffiliation::query()->updateOrCreate(
                [
                    'user_id' => $member->id,
                    'sports_athlete_participation_id' => $participation->id,
                    'sports_federation_id' => $federation->id,
                ],
                [
                    'club_id' => $this->clubId(),
                    'sports_modality_id' => $participation->sports_modality_id,
                    'membership_number' => $row['membership_number'] ?? null,
                    'license_number' => $row['license_number'] ?? null,
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                    'active' => (bool) ($row['active'] ?? true),
                    'notes' => $row['notes'] ?? null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]
            );
        }
    }

    private function syncOperationalLimitations(User $member, array $rows, User $actor): void
    {
        if ($rows === []) {
            return;
        }

        $this->assertCanManageStructure($actor, 'A gestão de limitações operacionais exige permissão desportiva adequada.');

        foreach ($rows as $row) {
            if (($row['action'] ?? 'create') === 'end') {
                $limitation = SportsAthleteLimitation::query()
                    ->where('club_id', $this->clubId())
                    ->where('user_id', $member->id)
                    ->findOrFail($row['id']);

                $limitation->forceFill([
                    'active' => false,
                    'ends_at' => $row['ends_at'] ?? now()->toDateString(),
                    'ended_at' => now(),
                    'ended_by' => $actor->id,
                ])->save();

                continue;
            }

            $type = SportsLimitationType::query()
                ->where('club_id', $this->clubId())
                ->findOrFail($row['sports_limitation_type_id']);

            if (! empty($row['sports_modality_id'])) {
                SportsModality::query()
                    ->where('club_id', $this->clubId())
                    ->findOrFail($row['sports_modality_id']);
            }

            SportsAthleteLimitation::query()->create([
                'club_id' => $this->clubId(),
                'user_id' => $member->id,
                'sports_modality_id' => $row['sports_modality_id'] ?? null,
                'sports_limitation_type_id' => $type->id,
                'starts_at' => $row['starts_at'] ?? now()->toDateString(),
                'ends_at' => $row['ends_at'] ?? null,
                'operational_instruction' => $row['operational_instruction'] ?? $type->instrucao_padrao,
                'allows_training' => (bool) $type->allows_training,
                'allows_competition' => (bool) $type->allows_competition,
                'active' => true,
                'created_by' => $actor->id,
            ]);
        }
    }

    private function projectLegacyCompatibility(User $member, array $payload = []): void
    {
        $participations = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubId())
            ->where('user_id', $member->id)
            ->where('current_slot', 'current')
            ->orderBy('starts_at')
            ->get();

        $active = $participations->isNotEmpty();
        $primary = $participations->first();
        $seasonProfile = null;

        if ($primary) {
            $seasonProfile = SportsAthleteSeasonProfile::query()
                ->where('club_id', $this->clubId())
                ->where('user_id', $member->id)
                ->where('sports_modality_id', $primary->sports_modality_id)
                ->whereHas('season', function ($query): void {
                    $query->whereDate('data_inicio', '<=', today())
                        ->whereDate('data_fim', '>=', today());
                })
                ->orderByDesc('evaluated_at')
                ->first();

            $seasonProfile ??= SportsAthleteSeasonProfile::query()
                ->where('club_id', $this->clubId())
                ->where('user_id', $member->id)
                ->where('sports_modality_id', $primary->sports_modality_id)
                ->orderByDesc('evaluated_at')
                ->first();
        }

        $legacy = AthleteSportsData::query()->firstOrNew(['user_id' => $member->id]);
        $legacy->ativo = $active;
        $legacy->escalao_id = $seasonProfile?->official_age_group_id;
        $legacy->escalao_calculado_id = $seasonProfile?->calculated_age_group_id;
        $legacy->escalao_manual_override = $seasonProfile?->placement_source === 'override';

        foreach (['numero_pmb', 'data_inscricao'] as $field) {
            if (array_key_exists($field, $payload)) {
                $legacy->{$field} = $payload[$field];
            }
        }

        if (! $legacy->exists || $legacy->isDirty()) {
            $legacy->save();
        }

        $member->forceFill([
            'ativo_desportivo' => $active,
            'escalao' => $seasonProfile?->official_age_group_id
                ? [(string) $seasonProfile->official_age_group_id]
                : [],
        ])->saveQuietly();
    }

    private function legacyProfileOnly(User $user, array $payload): ?AthleteSportsData
    {
        $profile = AthleteSportsData::query()->where('user_id', $user->id)->first();
        if (! $profile) {
            return null;
        }

        if (array_key_exists('ativo_desportivo', $payload)) {
            $profile->forceFill(['ativo' => (bool) $payload['ativo_desportivo']])->save();
        }

        return $profile->fresh();
    }

    private function assertCanManageStructure(User $actor, string $message): void
    {
        if (! $this->accessControlService->canAccessPermission($actor, 'desportivo.estrutura', 'edit')) {
            throw ValidationException::withMessages(['sports' => $message]);
        }
    }

    private function assertSameClub(object $model): void
    {
        if ((string) ($model->club_id ?? '') !== $this->clubId()) {
            abort(404);
        }
    }

    private function clubId(): string
    {
        return $this->clubContext->id();
    }
}
