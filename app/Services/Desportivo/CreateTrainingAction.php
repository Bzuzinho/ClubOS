<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Action: criar uma sessão de treino completa.
 *
 * `trainings` é a sessão agendada. O conteúdo pode ser manual (`training_series`)
 * ou um snapshot de uma `training_plan_version` reutilizável.
 */
class CreateTrainingAction
{
    public function __construct(
        private PrepareTrainingAthletesAction $prepareAthletesAction,
        private SportsClubContext $clubContext,
        private TrainingSessionPlanService $sessionPlanService,
    ) {
    }

    public function execute(array $data, User $criadoPor): Training
    {
        $planVersion = !empty($data['training_plan_version_id'])
            ? TrainingPlanVersion::query()->findOrFail($data['training_plan_version_id'])
            : null;

        $this->validate($data, $planVersion);

        DB::beginTransaction();

        try {
            $training = $this->createTraining($data, $criadoPor, $planVersion);

            if (Schema::hasTable('training_age_group') && !empty($data['escaloes'])) {
                $training->syncAgeGroupsWithPivot($data['escaloes']);
            }

            $this->prepareAthletesAction->execute($training, $data['escaloes'] ?? []);

            if ($planVersion !== null) {
                $training = $this->sessionPlanService->assign($training, $planVersion, $criadoPor);
            } else {
                $this->createSeriesRows($training, $data['series_linhas'] ?? []);
            }

            DB::commit();

            Log::info('Training session created successfully', [
                'training_id' => $training->id,
                'training_plan_version_id' => $planVersion?->id,
                'created_by' => $criadoPor->id,
            ]);

            $relations = ['athleteRecords', 'series', 'planVersion'];
            if (Schema::hasTable('training_age_group')) {
                $relations[] = 'ageGroups';
            }

            return $training->fresh($relations);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create training session', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            throw $e;
        }
    }

    private function validate(array $data, ?TrainingPlanVersion $planVersion): void
    {
        $rules = [
            'data' => 'nullable|date',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fim' => 'nullable|date_format:H:i|after:hora_inicio',
            'local' => 'nullable|string|max:255',
            'epoca_id' => 'nullable|uuid|exists:seasons,id',
            'macrocycle_id' => 'nullable|uuid|exists:macrocycles,id',
            'mesociclo_id' => 'nullable|uuid|exists:mesocycles,id',
            'microciclo_id' => 'nullable|uuid|exists:microcycles,id',
            'tipo_treino' => 'nullable|string|max:30',
            'volume_planeado_m' => 'nullable|integer|min:0',
            'escaloes' => 'nullable|array',
            'escaloes.*' => 'uuid|exists:age_groups,id',
            'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'responsavel_id' => 'nullable|uuid|exists:users,id',
            'session_status' => 'nullable|in:draft,published',
            'instrucao' => 'nullable|string',
            'series_linhas' => 'nullable|array',
            'series_linhas.*.repeticoes' => 'nullable|integer|min:0',
            'series_linhas.*.exercicio' => 'nullable|string|max:255',
            'series_linhas.*.metros' => 'nullable|integer|min:0',
            'series_linhas.*.zona' => 'nullable|string|max:30',
        ];

        $validator = validator($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($planVersion === null && blank($data['tipo_treino'] ?? null)) {
            throw ValidationException::withMessages([
                'tipo_treino' => 'O tipo de treino é obrigatório quando a sessão não usa um plano.',
            ]);
        }

        if ($planVersion !== null && !empty($data['series_linhas'])) {
            throw ValidationException::withMessages([
                'series_linhas' => 'Uma sessão não pode receber simultaneamente um plano versionado e séries manuais.',
            ]);
        }
    }

    private function createTraining(array $data, User $criadoPor, ?TrainingPlanVersion $planVersion): Training
    {
        $payload = [
            'numero_treino' => $data['numero_treino'] ?? $this->generateNumeroTreino(),
            'data' => $data['data'] ?? null,
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fim' => $data['hora_fim'] ?? null,
            'local' => $data['local'] ?? null,
            'epoca_id' => $data['epoca_id'] ?? null,
            'microciclo_id' => $data['microciclo_id'] ?? null,
            'tipo_treino' => $data['tipo_treino'] ?? $planVersion?->tipo_treino ?? 'treino',
            'volume_planeado_m' => $data['volume_planeado_m'] ?? $planVersion?->volume_planeado_m,
            'notas_gerais' => $data['notas_gerais'] ?? null,
            'descricao_treino' => $data['descricao_treino'] ?? null,
            'criado_por' => $criadoPor->id,
            'club_id' => $this->clubContext->id(),
            'responsavel_id' => $data['responsavel_id'] ?? null,
            'session_status' => $data['session_status'] ?? 'draft',
            'instrucao' => $data['instrucao'] ?? null,
            'published_at' => ($data['session_status'] ?? 'draft') === 'published' ? now() : null,
        ];

        if (Schema::hasColumn('trainings', 'macrocycle_id')) {
            $payload['macrocycle_id'] = $data['macrocycle_id'] ?? null;
        }

        if (Schema::hasColumn('trainings', 'mesociclo_id')) {
            $payload['mesociclo_id'] = $data['mesociclo_id'] ?? null;
        }

        return Training::query()->create($payload);
    }

    private function createSeriesRows(Training $training, array $seriesRows): void
    {
        foreach ($seriesRows as $index => $row) {
            $repeticoes = (int) ($row['repeticoes'] ?? 0);
            $metros = (int) ($row['metros'] ?? 0);
            $exercicio = trim((string) ($row['exercicio'] ?? ''));
            $zona = trim((string) ($row['zona'] ?? ''));

            if ($repeticoes <= 0 && $metros <= 0 && $exercicio === '' && $zona === '') {
                continue;
            }

            TrainingSeries::query()->create([
                'treino_id' => $training->id,
                'ordem' => $index + 1,
                'descricao_texto' => $exercicio,
                'repeticoes' => $repeticoes > 0 ? $repeticoes : null,
                'distancia_m' => $metros > 0 ? $metros : null,
                'distancia_total_m' => $repeticoes > 0 && $metros > 0 ? ($repeticoes * $metros) : 0,
                'zona_intensidade' => $zona !== '' ? $zona : null,
                'source' => 'manual',
            ]);
        }
    }

    private function generateNumeroTreino(): string
    {
        $max = Training::query()
            ->where('numero_treino', 'LIKE', '#%')
            ->selectRaw("MAX(CAST(SUBSTR(numero_treino, 2) AS INTEGER)) as max_num")
            ->value('max_num');

        return sprintf('#%04d', ((int) ($max ?? 0)) + 1);
    }
}
