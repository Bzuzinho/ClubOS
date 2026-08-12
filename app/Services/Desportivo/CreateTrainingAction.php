<?php

namespace App\Services\Desportivo;

use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\Season;
use App\Models\SportsPool;
use App\Models\SportsVenue;
use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingRecurrence;
use App\Models\TrainingSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreateTrainingAction
{
    public function __construct(
        private PrepareTrainingAthletesAction $prepareAthletesAction,
        private SportsClubContext $clubContext,
        private TrainingSessionPlanService $sessionPlanService,
        private TrainingSessionGroupService $sessionGroupService,
        private TrainingScheduleConflictService $conflictService,
    ) {}

    public function execute(array $data, User $criadoPor): Training
    {
        $planVersion = ! empty($data['training_plan_version_id']) ? TrainingPlanVersion::query()->findOrFail($data['training_plan_version_id']) : null;
        $this->validate($data, $planVersion);

        DB::beginTransaction();
        try {
            $training = $this->createTraining($data, $criadoPor, $planVersion);

            if (Schema::hasTable('training_age_group') && ! empty($data['escaloes'])) $training->syncAgeGroupsWithPivot($data['escaloes']);

            if ($planVersion !== null) {
                $training = $this->sessionPlanService->assign($training, $planVersion, $criadoPor);
            } else {
                $this->createSeriesRows($training, $data['series_linhas'] ?? []);
                $training = $training->fresh();
            }

            $groupAssignments = $data['training_groups'] ?? [];
            $manualAthletes = collect($data['athlete_ids'] ?? [])->filter()->map('strval')->unique()->values()->all();

            if ($groupAssignments !== []) {
                $this->sessionGroupService->replace($training, $groupAssignments, $criadoPor);
                $groupIds = collect($groupAssignments)->pluck('training_group_id')->filter()->map('strval')->unique()->values()->all();
                $this->prepareAthletesAction->executeForGroups($training->fresh(), $groupIds);
            } elseif (! empty($data['escaloes']) || $manualAthletes === []) {
                $this->prepareAthletesAction->execute($training, $data['escaloes'] ?? []);
            }

            if ($manualAthletes !== []) {
                $this->prepareAthletesAction->executeForUsers($training->fresh(), $manualAthletes, ['source' => 'planning_manual']);
            }

            $this->conflictService->apply($training->fresh());
            DB::commit();

            Log::info('Training session created successfully', [
                'training_id' => $training->id,
                'training_plan_version_id' => $planVersion?->id,
                'training_recurrence_id' => $training->training_recurrence_id,
                'created_by' => $criadoPor->id,
            ]);

            $relations = [
                'athleteRecords', 'series', 'planVersion', 'venue', 'pool', 'recurrence',
                'season', 'macrocycle', 'mesocycle', 'microcycle',
                'sessionGroups.group', 'sessionGroups.planVersion', 'sessionGroups.lanes.pool',
            ];
            if (Schema::hasTable('training_age_group')) $relations[] = 'ageGroups';
            return $training->fresh($relations);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create training session', ['error' => $e->getMessage(), 'data' => $data]);
            throw $e;
        }
    }

    private function validate(array $data, ?TrainingPlanVersion $planVersion): void
    {
        $rules = [
            'data' => 'nullable|date', 'hora_inicio' => 'nullable|date_format:H:i', 'hora_fim' => 'nullable|date_format:H:i|after:hora_inicio',
            'local' => 'nullable|string|max:255', 'sports_venue_id' => 'nullable|uuid|exists:sports_venues,id',
            'sports_pool_id' => 'nullable|uuid|exists:sports_pools,id', 'training_recurrence_id' => 'nullable|uuid|exists:training_recurrences,id',
            'recurrence_occurrence_key' => 'nullable|string|max:64', 'epoca_id' => 'nullable|uuid|exists:seasons,id',
            'macrocycle_id' => 'nullable|uuid|exists:macrocycles,id', 'mesociclo_id' => 'nullable|uuid|exists:mesocycles,id',
            'microciclo_id' => 'nullable|uuid|exists:microcycles,id', 'tipo_treino' => 'nullable|string|max:100',
            'volume_planeado_m' => 'nullable|integer|min:0', 'escaloes' => 'nullable|array', 'escaloes.*' => 'uuid|exists:age_groups,id',
            'athlete_ids' => 'nullable|array', 'athlete_ids.*' => 'uuid|exists:users,id',
            'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id', 'responsavel_id' => 'nullable|uuid|exists:users,id',
            'session_status' => 'nullable|in:draft,published', 'instrucao' => 'nullable|string', 'series_linhas' => 'nullable|array',
            'series_linhas.*.repeticoes' => 'nullable|integer|min:0', 'series_linhas.*.exercicio' => 'nullable|string|max:255',
            'series_linhas.*.metros' => 'nullable|integer|min:0', 'series_linhas.*.zona' => 'nullable|string|max:30',
            'training_groups' => 'nullable|array', 'training_groups.*.training_group_id' => 'required|uuid|exists:training_groups,id',
            'training_groups.*.training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id', 'training_groups.*.instruction' => 'nullable|string',
            'training_groups.*.sort_order' => 'nullable|integer|min:0|max:999', 'training_groups.*.lanes' => 'nullable|array',
            'training_groups.*.lanes.*.lane_id' => 'required|uuid|exists:sports_pool_lanes,id',
            'training_groups.*.lanes.*.planned_capacity' => 'nullable|integer|min:1|max:999',
        ];
        $validator = validator($data, $rules);
        if ($validator->fails()) throw new ValidationException($validator);

        $this->assertPlanningHierarchy($data);

        $groupRows = collect($data['training_groups'] ?? []);
        $hasGroupContent = $groupRows->contains(fn (array $row): bool => filled($row['training_plan_version_id'] ?? null) || filled($row['instruction'] ?? null));
        $status = $data['session_status'] ?? 'draft';
        $isRecurrenceDraft = ! empty($data['training_recurrence_id']) && $status === 'draft';

        if ($planVersion === null && blank($data['tipo_treino'] ?? null) && ! $hasGroupContent && empty($data['series_linhas']) && ! $isRecurrenceDraft) {
            throw ValidationException::withMessages(['tipo_treino' => 'O tipo de treino é obrigatório quando a sessão não usa um plano ou instrução por grupo.']);
        }
        if ($planVersion !== null && ! empty($data['series_linhas'])) {
            throw ValidationException::withMessages(['series_linhas' => 'Uma sessão não pode receber simultaneamente um plano versionado e séries manuais.']);
        }

        $hasGlobalContent = $planVersion !== null || filled($data['instrucao'] ?? null) || ! empty($data['series_linhas']);
        if ($status === 'published' && $groupRows->isEmpty() && ! $hasGlobalContent) {
            throw ValidationException::withMessages(['session_status' => 'Uma sessão publicada precisa de um plano, séries ou instrução.']);
        }

        if ($planVersion !== null && (string) $planVersion->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training_plan_version_id' => 'A versão do plano pertence a outro clube.']);
        }

        if (! empty($data['training_recurrence_id'])) {
            $recurrence = TrainingRecurrence::query()->where('club_id', $this->clubContext->id())->whereKey($data['training_recurrence_id'])->first();
            if ($recurrence === null) throw ValidationException::withMessages(['training_recurrence_id' => 'A recorrência pertence a outro clube.']);
        }
    }

    private function assertPlanningHierarchy(array $data): void
    {
        $clubId = $this->clubContext->id();
        $season = null; $macro = null; $meso = null; $micro = null;
        if (! empty($data['epoca_id'])) {
            $season = Season::query()->where('club_id', $clubId)->whereKey($data['epoca_id'])->first();
            if (! $season) throw ValidationException::withMessages(['epoca_id' => 'A época não pertence ao clube ativo.']);
        }
        if (! empty($data['macrocycle_id'])) {
            $macro = Macrocycle::query()->where('club_id', $clubId)->whereKey($data['macrocycle_id'])->first();
            if (! $macro || ($season && (string) $macro->epoca_id !== (string) $season->id)) throw ValidationException::withMessages(['macrocycle_id' => 'O macrociclo não pertence à época selecionada.']);
        }
        if (! empty($data['mesociclo_id'])) {
            $meso = Mesocycle::query()->where('club_id', $clubId)->whereKey($data['mesociclo_id'])->first();
            if (! $meso || ($macro && (string) $meso->macrociclo_id !== (string) $macro->id)) throw ValidationException::withMessages(['mesociclo_id' => 'O mesociclo não pertence ao macrociclo selecionado.']);
        }
        if (! empty($data['microciclo_id'])) {
            $micro = Microcycle::query()->where('club_id', $clubId)->whereKey($data['microciclo_id'])->first();
            if (! $micro || ($meso && (string) $micro->mesociclo_id !== (string) $meso->id)) throw ValidationException::withMessages(['microciclo_id' => 'O microciclo não pertence ao mesociclo selecionado.']);
        }
        if (! empty($data['data']) && $micro?->data_inicio && $micro?->data_fim) {
            $date = (string) $data['data'];
            if ($date < $micro->data_inicio->toDateString() || $date > $micro->data_fim->toDateString()) {
                throw ValidationException::withMessages(['data' => 'A data da sessão tem de ficar dentro do microciclo selecionado.']);
            }
        }
    }

    private function createTraining(array $data, User $criadoPor, ?TrainingPlanVersion $planVersion): Training
    {
        $venue = null; $pool = null;
        if (! empty($data['sports_pool_id'])) {
            $pool = SportsPool::query()->with('venue')->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($data['sports_pool_id'])->first();
            if (! $pool || ! $pool->venue?->active) throw ValidationException::withMessages(['sports_pool_id' => 'A piscina/área selecionada não pertence ao clube ativo ou está inativa.']);
            $venue = $pool->venue;
        }
        if (! empty($data['sports_venue_id'])) {
            $selectedVenue = SportsVenue::query()->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($data['sports_venue_id'])->first();
            if (! $selectedVenue) throw ValidationException::withMessages(['sports_venue_id' => 'O local selecionado não pertence ao clube ativo ou está inativo.']);
            if ($venue && (string) $venue->id !== (string) $selectedVenue->id) throw ValidationException::withMessages(['sports_pool_id' => 'A piscina/área não pertence ao local selecionado.']);
            $venue = $selectedVenue;
        }

        $payload = [
            'numero_treino' => $data['numero_treino'] ?? $this->generateNumeroTreino(), 'data' => $data['data'] ?? null,
            'hora_inicio' => $data['hora_inicio'] ?? null, 'hora_fim' => $data['hora_fim'] ?? null,
            'local' => $data['local'] ?? $venue?->name, 'epoca_id' => $data['epoca_id'] ?? null,
            'microciclo_id' => $data['microciclo_id'] ?? null, 'tipo_treino' => $data['tipo_treino'] ?? $planVersion?->tipo_treino ?? 'treino',
            'volume_planeado_m' => $data['volume_planeado_m'] ?? $planVersion?->volume_planeado_m,
            'notas_gerais' => $data['notas_gerais'] ?? null, 'descricao_treino' => $data['descricao_treino'] ?? null,
            'criado_por' => $criadoPor->id, 'club_id' => $this->clubContext->id(), 'responsavel_id' => $data['responsavel_id'] ?? null,
            'session_status' => $data['session_status'] ?? 'draft', 'instrucao' => $data['instrucao'] ?? null,
            'published_at' => ($data['session_status'] ?? 'draft') === 'published' ? now() : null,
            'sports_venue_id' => $venue?->id, 'sports_pool_id' => $pool?->id,
            'training_recurrence_id' => $data['training_recurrence_id'] ?? null,
            'recurrence_occurrence_key' => $data['recurrence_occurrence_key'] ?? null,
        ];
        if (Schema::hasColumn('trainings', 'macrocycle_id')) $payload['macrocycle_id'] = $data['macrocycle_id'] ?? null;
        if (Schema::hasColumn('trainings', 'mesociclo_id')) $payload['mesociclo_id'] = $data['mesociclo_id'] ?? null;
        return Training::query()->create($payload);
    }

    private function createSeriesRows(Training $training, array $seriesRows): void
    {
        foreach ($seriesRows as $index => $row) {
            $repeticoes = (int) ($row['repeticoes'] ?? 0); $metros = (int) ($row['metros'] ?? 0);
            $exercicio = trim((string) ($row['exercicio'] ?? '')); $zona = trim((string) ($row['zona'] ?? ''));
            if ($repeticoes <= 0 && $metros <= 0 && $exercicio === '' && $zona === '') continue;
            TrainingSeries::query()->create([
                'treino_id' => $training->id, 'ordem' => $index + 1, 'descricao_texto' => $exercicio,
                'repeticoes' => $repeticoes > 0 ? $repeticoes : null, 'distancia_m' => $metros > 0 ? $metros : null,
                'distancia_total_m' => $repeticoes > 0 && $metros > 0 ? $repeticoes * $metros : 0,
                'zona_intensidade' => $zona !== '' ? $zona : null, 'source' => 'manual',
            ]);
        }
    }

    private function generateNumeroTreino(): string
    {
        $max = Training::query()->where('numero_treino', 'LIKE', '#%')->selectRaw("MAX(CAST(SUBSTR(numero_treino, 2) AS INTEGER)) as max_num")->value('max_num');
        return sprintf('#%04d', ((int) ($max ?? 0)) + 1);
    }
}
