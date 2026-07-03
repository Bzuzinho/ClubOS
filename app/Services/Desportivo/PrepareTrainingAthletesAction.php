<?php

namespace App\Services\Desportivo;

use App\Models\AthleteSportsData;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Action: Preparar Registos de TrainingAthletes
 * 
 * Responsabilidade:
 * - Obter todos atletas elegíveis com base nos escalões do treino
 * - Pré-criar registos de training_athletes para cada atleta
 * - Estado inicial: ausente, presente=false
 * - Evitar duplicados (UNIQUE constraint treino_id + user_id)
 */
class PrepareTrainingAthletesAction
{
    public function __construct(private readonly MemberTypeResolver $memberTypeResolver)
    {
    }

    /**
     * Prepara training_athletes para um treino
     * 
     * @param Training $training
     * @param array $escalaoIds Lista de IDs de escalões (age_groups)
     * @return Collection<TrainingAthlete>
     */
    public function execute(Training $training, array $escalaoIds = []): Collection
    {
        // Obter atletas elegíveis
        $atletas = $this->getEligibleAthletes($escalaoIds);

        if ($atletas->isEmpty()) {
            Log::warning('No eligible athletes found for training', [
                'training_id' => $training->id,
                'escalao_ids' => $escalaoIds,
            ]);

            return collect();
        }

        // Preparar dados para bulk insert
        $athleteRecords = $atletas->map(function (User $atleta) use ($training) {
            return [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'treino_id' => $training->id,
                'user_id' => $atleta->id,
                'presente' => false,
                'estado' => 'ausente',
                'volume_real_m' => null,
                'rpe' => null,
                'observacoes_tecnicas' => null,
                'registado_por' => $training->criado_por,
                'registado_em' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Bulk insert (mais performant que loop de save())
        try {
            DB::table('training_athletes')->insert($athleteRecords);

            Log::info('Training athletes prepared', [
                'training_id' => $training->id,
                'count' => count($athleteRecords),
            ]);

            // Retornar collection de TrainingAthletes criados
            return TrainingAthlete::where('treino_id', $training->id)->get();

        } catch (\Illuminate\Database\QueryException $e) {
            // Se houver constraint violation (duplicate), logar mas não falhar
            if ($e->getCode() === '23505') { // PostgreSQL unique violation code
                Log::warning('Some training athletes already exist (duplicate)', [
                    'training_id' => $training->id,
                    'error' => $e->getMessage(),
                ]);

                return TrainingAthlete::where('treino_id', $training->id)->get();
            }

            throw $e;
        }
    }

    /**
     * Obter atletas elegíveis para o treino com base nos escalões
     * 
     * @param array $escalaoIds
     * @return Collection<User>
     */
    private function getEligibleAthletes(array $escalaoIds): Collection
    {
        $query = User::query()
            ->with(['userTypes:id,codigo,nome', 'athleteSportsData:id,user_id,escalao_id'])
            ->where('estado', 'ativo')
            ->where(function ($subQuery) {
                $subQuery
                    ->whereNull('ativo_desportivo')
                    ->orWhere('ativo_desportivo', true);
            });

        if (!empty($escalaoIds)) {
            $query->whereHas('athleteSportsData', function ($sportsDataQuery) use ($escalaoIds) {
                $sportsDataQuery->whereIn('escalao_id', $escalaoIds);
            });
        }

        return $query
            ->orderBy('nome_completo')
            ->get()
            ->filter(fn (User $user): bool => $this->memberTypeResolver->isAthlete($user))
            ->values();
    }

    /**
     * Atualizar training_athletes existentes (caso treino seja reeditado e escalões mudem)
     * 
     * @param Training $training
     * @param array $newEscalaoIds
     * @return Collection
     */
    public function updateForChangedEscaloes(Training $training, array $newEscalaoIds): Collection
    {
        $existingAthleteIds = $training->athleteRecords()->pluck('user_id')->toArray();
        $newEligibleAthletes = $this->getEligibleAthletes($newEscalaoIds);
        $newAthleteIds = $newEligibleAthletes->pluck('id')->toArray();

        // Atletas a adicionar (novos)
        $toAdd = array_diff($newAthleteIds, $existingAthleteIds);

        // Atletas a remover (se necessário - decisão de negócio)
        // Para segurança, NÃO remover automaticamente se já tem presenças marcadas
        // $toRemove = array_diff($existingAthleteIds, $newAthleteIds);

        // Adicionar novos atletas
        if (!empty($toAdd)) {
            $newAthletes = $newEligibleAthletes->whereIn('id', $toAdd);

            $athleteRecords = $newAthletes->map(function (User $atleta) use ($training) {
                return [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'treino_id' => $training->id,
                    'user_id' => $atleta->id,
                    'presente' => false,
                    'estado' => 'ausente',
                    'registado_por' => $training->criado_por,
                    'registado_em' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            DB::table('training_athletes')->insert($athleteRecords);

            Log::info('Added new athletes to training', [
                'training_id' => $training->id,
                'added_count' => count($athleteRecords),
            ]);
        }

        return TrainingAthlete::where('treino_id', $training->id)->get();
    }
}
