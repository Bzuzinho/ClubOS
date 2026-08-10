<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Action: Preparar Registos de TrainingAthletes
 *
 * Responsabilidade:
 * - Obter atletas elegíveis por escalão ou pelos grupos técnicos planeados
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
     * @param array<int,string> $escalaoIds
     * @return Collection<int,TrainingAthlete>
     */
    public function execute(Training $training, array $escalaoIds = []): Collection
    {
        $atletas = $this->getEligibleAthletes($escalaoIds);

        return $this->insertAthletes($training, $atletas, [
            'escalao_ids' => $escalaoIds,
        ]);
    }

    /**
     * Prepare only athletes who are members of the selected technical groups on
     * the session date. Complementary groups are allowed and duplicates are
     * collapsed into a single training_athletes row.
     *
     * @param array<int,string> $groupIds
     * @return Collection<int,TrainingAthlete>
     */
    public function executeForGroups(Training $training, array $groupIds): Collection
    {
        $normalized = collect($groupIds)
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return collect();
        }

        $date = $training->data?->toDateString() ?? now()->toDateString();

        $userIds = TrainingGroupMembership::query()
            ->where('club_id', $training->club_id)
            ->whereIn('training_group_id', $normalized->all())
            ->activeOn($date)
            ->pluck('user_id')
            ->map('strval')
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $athletes = User::query()
            ->with([
                'userTypes:id,codigo,nome',
                'athleteSportsData:id,user_id,escalao_id,ativo',
            ])
            ->whereIn('id', $userIds->all())
            ->where('estado', 'ativo')
            ->orderBy('nome_completo')
            ->get()
            ->filter(fn (User $user): bool => $this->sportsMemberStatusResolver->isActiveAthlete($user))
            ->values();

        return $this->insertAthletes($training, $athletes, [
            'training_group_ids' => $normalized->all(),
        ]);
    }

    /**
     * @param array<int,string> $escalaoIds
     * @return Collection<int,User>
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
                if (!$this->sportsMemberStatusResolver->isActiveAthlete($user)) {
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
     * @param Collection<int,User> $athletes
     * @param array<string,mixed> $context
     * @return Collection<int,TrainingAthlete>
     */
    private function insertAthletes(Training $training, Collection $athletes, array $context = []): Collection
    {
        if ($athletes->isEmpty()) {
            Log::warning('No eligible athletes found for training', [
                'training_id' => $training->id,
                ...$context,
            ]);

            return collect();
        }

        $existing = TrainingAthlete::query()
            ->where('treino_id', $training->id)
            ->pluck('user_id')
            ->map('strval');

        $athletes = $athletes
            ->reject(fn (User $athlete): bool => $existing->contains((string) $athlete->id))
            ->values();

        if ($athletes->isEmpty()) {
            return TrainingAthlete::query()->where('treino_id', $training->id)->get();
        }

        $now = now();
        $records = $athletes->map(function (User $athlete) use ($training, $now): array {
            return [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'treino_id' => $training->id,
                'user_id' => $athlete->id,
                'presente' => false,
                'estado' => 'ausente',
                'volume_real_m' => null,
                'rpe' => null,
                'observacoes_tecnicas' => null,
                'registado_por' => $training->criado_por,
                'registado_em' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        DB::table('training_athletes')->insert($records);

        Log::info('Training athletes prepared', [
            'training_id' => $training->id,
            'count' => count($records),
            ...$context,
        ]);

        return TrainingAthlete::query()->where('treino_id', $training->id)->get();
    }

    /**
     * @param array<int,string> $newEscalaoIds
     * @return Collection<int,TrainingAthlete>
     */
    public function updateForChangedEscaloes(Training $training, array $newEscalaoIds): Collection
    {
        $existingAthleteIds = $training->athleteRecords()->pluck('user_id')->map('strval');
        $newEligibleAthletes = $this->getEligibleAthletes($newEscalaoIds)
            ->reject(fn (User $athlete): bool => $existingAthleteIds->contains((string) $athlete->id))
            ->values();

        return $this->insertAthletes($training, $newEligibleAthletes, [
            'escalao_ids' => $newEscalaoIds,
            'mode' => 'changed_age_groups',
        ]);
    }
}
