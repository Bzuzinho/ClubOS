<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\Season;
use App\Models\SportsAthleteFederationAffiliation;
use App\Models\SportsAthleteLimitation;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsFederation;
use App\Models\SportsLimitationType;
use App\Models\SportsModality;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class SportsMemberProfileQueryService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberSportsIdentityProvider $memberIdentityProvider,
        private readonly AgeGroupPlacementService $ageGroupPlacementService,
    ) {
    }

    /** @return array<string,mixed> */
    public function forMember(User $member): array
    {
        $clubId = $this->clubContext->id();
        $identity = $this->memberIdentityProvider->forSports($member);
        $legacy = AthleteSportsData::query()->where('user_id', $member->id)->first();

        if (! Schema::hasTable('sports_athlete_participations')) {
            return $this->legacyOnlyPayload($member, $legacy, $identity);
        }

        $modalities = SportsModality::query()
            ->where('club_id', $clubId)
            ->whereNull('archived_at')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $participations = SportsAthleteParticipation::query()
            ->where('club_id', $clubId)
            ->where('user_id', $member->id)
            ->with('modality')
            ->orderByDesc('active')
            ->orderByDesc('starts_at')
            ->get();

        $seasonProfiles = SportsAthleteSeasonProfile::query()
            ->where('club_id', $clubId)
            ->where('user_id', $member->id)
            ->with(['season', 'calculatedAgeGroup', 'officialAgeGroup'])
            ->get()
            ->keyBy(fn (SportsAthleteSeasonProfile $profile): string => $profile->season_id.'|'.$profile->sports_modality_id);

        $memberships = TrainingGroupMembership::query()
            ->where('club_id', $clubId)
            ->where('user_id', $member->id)
            ->with(['group', 'seasonContext.season', 'seasonContext.program'])
            ->orderByDesc('starts_at')
            ->get();

        $affiliations = SportsAthleteFederationAffiliation::query()
            ->where('club_id', $clubId)
            ->where('user_id', $member->id)
            ->with('federation')
            ->orderByDesc('active')
            ->orderByDesc('starts_at')
            ->get();

        $limitations = SportsAthleteLimitation::query()
            ->where('club_id', $clubId)
            ->where('user_id', $member->id)
            ->with(['limitationType', 'modality'])
            ->orderByDesc('active')
            ->orderByDesc('starts_at')
            ->get();

        $modalityRows = $modalities->map(function (SportsModality $modality) use (
            $member,
            $identity,
            $participations,
            $seasonProfiles,
            $memberships,
            $affiliations
        ): array {
            $modalityParticipations = $participations
                ->where('sports_modality_id', $modality->id)
                ->values();
            $activeParticipation = $modalityParticipations->first(
                fn (SportsAthleteParticipation $row): bool => $row->current_slot === 'current'
            );

            $seasons = Season::query()
                ->where('club_id', $this->clubContext->id())
                ->where('sports_modality_id', $modality->id)
                ->orderByDesc('data_inicio')
                ->get()
                ->map(function (Season $season) use ($member, $identity, $seasonProfiles, $modality): array {
                    $profile = $seasonProfiles->get($season->id.'|'.$modality->id);
                    $placement = null;

                    if (! $profile && $identity['birth_date']) {
                        $placement = $this->ageGroupPlacementService->resolve(
                            $this->clubContext->id(),
                            (string) $member->id,
                            (string) $season->id,
                            (string) $modality->id,
                            $identity['birth_date'],
                            $identity['sex'],
                        );
                    }

                    $isCurrent = false;
                    if ($season->data_inicio && $season->data_fim) {
                        $isCurrent = today()->between($season->data_inicio, $season->data_fim, true);
                    } elseif ($season->status === 'active') {
                        $isCurrent = true;
                    }

                    return [
                        'id' => (string) $season->id,
                        'name' => $season->nome,
                        'status' => $season->status,
                        'starts_at' => optional($season->data_inicio)->toDateString(),
                        'ends_at' => optional($season->data_fim)->toDateString(),
                        'is_current' => $isCurrent,
                        'placement' => $this->placementPayload($profile, $placement),
                    ];
                });

            return [
                'id' => (string) $modality->id,
                'code' => $modality->code,
                'name' => $modality->name,
                'available' => (bool) $modality->active,
                'active' => $activeParticipation !== null,
                'active_participation_id' => $activeParticipation?->id,
                'starts_at' => optional($activeParticipation?->starts_at)->toDateString(),
                'history' => $modalityParticipations->map(fn (SportsAthleteParticipation $row): array => [
                    'id' => (string) $row->id,
                    'active' => (bool) $row->active,
                    'starts_at' => optional($row->starts_at)->toDateString(),
                    'ends_at' => optional($row->ends_at)->toDateString(),
                    'source' => $row->source,
                    'start_reason' => $row->start_reason,
                    'end_reason' => $row->end_reason,
                ])->all(),
                'seasons' => $seasons->all(),
                'groups' => $memberships
                    ->filter(fn (TrainingGroupMembership $membership): bool =>
                        (string) ($membership->group?->sports_modality_id ?? '') === (string) $modality->id
                    )
                    ->map(fn (TrainingGroupMembership $membership): array => [
                        'id' => (string) $membership->id,
                        'group_id' => (string) $membership->training_group_id,
                        'group_name' => $membership->group?->name,
                        'season_id' => $membership->seasonContext?->season_id,
                        'season_name' => $membership->seasonContext?->season?->nome,
                        'program_name' => $membership->seasonContext?->program?->name,
                        'is_primary' => (bool) $membership->is_primary,
                        'starts_at' => optional($membership->starts_at)->toDateString(),
                        'ends_at' => optional($membership->ends_at)->toDateString(),
                    ])->values()->all(),
                'federation_affiliations' => $affiliations
                    ->where('sports_modality_id', $modality->id)
                    ->map(fn (SportsAthleteFederationAffiliation $row): array => [
                        'id' => (string) $row->id,
                        'federation_id' => (string) $row->sports_federation_id,
                        'federation_name' => $row->federation?->name,
                        'membership_number' => $row->membership_number,
                        'license_number' => $row->license_number,
                        'active' => (bool) $row->active,
                        'starts_at' => optional($row->starts_at)->toDateString(),
                        'ends_at' => optional($row->ends_at)->toDateString(),
                    ])->values()->all(),
            ];
        })->values();

        return [
            'version' => 3,
            'canonical' => true,
            'member' => [
                'id' => (string) $member->id,
                'is_athlete' => (bool) $identity['is_athlete'],
                'member_state' => $identity['member_state'],
                'birth_date' => $identity['birth_date'],
                'sex' => $identity['sex'],
            ],
            'activity_active' => $participations->contains(
                fn (SportsAthleteParticipation $row): bool => $row->current_slot === 'current'
            ),
            'modalities' => $modalityRows->all(),
            'age_groups' => AgeGroup::query()
                ->where('club_id', $clubId)
                ->where('ativo', true)
                ->whereNull('archived_at')
                ->orderBy('idade_minima')
                ->orderBy('nome')
                ->get(['id', 'nome', 'code'])
                ->map(fn (AgeGroup $group): array => [
                    'id' => (string) $group->id,
                    'name' => $group->nome,
                    'code' => $group->code,
                ])->all(),
            'federations' => SportsFederation::query()
                ->where('club_id', $clubId)
                ->where('active', true)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (SportsFederation $federation): array => [
                    'id' => (string) $federation->id,
                    'name' => $federation->name,
                    'code' => $federation->code,
                ])->all(),
            'limitation_types' => SportsLimitationType::query()
                ->where('club_id', $clubId)
                ->where('ativo', true)
                ->whereNull('archived_at')
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get()
                ->map(fn (SportsLimitationType $type): array => [
                    'id' => (string) $type->id,
                    'name' => $type->nome,
                    'default_instruction' => $type->instrucao_padrao,
                    'allows_training' => (bool) $type->allows_training,
                    'allows_competition' => (bool) $type->allows_competition,
                    'requires_end_date' => (bool) $type->requires_end_date,
                ])->all(),
            'limitations' => $limitations->map(fn (SportsAthleteLimitation $row): array => [
                'id' => (string) $row->id,
                'type_id' => (string) $row->sports_limitation_type_id,
                'type_name' => $row->limitationType?->nome,
                'modality_id' => $row->sports_modality_id,
                'modality_name' => $row->modality?->name,
                'starts_at' => optional($row->starts_at)->toDateString(),
                'ends_at' => optional($row->ends_at)->toDateString(),
                'operational_instruction' => $row->operational_instruction,
                'allows_training' => (bool) $row->allows_training,
                'allows_competition' => (bool) $row->allows_competition,
                'active' => (bool) $row->active,
            ])->all(),
            'legacy_compatibility' => [
                'athlete_sports_data_id' => $legacy?->id,
                'numero_pmb' => $legacy?->numero_pmb,
                'num_federacao_unscoped' => $legacy?->num_federacao,
                'has_unscoped_federation_data' => (bool) ($legacy?->num_federacao || $legacy?->cartao_federacao),
                'medical_json_preserved_not_operational' => (bool) $legacy?->informacoes_medicas,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function placementPayload(?SportsAthleteSeasonProfile $profile, ?array $livePlacement): array
    {
        if ($profile) {
            return [
                'persisted' => true,
                'source' => $profile->placement_source,
                'calculated_age_group_id' => $profile->calculated_age_group_id,
                'calculated_age_group_name' => $profile->calculatedAgeGroup?->nome,
                'official_age_group_id' => $profile->official_age_group_id,
                'official_age_group_name' => $profile->officialAgeGroup?->nome,
                'rule_id' => $profile->season_age_group_rule_id,
                'override_id' => $profile->athlete_age_group_override_id,
                'reference_date' => optional($profile->reference_date)->toDateString(),
                'evaluated_at' => optional($profile->evaluated_at)->toIso8601String(),
            ];
        }

        $calculatedGroup = $livePlacement['calculated_age_group'] ?? null;
        $officialGroup = $livePlacement['age_group'] ?? null;
        $calculatedId = $calculatedGroup?->id;
        $calculatedName = $calculatedGroup?->nome;
        if ($calculatedId === null && ($livePlacement['source'] ?? null) === 'rule') {
            $calculatedId = $officialGroup?->id;
            $calculatedName = $officialGroup?->nome;
        }

        return [
            'persisted' => false,
            'source' => $livePlacement['source'] ?? null,
            'calculated_age_group_id' => $calculatedId,
            'calculated_age_group_name' => $calculatedName,
            'official_age_group_id' => $officialGroup?->id,
            'official_age_group_name' => $officialGroup?->nome,
            'rule_id' => $livePlacement['rule_id'] ?? null,
            'override_id' => $livePlacement['override_id'] ?? null,
            'reference_date' => $livePlacement['reference_date'] ?? null,
            'evaluated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function legacyOnlyPayload(User $member, ?AthleteSportsData $legacy, array $identity): array
    {
        return [
            'version' => 3,
            'canonical' => false,
            'member' => [
                'id' => (string) $member->id,
                'is_athlete' => (bool) $identity['is_athlete'],
                'member_state' => $identity['member_state'],
                'birth_date' => $identity['birth_date'],
                'sex' => $identity['sex'],
            ],
            'activity_active' => (bool) ($legacy?->ativo ?? $member->ativo_desportivo),
            'modalities' => [],
            'age_groups' => [],
            'federations' => [],
            'limitation_types' => [],
            'limitations' => [],
            'legacy_compatibility' => [
                'athlete_sports_data_id' => $legacy?->id,
                'numero_pmb' => $legacy?->numero_pmb,
                'num_federacao_unscoped' => $legacy?->num_federacao,
                'has_unscoped_federation_data' => (bool) ($legacy?->num_federacao || $legacy?->cartao_federacao),
                'medical_json_preserved_not_operational' => (bool) $legacy?->informacoes_medicas,
            ],
        ];
    }
}
