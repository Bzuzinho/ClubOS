<?php

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\AthleteAgeGroupOverride;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use App\Models\SeasonProgram;
use App\Models\SportsCoachRole;
use App\Models\SportsModality;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsProgram;
use App\Models\SportsSeasonLifecycleEvent;
use App\Models\SportsVenue;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\TrainingGroupSeason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SportsStructureService
{
    public function clubId(): string
    {
        return (string) config('clubos.sports.club_id', 'bscn');
    }

    public function createModality(array $data, ?string $actorId = null): SportsModality
    {
        return SportsModality::create([
            'club_id' => $this->clubId(),
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'active' => $data['active'] ?? true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function updateModality(SportsModality $modality, array $data, ?string $actorId = null): SportsModality
    {
        $this->assertTenant($modality);

        if (isset($data['code']) && $data['code'] !== $modality->code && $this->modalityIsUsed($modality)) {
            throw ValidationException::withMessages([
                'code' => 'O código técnico de uma modalidade utilizada é imutável.',
            ]);
        }

        $modality->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $modality->refresh();
    }

    public function retireModality(SportsModality $modality, ?string $actorId = null): void
    {
        $this->assertTenant($modality);

        if ($this->modalityIsUsed($modality)) {
            $modality->forceFill([
                'active' => false,
                'archived_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            return;
        }

        $modality->delete();
    }

    public function createProgram(array $data, ?string $actorId = null): SportsProgram
    {
        $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);

        return SportsProgram::create([
            'club_id' => $this->clubId(),
            'sports_modality_id' => $modality->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'active' => $data['active'] ?? true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function updateProgram(SportsProgram $program, array $data, ?string $actorId = null): SportsProgram
    {
        $this->assertTenant($program);

        if (isset($data['code']) && $data['code'] !== $program->code && $program->seasonPrograms()->exists()) {
            throw ValidationException::withMessages([
                'code' => 'O código técnico de um programa utilizado é imutável.',
            ]);
        }

        $program->fill($data)->forceFill(['updated_by' => $actorId])->save();

        return $program->refresh();
    }

    public function retireProgram(SportsProgram $program, ?string $actorId = null): void
    {
        $this->assertTenant($program);

        if ($program->seasonPrograms()->exists()
            || TrainingGroupSeason::query()->where('club_id', $this->clubId())->where('sports_program_id', $program->id)->exists()) {
            $program->forceFill([
                'active' => false,
                'archived_at' => now(),
                'updated_by' => $actorId,
            ])->save();

            return;
        }

        $program->delete();
    }

    public function syncSeasonProgram(array $data, ?string $actorId = null): SeasonProgram
    {
        $season = $this->season($data['season_id']);
        $program = SportsProgram::forClub($this->clubId())->findOrFail($data['sports_program_id']);

        if ((string) $season->sports_modality_id !== (string) $program->sports_modality_id) {
            throw ValidationException::withMessages([
                'sports_program_id' => 'O programa não pertence à modalidade da época.',
            ]);
        }

        $relation = SeasonProgram::query()->firstOrNew([
            'season_id' => $season->id,
            'sports_program_id' => $program->id,
        ]);
        $relation->fill([
            'club_id' => $this->clubId(),
            'active' => $data['active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $actorId,
        ]);
        if (! $relation->exists) {
            $relation->created_by = $actorId;
        }
        $relation->save();

        return $relation->refresh();
    }

    public function createAgeGroupRule(array $data, ?string $actorId = null): SeasonAgeGroupRule
    {
        $season = $this->season($data['season_id']);
        $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
        $ageGroup = AgeGroup::query()->where('club_id', $this->clubId())->findOrFail($data['age_group_id']);

        if ((string) $season->sports_modality_id !== (string) $modality->id) {
            throw ValidationException::withMessages([
                'sports_modality_id' => 'A modalidade da regra tem de coincidir com a modalidade da época.',
            ]);
        }

        return SeasonAgeGroupRule::create([
            ...$data,
            'club_id' => $this->clubId(),
            'age_group_id' => $ageGroup->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function retireAgeGroupRule(SeasonAgeGroupRule $rule, ?string $actorId = null): void
    {
        $this->assertTenant($rule);
        $rule->forceFill(['active' => false, 'updated_by' => $actorId])->save();
    }

    public function syncGroupSeason(array $data, ?string $actorId = null): TrainingGroupSeason
    {
        $group = TrainingGroup::forClub($this->clubId())->findOrFail($data['training_group_id']);
        $season = $this->season($data['season_id']);
        $program = null;

        if ((string) $group->sports_modality_id !== (string) $season->sports_modality_id) {
            throw ValidationException::withMessages([
                'training_group_id' => 'O grupo e a época têm de pertencer à mesma modalidade.',
            ]);
        }

        if (! empty($data['sports_program_id'])) {
            $program = SportsProgram::forClub($this->clubId())->findOrFail($data['sports_program_id']);
            if ((string) $program->sports_modality_id !== (string) $season->sports_modality_id) {
                throw ValidationException::withMessages([
                    'sports_program_id' => 'O programa não pertence à modalidade da época.',
                ]);
            }
            if (! SeasonProgram::query()
                ->where('club_id', $this->clubId())
                ->where('season_id', $season->id)
                ->where('sports_program_id', $program->id)
                ->where('active', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'sports_program_id' => 'O programa tem de estar ativo nesta época antes de poder ser usado por um grupo.',
                ]);
            }
        }

        $context = TrainingGroupSeason::query()->firstOrNew([
            'training_group_id' => $group->id,
            'season_id' => $season->id,
        ]);
        $context->fill([
            'club_id' => $this->clubId(),
            'sports_program_id' => $program?->id,
            'active' => $data['active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $actorId,
        ]);
        if (! $context->exists) {
            $context->created_by = $actorId;
        }
        $context->save();

        return $context->refresh();
    }

    public function createCoachRole(array $data, ?string $actorId = null): SportsCoachRole
    {
        return SportsCoachRole::create([
            ...$data,
            'club_id' => $this->clubId(),
            'active' => true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function createPool(array $data, ?string $actorId = null): SportsPool
    {
        SportsVenue::forClub($this->clubId())->findOrFail($data['sports_venue_id']);

        return SportsPool::create([
            ...$data,
            'club_id' => $this->clubId(),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function addLane(SportsPool $pool, array $data, ?string $actorId = null): SportsPoolLane
    {
        $this->assertTenant($pool);

        return $pool->lanes()->create([
            ...$data,
            'club_id' => $this->clubId(),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    public function closeSeason(Season $season, ?string $actorId = null): Season
    {
        $this->assertTenant($season);

        return DB::transaction(function () use ($season, $actorId) {
            $locked = Season::query()
                ->where('club_id', $this->clubId())
                ->lockForUpdate()
                ->findOrFail($season->id);

            if ($locked->status === 'closed') {
                return $locked;
            }

            $fromStatus = $locked->status;
            $occurredAt = now();
            $locked->forceFill([
                'status' => 'closed',
                'estado' => 'Concluída',
                'closed_at' => $occurredAt,
                'closed_by' => $actorId,
            ])->save();

            SportsSeasonLifecycleEvent::create([
                'club_id' => $this->clubId(),
                'season_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => 'closed',
                'reason' => null,
                'actor_id' => $actorId,
                'occurred_at' => $occurredAt,
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function reopenSeason(Season $season, string $reason, ?string $actorId = null): Season
    {
        $this->assertTenant($season);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reabertura de uma época exige um motivo.',
            ]);
        }

        return DB::transaction(function () use ($season, $reason, $actorId) {
            $locked = Season::query()
                ->where('club_id', $this->clubId())
                ->lockForUpdate()
                ->findOrFail($season->id);

            if ($locked->status !== 'closed') {
                throw ValidationException::withMessages([
                    'reason' => 'Apenas épocas encerradas podem ser reabertas.',
                ]);
            }

            $fromStatus = $locked->status;
            $occurredAt = now();
            $locked->forceFill([
                'status' => 'active',
                'estado' => 'Em curso',
                'reopened_at' => $occurredAt,
                'reopened_by' => $actorId,
                'reopen_reason' => $reason,
            ])->save();

            SportsSeasonLifecycleEvent::create([
                'club_id' => $this->clubId(),
                'season_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => 'active',
                'reason' => $reason,
                'actor_id' => $actorId,
                'occurred_at' => $occurredAt,
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function createAgeGroupOverride(array $data, ?string $actorId = null): AthleteAgeGroupOverride
    {
        return DB::transaction(function () use ($data, $actorId) {
            $season = $this->season($data['season_id']);
            SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
            AgeGroup::query()->where('club_id', $this->clubId())->findOrFail($data['age_group_id']);

            if ((string) $season->sports_modality_id !== (string) $data['sports_modality_id']) {
                throw ValidationException::withMessages([
                    'sports_modality_id' => 'O override tem de usar a modalidade da época.',
                ]);
            }

            if (! DB::table('users')->where('id', $data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Atleta inválido.']);
            }

            AthleteAgeGroupOverride::query()
                ->where('club_id', $this->clubId())
                ->where('user_id', $data['user_id'])
                ->where('season_id', $season->id)
                ->where('sports_modality_id', $data['sports_modality_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->get()
                ->each(function (AthleteAgeGroupOverride $override) use ($actorId): void {
                    $override->forceFill([
                        'active' => false,
                        'ended_at' => now(),
                        'ended_by' => $actorId,
                    ])->save();
                });

            return AthleteAgeGroupOverride::create([
                'club_id' => $this->clubId(),
                'user_id' => $data['user_id'],
                'season_id' => $season->id,
                'sports_modality_id' => $data['sports_modality_id'],
                'age_group_id' => $data['age_group_id'],
                'reason' => trim($data['reason']),
                'active' => true,
                'effective_at' => $data['effective_at'] ?? now(),
                'created_by' => $actorId,
            ]);
        }, 3);
    }

    public function endAgeGroupOverride(AthleteAgeGroupOverride $override, ?string $actorId = null): void
    {
        $this->assertTenant($override);

        if (! $override->active) {
            return;
        }

        $override->forceFill([
            'active' => false,
            'ended_at' => now(),
            'ended_by' => $actorId,
        ])->save();
    }

    public function assignMembershipWithSeasonContext(array $data, ?string $actorId = null): TrainingGroupMembership
    {
        return DB::transaction(function () use ($data, $actorId) {
            $context = TrainingGroupSeason::query()
                ->where('club_id', $this->clubId())
                ->with(['group', 'season'])
                ->lockForUpdate()
                ->findOrFail($data['training_group_season_id']);

            if (! DB::table('users')->where('id', $data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Atleta inválido.']);
            }

            $startsAt = $data['starts_at'];
            $endsAt = $data['ends_at'] ?? null;

            if ($endsAt && $endsAt < $startsAt) {
                throw ValidationException::withMessages([
                    'ends_at' => 'A data de fim não pode ser anterior à data de início.',
                ]);
            }

            if ($startsAt < $context->season->data_inicio->toDateString()
                || ($endsAt && $endsAt > $context->season->data_fim->toDateString())) {
                throw ValidationException::withMessages([
                    'starts_at' => 'A associação ao grupo tem de ficar dentro das datas da época.',
                ]);
            }

            if ((bool) ($data['is_primary'] ?? true)) {
                $conflict = TrainingGroupMembership::query()
                    ->where('club_id', $this->clubId())
                    ->where('user_id', $data['user_id'])
                    ->where('is_primary', true)
                    ->whereNotNull('training_group_season_id')
                    ->whereHas('seasonContext', function ($query) use ($context): void {
                        $query
                            ->where('season_id', $context->season_id)
                            ->whereHas('group', fn ($group) => $group->where('sports_modality_id', $context->group->sports_modality_id));
                    })
                    ->whereDate('starts_at', '<=', $endsAt ?: '9999-12-31')
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $startsAt))
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'is_primary' => 'O atleta já possui um grupo principal sobreposto nesta modalidade e época.',
                    ]);
                }
            }

            return TrainingGroupMembership::create([
                'club_id' => $this->clubId(),
                'training_group_id' => $context->training_group_id,
                'training_group_season_id' => $context->id,
                'user_id' => $data['user_id'],
                'is_primary' => $data['is_primary'] ?? true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);
        }, 3);
    }

    private function season(string $seasonId): Season
    {
        return Season::query()->where('club_id', $this->clubId())->findOrFail($seasonId);
    }

    private function modalityIsUsed(SportsModality $modality): bool
    {
        return $modality->programs()->exists()
            || DB::table('seasons')->where('club_id', $this->clubId())->where('sports_modality_id', $modality->id)->exists()
            || DB::table('training_groups')->where('club_id', $this->clubId())->where('sports_modality_id', $modality->id)->exists();
    }

    private function assertTenant(Model $model): void
    {
        if ((string) $model->getAttribute('club_id') !== $this->clubId()) {
            abort(404);
        }
    }
}
