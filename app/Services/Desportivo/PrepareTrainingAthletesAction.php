<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Action: Preparar Registos de TrainingAthletes
 *
 * Responsabilidade:
 * - Obter todos atletas elegíveis com base nos escalões do treino
 * - Pré-criar registos de training_athletes para cada atleta
 * - Estado inicial atual: ausente, presente=false (será revisto no novo Cais)
 * - Evitar duplicados (UNIQUE constraint treino_id + user_id)
 */
class PrepareTrainingAthletesAction
{
    public function __construct(
        private readonly SportsMemberStatusResolver $sportsMemberStatusResolver,
    ) {
    }

    /**
     * @param Training $training
     * @param array $escalaoIds Lista de IDs de escalões (age_groups)
     * @return Collection<TrainingAthlete>
     */
    public function execute(Training $training, array $escalaoIds = []): Collection
    {
        $atletas = $this->getEligibleAthletes($escalaoIds);

        if ($atletas->isEmpty()) {
            Log::warning('No eligible athletes found for training', [
                'training_id' => $training->id,
                'escalao_ids' => $escalaoIds,
            ]);

            return collect();
        }

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

        try {
            DB::table('training_athletes')->insert($athleteRecords);

            Log::info('Training athletes prepared', [
                'training_id' => $training->id,
                'count' => count($athleteRecords),
            ]);

            return TrainingAthlete::where('treino_id', $training->id)->get();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23505') {
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
     * @param array $escalaoIds
     * @return Collection<User>
     */
    private function getEligibleAthletes(array $escalaoIds): Collection
    {
        $normalizedAgeGroups = collect($escalaoIds)
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        return User::query()
            ->with([
                'userTypes:id,codigo,nome',
                'athleteSportsData:id,user_id,escalao_id,ativo',
            ])
            ->where('estado', 'ativo')
            ->orderBy('nome_completo')
            ->get()
            ->filter(function (User $user) use ($normalizedAgeGroups): bool {
                if (! $this->sportsMemberStatusResolver->isActiveAthlete($user)) {
                    return false;
                }

                if ($normalizedAgeGroups->isEmpty()) {
                    return true;
                }

                $officialAgeGroupId = $this->sportsMemberStatusResolver->officialAgeGroupId($user);

                return $officialAgeGroupId !== null
                    && $normalizedAgeGroups->contains((string) $officialAgeGroupId);
            })
            ->values();
    }

    /**
     * @param Training $training
     * @param array $newEscalaoIds
     * @return Collection
     */
    public function updateForChangedEscaloes(Training $training, array $newEscalaoIds): Collection
    {
        $existingAthleteIds = $training->athleteRecords()->pluck('user_id')->toArray();
        $newEligibleAthletes = $this->getEligibleAthletes($newEscalaoIds);
        $newAthleteIds = $newEligibleAthletes->pluck('id')->toArray();
        $toAdd = array_diff($newAthleteIds, $existingAthleteIds);

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
