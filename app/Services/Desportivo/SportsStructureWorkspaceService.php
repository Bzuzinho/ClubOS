<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\Season;
use App\Models\SportsCoachRole;
use App\Models\SportsModality;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsVenue;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupCoach;
use App\Models\TrainingGroupMembership;
use App\Models\TrainingGroupSeason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsStructureWorkspaceService
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    public function clubId(): string
    {
        return $this->clubContext->id();
    }

    public function createSeason(array $data, ?string $actorId): Season
    {
        $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
        $status = $data['status'] ?? 'planned';

        return Season::query()->create([
            'club_id' => $this->clubId(),
            'sports_modality_id' => $modality->id,
            'nome' => $data['nome'],
            'ano_temporada' => $data['ano_temporada'],
            'data_inicio' => $data['data_inicio'],
            'data_fim' => $data['data_fim'],
            'tipo' => $data['tipo'] ?? 'Principal',
            'estado' => $this->legacySeasonState($status),
            'status' => $status,
            'descricao' => $data['descricao'] ?? null,
        ]);
    }

    public function updateSeason(Season $season, array $data, ?string $actorId): Season
    {
        $this->assertTenant($season);

        if ($season->status === 'closed' && array_intersect(array_keys($data), ['sports_modality_id', 'data_inicio', 'data_fim', 'status'])) {
            throw ValidationException::withMessages([
                'season' => 'Reabra a época antes de alterar modalidade, datas ou estado.',
            ]);
        }

        if (! empty($data['sports_modality_id'])) {
            $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
            if ((string) $modality->id !== (string) $season->sports_modality_id
                && ($season->trainings()->exists() || $season->programs()->exists() || $season->groupConfigurations()->exists())) {
                throw ValidationException::withMessages([
                    'sports_modality_id' => 'A modalidade de uma época já utilizada não pode ser alterada.',
                ]);
            }
        }

        if (isset($data['status'])) {
            $data['estado'] = $this->legacySeasonState($data['status']);
        }

        $season->fill($data)->save();

        return $season->refresh();
    }

    public function retireSeason(Season $season, ?string $actorId): void
    {
        $this->assertTenant($season);

        $used = $season->trainings()->exists()
            || $season->programs()->exists()
            || $season->ageGroupRules()->exists()
            || $season->groupConfigurations()->exists();

        if (! $used) {
            $season->delete();
            return;
        }

        $season->forceFill([
            'status' => 'archived',
            'estado' => 'Arquivada',
        ])->save();
    }

    public function createAgeGroup(array $data): AgeGroup
    {
        return AgeGroup::query()->create([
            ...$data,
            'club_id' => $this->clubId(),
            'ativo' => $data['ativo'] ?? true,
        ]);
    }

    public function updateAgeGroup(AgeGroup $ageGroup, array $data): AgeGroup
    {
        $this->assertTenant($ageGroup);
        $ageGroup->fill($data)->save();

        return $ageGroup->refresh();
    }

    public function retireAgeGroup(AgeGroup $ageGroup): void
    {
        $this->assertTenant($ageGroup);

        $used = $ageGroup->provas()->exists()
            || $ageGroup->athleteSportsData()->exists()
            || $ageGroup->seasonRules()->exists()
            || DB::table('training_group_age_groups')->where('age_group_id', $ageGroup->id)->exists();

        if (! $used) {
            $ageGroup->delete();
            return;
        }

        $ageGroup->forceFill(['ativo' => false, 'archived_at' => now()])->save();
    }

    public function createGroup(array $data, ?string $actorId): TrainingGroup
    {
        $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);

        return DB::transaction(function () use ($data, $actorId, $modality): TrainingGroup {
            $group = TrainingGroup::query()->create([
                'club_id' => $this->clubId(),
                'sports_modality_id' => $modality->id,
                'modality' => $modality->code,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'] ?? true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->syncGroupAgeGroups($group, $data['age_group_ids'] ?? []);

            return $group->refresh();
        }, 3);
    }

    public function updateGroup(TrainingGroup $group, array $data, ?string $actorId): TrainingGroup
    {
        $this->assertTenant($group);

        return DB::transaction(function () use ($group, $data, $actorId): TrainingGroup {
            if (! empty($data['sports_modality_id'])
                && (string) $data['sports_modality_id'] !== (string) $group->sports_modality_id
                && ($group->memberships()->exists() || $group->seasonConfigurations()->exists() || $group->coaches()->exists())) {
                throw ValidationException::withMessages([
                    'sports_modality_id' => 'A modalidade de um grupo já utilizado não pode ser alterada.',
                ]);
            }

            if (! empty($data['sports_modality_id'])) {
                $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
                $data['modality'] = $modality->code;
            }

            $ageGroupIds = $data['age_group_ids'] ?? null;
            unset($data['age_group_ids']);
            $group->fill($data)->forceFill(['updated_by' => $actorId])->save();

            if (is_array($ageGroupIds)) {
                $this->syncGroupAgeGroups($group, $ageGroupIds);
            }

            return $group->refresh();
        }, 3);
    }

    public function retireGroup(TrainingGroup $group, ?string $actorId): void
    {
        $this->assertTenant($group);

        $used = $group->memberships()->exists()
            || $group->coaches()->exists()
            || $group->seasonConfigurations()->exists()
            || $group->sessionAssignments()->exists()
            || $group->recurrenceAssignments()->exists();

        if (! $used) {
            $group->delete();
            return;
        }

        $group->forceFill([
            'active' => false,
            'archived_at' => now(),
            'updated_by' => $actorId,
        ])->save();
    }

    public function updateMembership(TrainingGroupMembership $membership, array $data): TrainingGroupMembership
    {
        $this->assertTenant($membership);
        $context = TrainingGroupSeason::query()
            ->where('club_id', $this->clubId())
            ->with(['group', 'season'])
            ->findOrFail($data['training_group_season_id'] ?? $membership->training_group_season_id);

        $startsAt = $data['starts_at'] ?? optional($membership->starts_at)->toDateString();
        $endsAt = array_key_exists('ends_at', $data) ? $data['ends_at'] : optional($membership->ends_at)->toDateString();

        if ($startsAt < $context->season->data_inicio->toDateString()
            || ($endsAt && $endsAt > $context->season->data_fim->toDateString())) {
            throw ValidationException::withMessages(['starts_at' => 'A associação tem de ficar dentro das datas da época.']);
        }

        $primary = (bool) ($data['is_primary'] ?? $membership->is_primary);
        if ($primary) {
            $conflict = TrainingGroupMembership::query()
                ->where('club_id', $this->clubId())
                ->where('user_id', $membership->user_id)
                ->where('id', '!=', $membership->id)
                ->where('is_primary', true)
                ->whereHas('seasonContext', fn ($q) => $q
                    ->where('season_id', $context->season_id)
                    ->whereHas('group', fn ($g) => $g->where('sports_modality_id', $context->group->sports_modality_id)))
                ->whereDate('starts_at', '<=', $endsAt ?: '9999-12-31')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $startsAt))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['is_primary' => 'O atleta já possui outro grupo principal sobreposto nesta modalidade e época.']);
            }
        }

        $membership->fill([
            'training_group_id' => $context->training_group_id,
            'training_group_season_id' => $context->id,
            'is_primary' => $primary,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt ?: null,
            'notes' => $data['notes'] ?? $membership->notes,
        ])->save();

        return $membership->refresh();
    }

    public function endMembership(TrainingGroupMembership $membership, string $endedAt): void
    {
        $this->assertTenant($membership);
        if ($endedAt < $membership->starts_at->toDateString()) {
            throw ValidationException::withMessages(['ends_at' => 'A data de fim não pode ser anterior ao início.']);
        }

        $membership->forceFill(['ends_at' => $endedAt])->save();
    }

    public function updateCoachRole(SportsCoachRole $role, array $data, ?string $actorId): SportsCoachRole
    {
        $this->assertTenant($role);
        $role->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $role->refresh();
    }

    public function retireCoachRole(SportsCoachRole $role, ?string $actorId): void
    {
        $this->assertTenant($role);

        if (TrainingGroupCoach::query()->where('club_id', $this->clubId())->where('sports_coach_role_id', $role->id)->exists()) {
            $role->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actorId])->save();
            return;
        }

        $role->delete();
    }

    public function assignCoach(array $data, ?string $actorId): TrainingGroupCoach
    {
        $context = TrainingGroupSeason::query()
            ->where('club_id', $this->clubId())
            ->with('group')
            ->findOrFail($data['training_group_season_id']);
        $role = SportsCoachRole::forClub($this->clubId())->findOrFail($data['sports_coach_role_id']);

        return TrainingGroupCoach::query()->create([
            'club_id' => $this->clubId(),
            'training_group_id' => $context->training_group_id,
            'training_group_season_id' => $context->id,
            'user_id' => $data['user_id'],
            'sports_coach_role_id' => $role->id,
            'role' => $role->code,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    public function updateCoach(TrainingGroupCoach $coach, array $data): TrainingGroupCoach
    {
        $this->assertTenant($coach);

        if (! empty($data['training_group_season_id'])) {
            $context = TrainingGroupSeason::query()->where('club_id', $this->clubId())->findOrFail($data['training_group_season_id']);
            $data['training_group_id'] = $context->training_group_id;
        }

        if (! empty($data['sports_coach_role_id'])) {
            $role = SportsCoachRole::forClub($this->clubId())->findOrFail($data['sports_coach_role_id']);
            $data['role'] = $role->code;
        }

        $coach->fill($data)->save();

        return $coach->refresh();
    }

    public function endCoach(TrainingGroupCoach $coach, string $endedAt): void
    {
        $this->assertTenant($coach);
        if ($endedAt < $coach->starts_at->toDateString()) {
            throw ValidationException::withMessages(['ends_at' => 'A data de fim não pode ser anterior ao início.']);
        }

        $coach->forceFill(['ends_at' => $endedAt])->save();
    }

    public function createVenue(array $data, ?string $actorId): SportsVenue
    {
        return SportsVenue::query()->create([
            ...$data,
            'club_id' => $this->clubId(),
            'active' => $data['active'] ?? true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function updateVenue(SportsVenue $venue, array $data, ?string $actorId): SportsVenue
    {
        $this->assertTenant($venue);
        $venue->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $venue->refresh();
    }

    public function retireVenue(SportsVenue $venue, ?string $actorId): void
    {
        $this->assertTenant($venue);
        if (! $venue->pools()->exists() && ! $venue->trainings()->exists() && ! $venue->recurrences()->exists()) {
            $venue->delete();
            return;
        }

        $venue->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actorId])->save();
    }

    public function updatePool(SportsPool $pool, array $data, ?string $actorId): SportsPool
    {
        $this->assertTenant($pool);
        if (! empty($data['sports_venue_id'])) {
            SportsVenue::forClub($this->clubId())->findOrFail($data['sports_venue_id']);
        }
        $pool->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $pool->refresh();
    }

    public function retirePool(SportsPool $pool, ?string $actorId): void
    {
        $this->assertTenant($pool);
        $pool->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actorId])->save();
        $pool->lanes()->update(['active' => false, 'updated_at' => now()]);
    }

    public function updateLane(SportsPoolLane $lane, array $data, ?string $actorId): SportsPoolLane
    {
        $this->assertTenant($lane);
        $lane->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $lane->refresh();
    }

    public function retireLane(SportsPoolLane $lane, ?string $actorId): void
    {
        $this->assertTenant($lane);
        $lane->forceFill(['active' => false, 'updated_by' => $actorId])->save();
    }

    private function syncGroupAgeGroups(TrainingGroup $group, array $ids): void
    {
        $valid = AgeGroup::query()
            ->where('club_id', $this->clubId())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        $group->ageGroups()->syncWithPivotValues($valid, ['club_id' => $this->clubId()]);
    }

    private function legacySeasonState(string $status): string
    {
        return match ($status) {
            'active' => 'Em curso',
            'closed' => 'Concluída',
            'archived' => 'Arquivada',
            default => 'Planeada',
        };
    }

    private function assertTenant(Model $model): void
    {
        abort_unless((string) $model->getAttribute('club_id') === $this->clubId(), 404);
    }
}
