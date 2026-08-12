<?php

namespace App\Services\Desportivo;

use App\Models\SportsPoolLane;
use App\Models\Training;
use App\Models\TrainingGroup;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingSessionGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingSessionGroupService
{
    public function __construct(private readonly SportsClubContext $clubContext) {}

    /** @param array<int,array<string,mixed>> $assignments */
    public function replace(Training $session, array $assignments, ?User $actor = null): Collection
    {
        $this->assertSessionTenant($session);
        if ($session->isCompleted()) {
            throw ValidationException::withMessages(['training_groups' => 'A composição planeada de uma sessão concluída não pode ser alterada.']);
        }

        $normalized = collect($assignments)->values();
        $groupIds = $normalized->map(fn (array $row): string => trim((string) ($row['training_group_id'] ?? '')))->filter();
        if ($groupIds->count() !== $groupIds->unique()->count()) {
            throw ValidationException::withMessages(['training_groups' => 'O mesmo grupo de treino não pode ser repetido na mesma sessão.']);
        }

        return DB::transaction(function () use ($session, $normalized, $actor): Collection {
            $session->sessionGroups()->delete();
            foreach ($normalized as $index => $row) $this->createAssignment($session, $row, $index, $actor);

            return $session->fresh(['sessionGroups.group', 'sessionGroups.planVersion', 'sessionGroups.lanes.pool.venue'])->sessionGroups;
        });
    }

    private function createAssignment(Training $session, array $row, int $index, ?User $actor): TrainingSessionGroup
    {
        $clubId = $this->clubContext->id();
        $group = TrainingGroup::query()->where('club_id', $clubId)->whereKey(trim((string) ($row['training_group_id'] ?? '')))->first();
        if ($group === null || ! $group->active) {
            throw ValidationException::withMessages(['training_groups' => 'Existe pelo menos um grupo de treino inválido ou inativo.']);
        }

        $planVersion = null;
        $planVersionId = trim((string) ($row['training_plan_version_id'] ?? ''));
        if ($planVersionId !== '') {
            $planVersion = TrainingPlanVersion::query()->where('club_id', $clubId)->whereKey($planVersionId)->first();
            if ($planVersion === null) {
                throw ValidationException::withMessages(['training_groups' => 'Existe pelo menos uma versão de plano inválida para o clube ativo.']);
            }
        }

        $instruction = $this->nullableText($row['instruction'] ?? null);
        $hasGlobalContent = $session->training_plan_version_id !== null || filled($session->instrucao) || $session->series()->exists();
        if ($session->session_status === 'published' && ! $hasGlobalContent && $planVersion === null && $instruction === null) {
            throw ValidationException::withMessages(['training_groups' => "O grupo {$group->name} precisa de um plano ou instrução antes da publicação."]);
        }

        $laneRows = collect($row['lanes'] ?? [])->values();
        $laneIds = $laneRows->map(fn (array $lane): string => trim((string) ($lane['lane_id'] ?? '')))->filter()->unique()->values();
        $lanes = $laneIds->isEmpty() ? collect() : SportsPoolLane::query()
            ->with('pool.venue')
            ->where('club_id', $clubId)
            ->where('active', true)
            ->whereIn('id', $laneIds->all())
            ->get()->keyBy(fn (SportsPoolLane $lane): string => (string) $lane->id);

        if ($lanes->count() !== $laneIds->count()) {
            throw ValidationException::withMessages(['training_groups' => 'Existe pelo menos uma pista canónica inválida ou inativa.']);
        }

        $poolIds = $lanes->pluck('sports_pool_id')->map('strval')->unique()->values();
        if ($poolIds->count() > 1) {
            throw ValidationException::withMessages(['training_groups' => 'As pistas de uma sessão têm de pertencer à mesma piscina/área.']);
        }

        if ($poolIds->isNotEmpty()) {
            /** @var SportsPoolLane $firstLane */
            $firstLane = $lanes->first();
            $pool = $firstLane->pool;
            $venue = $pool?->venue;
            if (! $pool || ! $venue || ! $pool->active || ! $venue->active) {
                throw ValidationException::withMessages(['training_groups' => 'A piscina/área ou o local selecionado está inativo.']);
            }
            if ($session->sports_pool_id && (string) $session->sports_pool_id !== (string) $pool->id) {
                throw ValidationException::withMessages(['training_groups' => 'As pistas não pertencem à piscina/área definida para a sessão.']);
            }
            if ($session->sports_venue_id && (string) $session->sports_venue_id !== (string) $venue->id) {
                throw ValidationException::withMessages(['training_groups' => 'As pistas não pertencem ao local definido para a sessão.']);
            }

            $session->forceFill([
                'sports_venue_id' => $venue->id,
                'sports_pool_id' => $pool->id,
                'local' => filled($session->local) ? $session->local : $venue->name,
            ])->save();
        }

        $assignment = TrainingSessionGroup::query()->create([
            'club_id' => $clubId,
            'training_id' => $session->id,
            'training_group_id' => $group->id,
            'training_plan_version_id' => $planVersion?->id,
            'instruction' => $instruction,
            'sort_order' => (int) ($row['sort_order'] ?? $index),
            'created_by' => $actor?->id,
        ]);

        $pivot = [];
        foreach ($laneRows as $laneRow) {
            $laneId = trim((string) ($laneRow['lane_id'] ?? ''));
            if ($laneId === '' || ! $lanes->has($laneId)) continue;
            $planned = $laneRow['planned_capacity'] ?? null;
            $pivot[$laneId] = ['club_id' => $clubId, 'planned_capacity' => $planned === null ? null : max(1, (int) $planned)];
        }
        if ($pivot !== []) $assignment->lanes()->sync($pivot);

        return $assignment;
    }

    private function assertSessionTenant(Training $session): void
    {
        if ((string) $session->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training' => 'A sessão de treino pertence a outro clube.']);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
