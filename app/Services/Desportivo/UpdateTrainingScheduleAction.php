<?php

namespace App\Services\Desportivo;

use App\Models\SportsPool;
use App\Models\SportsVenue;
use App\Models\Training;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateTrainingScheduleAction
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly TrainingSessionGroupService $sessionGroupService,
        private readonly TrainingScheduleConflictService $conflictService,
        private readonly PrepareTrainingAthletesAction $prepareAthletesAction,
    ) {}

    public function execute(Training $training, array $data, User $actor): Training
    {
        if ((string) $training->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['training' => 'A sessão de treino pertence a outro clube.']);
        if ($training->isCompleted()) throw ValidationException::withMessages(['training' => 'Uma sessão concluída não pode ter o planeamento reescrito.']);
        if ($training->isCancelled()) throw ValidationException::withMessages(['training' => 'Uma sessão cancelada não pode ter o planeamento reescrito.']);

        return DB::transaction(function () use ($training, $data, $actor): Training {
            $wasPublished = $training->session_status === 'published';
            $venue = null; $pool = null;

            if (array_key_exists('sports_pool_id', $data)) {
                $poolId = trim((string) ($data['sports_pool_id'] ?? ''));
                if ($poolId === '') $data['sports_pool_id'] = null;
                else {
                    $pool = SportsPool::query()->with('venue')->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($poolId)->first();
                    if (! $pool || ! $pool->venue?->active) throw ValidationException::withMessages(['sports_pool_id' => 'A piscina/área selecionada não pertence ao clube ativo ou está inativa.']);
                    $data['sports_pool_id'] = $pool->id; $data['sports_venue_id'] = $pool->sports_venue_id;
                    if (! array_key_exists('local', $data)) $data['local'] = $pool->venue->name;
                }
            }

            if (array_key_exists('sports_venue_id', $data) && ! $pool) {
                $venueId = trim((string) ($data['sports_venue_id'] ?? ''));
                if ($venueId === '') $data['sports_venue_id'] = null;
                else {
                    $venue = SportsVenue::query()->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($venueId)->first();
                    if (! $venue) throw ValidationException::withMessages(['sports_venue_id' => 'O local selecionado não pertence ao clube ativo ou está inativo.']);
                    $data['sports_venue_id'] = $venue->id;
                    if (! array_key_exists('local', $data)) $data['local'] = $venue->name;
                }
            }

            $training->fill(Arr::only($data, ['numero_treino','data','hora_inicio','hora_fim','local','sports_venue_id','sports_pool_id','epoca_id','macrocycle_id','mesociclo_id','microciclo_id','tipo_treino','volume_planeado_m','descricao_treino','notas_gerais','responsavel_id','session_status','instrucao']));
            if (! $wasPublished && ($data['session_status'] ?? null) === 'published') $training->published_at = now();
            $training->save();

            if (array_key_exists('escaloes', $data)) {
                $training->syncAgeGroupsWithPivot($data['escaloes'] ?? []);
                $this->prepareAthletesAction->updateForChangedEscaloes($training, $data['escaloes'] ?? []);
            }
            if (array_key_exists('training_groups', $data)) {
                $groups = $data['training_groups'] ?? [];
                $this->sessionGroupService->replace($training->fresh(), $groups, $actor);
                $groupIds = collect($groups)->pluck('training_group_id')->filter()->map('strval')->unique()->values()->all();
                $this->prepareAthletesAction->executeForGroups($training->fresh(), $groupIds);
            }
            if (array_key_exists('athlete_ids', $data)) $this->prepareAthletesAction->executeForUsers($training->fresh(), $data['athlete_ids'] ?? [], ['source' => 'planning_manual']);

            $fresh = $training->fresh(['series','sessionGroups.group','sessionGroups.planVersion','sessionGroups.lanes.pool']);
            if (! $wasPublished && $fresh->session_status === 'published') $this->assertPublishable($fresh);
            $this->conflictService->apply($fresh);
            return $fresh->fresh(['ageGroups','athleteRecords','series','venue','pool','recurrence','season','macrocycle','mesocycle','microcycle','sessionGroups.group','sessionGroups.planVersion','sessionGroups.lanes.pool']);
        });
    }

    private function assertPublishable(Training $training): void
    {
        $global = $training->training_plan_version_id !== null || filled($training->instrucao) || $training->series->isNotEmpty();
        if ($training->sessionGroups->isEmpty()) {
            if (! $global) throw ValidationException::withMessages(['session_status' => 'Uma sessão publicada precisa de um plano, séries ou instrução.']);
            return;
        }
        foreach ($training->sessionGroups as $assignment) {
            if ($global || $assignment->training_plan_version_id !== null || filled($assignment->instruction)) continue;
            throw ValidationException::withMessages(['training_groups' => "O grupo {$assignment->group?->name} precisa de um plano ou instrução antes da publicação."]);
        }
    }
}
