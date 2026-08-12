<?php

namespace App\Services\Desportivo;

use App\Models\SportsVenueClosure;
use App\Models\Training;
use App\Models\TrainingGroupMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TrainingScheduleConflictService
{
    public function __construct(
        private readonly TrainingSchedulingPolicyService $policyService,
        private readonly SportsClubContext $clubContext,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function evaluate(Training $session): array
    {
        $this->assertTenant($session);
        if ($session->data === null || blank($session->hora_inicio) || blank($session->hora_fim)) return [];

        $session->loadMissing(['sessionGroups.group', 'sessionGroups.lanes.pool', 'athleteRecords']);
        $issues = [];
        $others = $this->overlappingSessions($session);
        $laneIds = $this->laneIds($session);

        $usage = [];
        foreach ($session->sessionGroups as $assignment) {
            foreach ($assignment->lanes as $lane) {
                $id = (string) $lane->id;
                $usage[$id] ??= [];
                $usage[$id][] = (string) $assignment->training_group_id;
            }
        }
        foreach ($usage as $laneId => $groupIds) {
            if (count($groupIds) <= 1) continue;
            $issue = $this->issue('lane_overlap', 'A mesma pista está a ser partilhada por vários grupos na mesma sessão.', [
                'scope' => 'same_session', 'sports_pool_lane_ids' => [$laneId],
                'training_group_ids' => array_values(array_unique($groupIds)),
            ]);
            if ($issue) $issues[] = $issue;
        }

        if ($laneIds->isNotEmpty()) {
            foreach ($others as $other) {
                $other->loadMissing('sessionGroups.lanes');
                $shared = $laneIds->intersect($this->laneIds($other))->values();
                if ($shared->isEmpty()) continue;
                $issue = $this->issue('lane_overlap', 'Existem pistas partilhadas com outra sessão no mesmo horário.', [
                    'other_training_id' => (string) $other->id,
                    'sports_pool_lane_ids' => $shared->all(),
                ]);
                if ($issue) $issues[] = $issue;
            }
        }

        $athleteIds = $this->athleteIds($session);
        if ($athleteIds->isNotEmpty()) {
            foreach ($others as $other) {
                $shared = $athleteIds->intersect($this->athleteIds($other))->values();
                if ($shared->isEmpty()) continue;
                $issue = $this->issue('athlete_overlap', 'Existem atletas planeados em duas sessões no mesmo horário.', [
                    'other_training_id' => (string) $other->id,
                    'athlete_ids' => $shared->all(), 'athlete_count' => $shared->count(),
                ]);
                if ($issue) $issues[] = $issue;
            }
        }

        $allocations = [];
        foreach ($session->sessionGroups as $assignment) {
            $plannedAthletes = TrainingGroupMembership::query()
                ->where('club_id', $this->clubContext->id())
                ->where('training_group_id', $assignment->training_group_id)
                ->activeOn($session->data->toDateString())
                ->distinct('user_id')->count('user_id');

            $capacity = $assignment->lanes->sum(function ($lane) use (&$allocations): int {
                $physical = max(0, (int) ($lane->capacity ?? 0));
                $planned = $lane->pivot?->planned_capacity;
                $allocated = $planned !== null ? (int) $planned : $physical;
                $id = (string) $lane->id;
                $allocations[$id] ??= ['allocated' => 0, 'physical' => $physical];
                $allocations[$id]['allocated'] += $allocated;
                return $allocated;
            });

            if ($capacity > 0 && $plannedAthletes > $capacity) {
                $issue = $this->issue('capacity', "O grupo {$assignment->group?->name} tem mais atletas planeados do que a capacidade das pistas atribuídas.", [
                    'training_group_id' => (string) $assignment->training_group_id,
                    'planned_athletes' => $plannedAthletes, 'lane_capacity' => $capacity,
                ]);
                if ($issue) $issues[] = $issue;
            }
        }

        foreach ($allocations as $laneId => $allocation) {
            if ($allocation['physical'] <= 0 || $allocation['allocated'] <= $allocation['physical']) continue;
            $issue = $this->issue('capacity', 'A capacidade distribuída entre grupos excede a capacidade física da pista.', [
                'sports_pool_lane_id' => $laneId,
                'allocated_capacity' => $allocation['allocated'], 'physical_capacity' => $allocation['physical'],
            ]);
            if ($issue) $issues[] = $issue;
        }

        foreach ($this->closureConflicts($session, $laneIds) as $closure) {
            $issues[] = [
                'type' => 'closure', 'severity' => 'decision_required',
                'message' => 'O local, piscina/área ou pista atribuída está encerrado neste período; é necessária decisão manual.',
                'context' => [
                    'closure_id' => (string) $closure->id,
                    'sports_venue_id' => (string) $closure->sports_venue_id,
                    'sports_pool_id' => $closure->sports_pool_id ? (string) $closure->sports_pool_id : null,
                    'sports_pool_lane_id' => $closure->sports_pool_lane_id ? (string) $closure->sports_pool_lane_id : null,
                    'reason' => $closure->reason,
                ],
            ];
        }

        return array_values($issues);
    }

    /** @return array<int,array<string,mixed>> */
    public function apply(Training $session, bool $throwOnBlock = true): array
    {
        $issues = $this->evaluate($session);
        $blocking = collect($issues)->firstWhere('severity', 'blocker');
        if ($blocking && $throwOnBlock) {
            throw ValidationException::withMessages(['schedule' => (string) ($blocking['message'] ?? 'Existe um conflito de planeamento bloqueante.')]);
        }
        $session->forceFill([
            'schedule_review_required' => $issues !== [],
            'schedule_conflicts_snapshot' => $issues === [] ? null : $issues,
        ])->save();
        return $issues;
    }

    private function overlappingSessions(Training $session): Collection
    {
        return Training::query()->where('club_id', $this->clubContext->id())->where('id', '<>', $session->id)
            ->whereDate('data', $session->data->toDateString())->whereNotNull('hora_inicio')->whereNotNull('hora_fim')
            ->where('hora_inicio', '<', $session->hora_fim)->where('hora_fim', '>', $session->hora_inicio)->get();
    }

    private function laneIds(Training $session): Collection
    {
        $session->loadMissing('sessionGroups.lanes');
        return $session->sessionGroups->flatMap(fn ($assignment) => $assignment->lanes->pluck('id'))->map('strval')->unique()->values();
    }

    private function athleteIds(Training $session): Collection
    {
        $session->loadMissing('sessionGroups', 'athleteRecords');
        $groupIds = $session->sessionGroups->pluck('training_group_id')->filter()->unique()->values();
        if ($groupIds->isNotEmpty() && $session->data !== null) {
            return TrainingGroupMembership::query()->where('club_id', $this->clubContext->id())
                ->whereIn('training_group_id', $groupIds->all())->activeOn($session->data->toDateString())
                ->pluck('user_id')->map('strval')->unique()->values();
        }
        return $session->athleteRecords->pluck('user_id')->map('strval')->unique()->values();
    }

    private function closureConflicts(Training $session, Collection $laneIds): Collection
    {
        if ($session->sports_venue_id === null) return collect();
        $date = $session->data->toDateString();
        $start = CarbonImmutable::parse($date.' '.$session->hora_inicio);
        $end = CarbonImmutable::parse($date.' '.$session->hora_fim);

        return SportsVenueClosure::query()
            ->where('club_id', $this->clubContext->id())
            ->where('sports_venue_id', $session->sports_venue_id)
            ->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->where(function ($query) use ($session, $laneIds): void {
                $query->where(function ($wholeVenue): void {
                    $wholeVenue->whereNull('sports_pool_id')->whereNull('sports_pool_lane_id');
                });
                if ($session->sports_pool_id) {
                    $query->orWhere(function ($pool) use ($session): void {
                        $pool->where('sports_pool_id', $session->sports_pool_id)->whereNull('sports_pool_lane_id');
                    });
                }
                if ($laneIds->isNotEmpty()) $query->orWhereIn('sports_pool_lane_id', $laneIds->all());
            })->get();
    }

    private function issue(string $type, string $message, array $context): ?array
    {
        $severity = $this->policyService->severityFor($type);
        return $severity === null ? null : ['type' => $type, 'severity' => $severity, 'message' => $message, 'context' => $context];
    }

    private function assertTenant(Training $session): void
    {
        if ((string) $session->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training' => 'A sessão de treino pertence a outro clube.']);
        }
    }
}
