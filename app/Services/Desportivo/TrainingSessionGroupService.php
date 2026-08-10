<?php

namespace App\Services\Desportivo;

use App\Models\SportsVenueLane;
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
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /**
     * Replace the planned group/lane composition of a non-completed session.
     *
     * @param array<int,array<string,mixed>> $assignments
     * @return Collection<int,TrainingSessionGroup>
     */
    public function replace(Training $session, array $assignments, ?User $actor = null): Collection
    {
        $this->assertSessionTenant($session);

        if ($session->isCompleted()) {
            throw ValidationException::withMessages([
                'training_groups' => 'A composição planeada de uma sessão concluída não pode ser alterada.',
            ]);
        }

        $normalized = collect($assignments)->values();
        $groupIds = $normalized
            ->map(fn (array $row): string => trim((string) ($row['training_group_id'] ?? '')))
            ->filter();

        if ($groupIds->count() !== $groupIds->unique()->count()) {
            throw ValidationException::withMessages([
                'training_groups' => 'O mesmo grupo de treino não pode ser repetido na mesma sessão.',
            ]);
        }

        return DB::transaction(function () use ($session, $normalized, $actor): Collection {
            $session->sessionGroups()->delete();

            foreach ($normalized as $index => $row) {
                $this->createAssignment($session, $row, $index, $actor);
            }

            return $session->fresh(['sessionGroups.group', 'sessionGroups.planVersion', 'sessionGroups.lanes'])
                ->sessionGroups;
        });
    }

    /** @param array<string,mixed> $row */
    private function createAssignment(Training $session, array $row, int $index, ?User $actor): TrainingSessionGroup
    {
        $clubId = $this->clubContext->id();
        $groupId = trim((string) ($row['training_group_id'] ?? ''));

        /** @var TrainingGroup|null $group */
        $group = TrainingGroup::query()
            ->where('club_id', $clubId)
            ->whereKey($groupId)
            ->first();

        if ($group === null || !$group->active) {
            throw ValidationException::withMessages([
                'training_groups' => 'Existe pelo menos um grupo de treino inválido ou inativo.',
            ]);
        }

        $planVersionId = trim((string) ($row['training_plan_version_id'] ?? ''));
        $planVersion = null;

        if ($planVersionId !== '') {
            $planVersion = TrainingPlanVersion::query()
                ->where('club_id', $clubId)
                ->whereKey($planVersionId)
                ->first();

            if ($planVersion === null) {
                throw ValidationException::withMessages([
                    'training_groups' => 'Existe pelo menos uma versão de plano inválida para o clube ativo.',
                ]);
            }
        }

        $instruction = $this->nullableText($row['instruction'] ?? null);
        $hasGlobalContent = $session->training_plan_version_id !== null
            || filled($session->instrucao)
            || $session->series()->exists();

        if ($session->session_status === 'published'
            && !$hasGlobalContent
            && $planVersion === null
            && $instruction === null) {
            throw ValidationException::withMessages([
                'training_groups' => "O grupo {$group->name} precisa de um plano ou instrução antes da publicação.",
            ]);
        }

        $laneRows = collect($row['lanes'] ?? [])->values();
        $laneIds = $laneRows
            ->map(fn (array $lane): string => trim((string) ($lane['lane_id'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        $lanes = $laneIds->isEmpty()
            ? collect()
            : SportsVenueLane::query()
                ->with('venue')
                ->where('club_id', $clubId)
                ->where('active', true)
                ->whereIn('id', $laneIds->all())
                ->get()
                ->keyBy(fn (SportsVenueLane $lane): string => (string) $lane->id);

        if ($lanes->count() !== $laneIds->count()) {
            throw ValidationException::withMessages([
                'training_groups' => 'Existe pelo menos uma pista inválida ou inativa.',
            ]);
        }

        $venueIds = $lanes->pluck('sports_venue_id')->map('strval')->unique()->values();

        if ($venueIds->count() > 1) {
            throw ValidationException::withMessages([
                'training_groups' => 'As pistas de um treino têm de pertencer ao mesmo local.',
            ]);
        }

        if ($venueIds->isNotEmpty()) {
            $venueId = (string) $venueIds->first();

            if ($session->sports_venue_id !== null && (string) $session->sports_venue_id !== $venueId) {
                throw ValidationException::withMessages([
                    'training_groups' => 'As pistas selecionadas não pertencem ao local definido para a sessão.',
                ]);
            }

            if ($session->sports_venue_id === null) {
                /** @var SportsVenueLane $firstLane */
                $firstLane = $lanes->first();
                $session->forceFill([
                    'sports_venue_id' => $venueId,
                    'local' => filled($session->local) ? $session->local : $firstLane->venue?->name,
                ])->save();
            }
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
            if ($laneId === '' || !$lanes->has($laneId)) {
                continue;
            }

            $plannedCapacity = $laneRow['planned_capacity'] ?? null;
            $pivot[$laneId] = [
                'club_id' => $clubId,
                'planned_capacity' => $plannedCapacity === null ? null : max(1, (int) $plannedCapacity),
            ];
        }

        if ($pivot !== []) {
            $assignment->lanes()->sync($pivot);
        }

        return $assignment;
    }

    private function assertSessionTenant(Training $session): void
    {
        if ((string) $session->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão de treino pertence a outro clube.',
            ]);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
