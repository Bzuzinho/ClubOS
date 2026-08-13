<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrepareTrainingAthletesAction
{
    public function __construct(private readonly SportsMemberStatusResolver $sportsMemberStatusResolver) {}

    /** @param array<int,string> $escalaoIds */
    public function execute(Training $training, array $escalaoIds = []): Collection
    {
        return $this->insertAthletes($training, $this->getEligibleAthletes($escalaoIds), ['escalao_ids' => $escalaoIds]);
    }

    /** @param array<int,string> $groupIds */
    public function executeForGroups(Training $training, array $groupIds): Collection
    {
        $normalized = collect($groupIds)->map(fn (mixed $id): string => trim((string) $id))->filter()->unique()->values();
        if ($normalized->isEmpty()) return collect();

        $date = $training->data?->toDateString() ?? now()->toDateString();
        $userIds = TrainingGroupMembership::query()->where('club_id', $training->club_id)
            ->whereIn('training_group_id', $normalized->all())->activeOn($date)
            ->pluck('user_id')->map('strval')->unique()->values();

        return $this->executeForUsers($training, $userIds->all(), ['training_group_ids' => $normalized->all()]);
    }

    /** @param array<int,string> $userIds */
    public function executeForUsers(Training $training, array $userIds, array $context = []): Collection
    {
        $normalized = collect($userIds)->map(fn (mixed $id): string => trim((string) $id))->filter()->unique()->values();
        if ($normalized->isEmpty()) return collect();

        $athletes = User::query()->with(['userTypes:id,codigo,nome', 'athleteSportsData:id,user_id,escalao_id,ativo'])
            ->whereIn('id', $normalized->all())->where('estado', 'ativo')->get()
            ->filter(fn (User $user): bool => $this->sportsMemberStatusResolver->isActiveAthlete($user))->values();

        return $this->insertAthletes($training, $athletes, $context + ['user_ids' => $normalized->all()]);
    }

    private function getEligibleAthletes(array $escalaoIds): Collection
    {
        $normalizedAgeGroups = collect($escalaoIds)->map(fn (mixed $id): string => trim((string) $id))->filter()->unique()->values();
        return User::query()->with(['userTypes:id,codigo,nome', 'athleteSportsData:id,user_id,escalao_id,ativo'])
            ->where('estado', 'ativo')->orderBy('nome_completo')->get()
            ->filter(function (User $user) use ($normalizedAgeGroups): bool {
                if (! $this->sportsMemberStatusResolver->isActiveAthlete($user)) return false;
                if ($normalizedAgeGroups->isEmpty()) return true;
                $officialAgeGroupId = $this->sportsMemberStatusResolver->officialAgeGroupId($user);
                return $officialAgeGroupId !== null && $normalizedAgeGroups->contains((string) $officialAgeGroupId);
            })->values();
    }

    private function insertAthletes(Training $training, Collection $athletes, array $context = []): Collection
    {
        if ($athletes->isEmpty()) {
            Log::warning('No eligible athletes found for training', ['training_id' => $training->id, ...$context]);
            return collect();
        }

        $existing = TrainingAthlete::query()->where('treino_id', $training->id)->pluck('user_id')->map('strval');
        $athletes = $athletes->reject(fn (User $athlete): bool => $existing->contains((string) $athlete->id))->values();
        if ($athletes->isEmpty()) return TrainingAthlete::query()->where('treino_id', $training->id)->get();

        $now = now();
        $records = $athletes->map(fn (User $athlete): array => [
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
        ])->all();
        DB::table('training_athletes')->insert($records);
        Log::info('Training athletes prepared', ['training_id' => $training->id, 'count' => count($records), ...$context]);
        return TrainingAthlete::query()->where('treino_id', $training->id)->get();
    }

    public function updateForChangedEscaloes(Training $training, array $newEscalaoIds): Collection
    {
        $existingAthleteIds = $training->athleteRecords()->pluck('user_id')->map('strval');
        $newEligibleAthletes = $this->getEligibleAthletes($newEscalaoIds)
            ->reject(fn (User $athlete): bool => $existingAthleteIds->contains((string) $athlete->id))->values();
        return $this->insertAthletes($training, $newEligibleAthletes, ['escalao_ids' => $newEscalaoIds, 'mode' => 'changed_age_groups']);
    }
}
