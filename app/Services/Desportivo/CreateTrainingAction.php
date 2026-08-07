<?php

namespace App\Services\Desportivo;

use App\Models\TrainingSeries;
use App\Models\Training;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Action: Criar Treino Completo
 * 
 * Responsabilidade:
 * - Criar Training
 * - Sincronizar escalões ao Training
 * - Pré-criar registos de training_athletes para atletas elegíveis
 * - Manter attendance no domínio de treinos
 * 
 * Garante transação DB completa: se qualquer etapa falhar, rollback total
 */
class CreateTrainingAction
{
    public function __construct(
        private PrepareTrainingAthletesAction $prepareAthletesAction
    ) {}

    /**
     * Executa criação de treino completo
     * 
     * @param array $data Dados do treino
     * @param User $criadoPor User que está criando
     * @return Training
     * @throws ValidationException
     * @throws \Exception
     */
    public function execute(array $data, User $criadoPor): Training
    {
        // Validação básica
        $this->validate($data);

        DB::beginTransaction();

        try {
            // 1. Criar Training
            $training = $this->createTraining($data, $criadoPor);

            // 2. Sincronizar escalões ao Training
            if (Schema::hasTable('training_age_group') && !empty($data['escaloes'])) {
                $training->syncAgeGroupsWithPivot($data['escaloes']);
            }

            // 3. Pré-criar training_athletes para atletas elegíveis
            $this->prepareAthletesAction->execute($training, $data['escaloes'] ?? []);

            // 4. Persistir tabela de séries (quando enviada)
            $this->createSeriesRows($training, $data['series_linhas'] ?? []);

            DB::commit();

            Log::info('Training created successfully', [
                'training_id' => $training->id,
                'created_by' => $criadoPor->id,
            ]);

            return Schema::hasTable('training_age_group')
                ? $training->fresh(['athleteRecords', 'ageGroups'])
                : $training->fresh(['athleteRecords']);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create training', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);

            throw $e;
        }
    }

    /**
     * Validação de dados
     */
    private function validate(array $data): void
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
            'tipo_treino' => 'required|string|max:100',
            'volume_planeado_m' => 'nullable|integer|min:0',
            'escaloes' => 'nullable|array',
            'escaloes.*' => 'uuid|exists:age_groups,id',
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
    }

    /**
     * Cria registo de Training
     */
    private function createTraining(array $data, User $criadoPor): Training
    {
        $trainingDate = !empty($data['data']) ? $data['data'] : null;

        $payload = [
            'numero_treino' => $data['numero_treino'] ?? $this->generateNumeroTreino(),
            'data' => $trainingDate,
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fim' => $data['hora_fim'] ?? null,
            'local' => $data['local'] ?? null,
            'epoca_id' => $data['epoca_id'] ?? null,
            'microciclo_id' => $data['microciclo_id'] ?? null,
            'tipo_treino' => $data['tipo_treino'],
            'volume_planeado_m' => $data['volume_planeado_m'] ?? null,
            'notas_gerais' => $data['notas_gerais'] ?? null,
            'descricao_treino' => $data['descricao_treino'] ?? null,
            'criado_por' => $criadoPor->id,
        ];

        if (Schema::hasColumn('trainings', 'macrocycle_id')) {
            $payload['macrocycle_id'] = $data['macrocycle_id'] ?? null;
        }

        if (Schema::hasColumn('trainings', 'mesociclo_id')) {
            $payload['mesociclo_id'] = $data['mesociclo_id'] ?? null;
        }

        return Training::create($payload);
    }

    /**
     * Persistir linhas da tabela de séries enviadas no formulário.
     */
    private function createSeriesRows(Training $training, array $seriesRows): void
    {
        if (empty($seriesRows)) {
            return;
        }

        foreach ($seriesRows as $index => $row) {
            $repeticoes = (int) ($row['repeticoes'] ?? 0);
            $metros = (int) ($row['metros'] ?? 0);
            $exercicio = trim((string) ($row['exercicio'] ?? ''));
            $zona = trim((string) ($row['zona'] ?? ''));

            if ($repeticoes <= 0 && $metros <= 0 && $exercicio === '' && $zona === '') {
                continue;
            }

            TrainingSeries::create([
                'treino_id' => $training->id,
                'ordem' => $index + 1,
                'descricao_texto' => $exercicio !== '' ? $exercicio : null,
                'repeticoes' => $repeticoes > 0 ? $repeticoes : null,
                'distancia_total_m' => $repeticoes > 0 && $metros > 0 ? ($repeticoes * $metros) : null,
                'zona_intensidade' => $zona !== '' ? $zona : null,
            ]);
        }
    }

    /**
     * Gera número sequencial de treino (ex: #0001)
     */
    private function generateNumeroTreino(): string
    {
        $max = Training::where('numero_treino', 'LIKE', '#%')
            ->selectRaw("MAX(CAST(SUBSTR(numero_treino, 2) AS INTEGER)) as max_num")
            ->value('max_num');

        return sprintf('#%04d', ((int) ($max ?? 0)) + 1);
    }

}
