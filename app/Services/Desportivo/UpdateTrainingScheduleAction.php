<?php

namespace App\Services\Desportivo;

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
    ) {
    }

    /** @param array<string,mixed> $data */
    public function execute(Training $training, array $data, User $actor): Training
    {
        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão de treino pertence a outro clube.',
            ]);
        }

        if ($training->isCompleted()) {
            throw ValidationException::withMessages([
                'training' => 'Uma sessão concluída não pode ter o planeamento reescrito.',
            ]);
        }

        return DB::transaction(function () use ($training, $data, $actor): Training {
            $wasPublished = $training->session_status === 'published';

            if (array_key_exists('sports_venue_id', $data)) {
                $venueId = trim((string) ($data['sports_venue_id'] ?? ''));

                if ($venueId === '') {
                    $data['sports_venue_id'] = null;
                } else {
                    $venue = SportsVenue::query()
                        ->where('club_id', $this->clubContext->id())
                        ->where('active', true)
                        ->whereKey($venueId)
                        ->first();

                    if ($venue === null) {
                        throw ValidationException::withMessages([
                            'sports_venue_id' => 'O local selecionado não pertence ao clube ativo ou está inativo.',
                        ]);
                    }

                    $data['sports_venue_id'] = $venue->id;
                    if (!array_key_exists('local', $data)) {
                        $data['local'] = $venue->name;
                    }
                }
            }

            $training->fill(Arr::only($data, [
                'numero_treino',
                'data',
                'hora_inicio',
                'hora_fim',
                'local',
                'sports_venue_id',
                'epoca_id',
                'macrocycle_id',
                'mesociclo_id',
                'microciclo_id',
                'tipo_treino',
                'volume_planeado_m',
                'descricao_treino',
                'notas_gerais',
                'responsavel_id',
                'session_status',
                'instrucao',
            ]));

            if (!$wasPublished && ($data['session_status'] ?? null) === 'published') {
                $training->published_at = now();
            }

            $training->save();

            if (array_key_exists('escaloes', $data)) {
                $training->syncAgeGroupsWithPivot($data['escaloes'] ?? []);
                $this->prepareAthletesAction->updateForChangedEscaloes($training, $data['escaloes'] ?? []);
            }

            if (array_key_exists('training_groups', $data)) {
                $groups = $data['training_groups'] ?? [];
                $this->sessionGroupService->replace($training->fresh(), $groups, $actor);

                $groupIds = collect($groups)
                    ->pluck('training_group_id')
                    ->filter()
                    ->map('strval')
                    ->unique()
                    ->values()
                    ->all();

                $this->prepareAthletesAction->executeForGroups($training->fresh(), $groupIds);
            }

            $fresh = $training->fresh([
                'series',
                'sessionGroups.group',
                'sessionGroups.planVersion',
                'sessionGroups.lanes',
            ]);

            if (!$wasPublished && $fresh->session_status === 'published') {
                $this->assertPublishable($fresh);
            }

            $this->conflictService->apply($fresh);

            return $fresh->fresh([
                'ageGroups',
                'athleteRecords',
                'series',
                'venue',
                'recurrence',
                'sessionGroups.group',
                'sessionGroups.planVersion',
                'sessionGroups.lanes',
            ]);
        });
    }

    private function assertPublishable(Training $training): void
    {
        $globalContent = $training->training_plan_version_id !== null
            || filled($training->instrucao)
            || $training->series->isNotEmpty();

        if ($training->sessionGroups->isEmpty()) {
            if (!$globalContent) {
                throw ValidationException::withMessages([
                    'session_status' => 'Uma sessão publicada precisa de um plano, séries ou instrução.',
                ]);
            }

            return;
        }

        foreach ($training->sessionGroups as $assignment) {
            if ($globalContent
                || $assignment->training_plan_version_id !== null
                || filled($assignment->instruction)) {
                continue;
            }

            throw ValidationException::withMessages([
                'training_groups' => "O grupo {$assignment->group?->name} precisa de um plano ou instrução antes da publicação.",
            ]);
        }
    }
}
