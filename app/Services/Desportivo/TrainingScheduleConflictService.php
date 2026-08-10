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
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function evaluate(Training $session): array
    {
        $this->assertTenant($session);

        if ($session->data === null || blank($session->hora_inicio) || blank($session->hora_fim)) {
            return [];
        }

        $session->loadMissing([
            'sessionGroups.group',
            'sessionGroups.lanes',
            'athleteRecords',
        ]);

        $issues = [];
        $others = $this->overlappingSessions($session);
        $laneIds = $this->laneIds($session);

        $intraSessionLaneUsage = [];
        foreach ($session->sessionGroups as $assignment) {
            foreach ($assignment->lanes as $lane) {
                $laneId = (string) $lane->id;
                $intraSessionLaneUsage[$laneId] ??= [];
                $intraSessionLaneUsage[$laneId][] = (string) $assignment->training_group_id;
            }
        }

        foreach ($intraSessionLaneUsage as $laneId => $groupIds) {
            if (count($groupIds) <= 1) {
                continue;
            }

            $issue = $this->issue(
                'lane_overlap',
                'A mesma pista está a ser partilhada por vários grupos na mesma sessão.',
                [
                    'scope' => 'same_session',
                    'lane_ids' => [$laneId],
                    'training_group_ids' => array_values(array_unique($groupIds)),
                ]
            );

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        if ($laneIds->isNotEmpty()) {
            foreach ($others as $other) {
                $other->loadMissing('sessionGroups.lanes');
                $shared = $laneIds->intersect($this->laneIds($other))->values();

                if ($shared->isNotEmpty()) {
                    $issue = $this->issue(
                        'lane_overlap',
                        'Existem pistas partilhadas com outra sessão no mesmo horário.',
                        [
                            'other_training_id' => (string) $other->id,
                            'lane_ids' => $shared->all(),
                        ]
                    );

                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }
        }

        $athleteIds = $this->athleteIds($session);
        if ($athleteIds->isNotEmpty()) {
            foreach ($others as $other) {
                $sharedAthletes = $athleteIds->intersect($this->athleteIds($other))->values();

                if ($sharedAthletes->isNotEmpty()) {
                    $issue = $this->issue(
                        'athlete_overlap',
                        'Existem atletas planeados em duas sessões no mesmo horário.',
                        [
                            'other_training_id' => (string) $other->id,
                            'athlete_ids' => $sharedAthletes->all(),
                            'athlete_count' => $sharedAthletes->count(),
                        ]
                    );

                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }
        }

        $laneAllocations = [];

        foreach ($session->sessionGroups as $assignment) {
            $plannedAthletes = TrainingGroupMembership::query()
                ->where('club_id', $this->clubContext->id())
                ->where('training_group_id', $assignment->training_group_id)
                ->activeOn($session->data->toDateString())
                ->distinct('user_id')
                ->count('user_id');

            $capacity = $assignment->lanes->sum(function ($lane) use (&$laneAllocations): int {
                $planned = $lane->pivot?->planned_capacity;
                $allocated = $planned !== null ? (int) $planned : (int) $lane->capacity;
                $laneId = (string) $lane->id;

                $laneAllocations[$laneId] ??= [
                    'allocated' => 0,
                    'physical' => (int) $lane->capacity,
                ];
                $laneAllocations[$laneId]['allocated'] += $allocated;

                return $allocated;
            });

            if ($capacity > 0 && $plannedAthletes > $capacity) {
                $issue = $this->issue(
                    'capacity',
                    "O grupo {$assignment->group?->name} tem mais atletas planeados do que a capacidade das pistas atribuídas.",
                    [
                        'training_group_id' => (string) $assignment->training_group_id,
                        'planned_athletes' => $plannedAthletes,
                        'lane_capacity' => $capacity,
                    ]
                );

                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        foreach ($laneAllocations as $laneId => $allocation) {
            if ($allocation['allocated'] <= $allocation['physical']) {
                continue;
            }

            $issue = $this->issue(
                'capacity',
                'A capacidade distribuída entre grupos excede a capacidade física da pista.',
                [
                    'sports_venue_lane_id' => $laneId,
                    'allocated_capacity' => $allocation['allocated'],
                    'physical_capacity' => $allocation['physical'],
                ]
            );

            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        foreach ($this->closureConflicts($session, $laneIds) as $closure) {
            $issues[] = [
                'type' => 'closure',
                'severity' => 'decision_required',
                'message' => 'O local ou uma pista atribuída está encerrado neste período; é necessária decisão manual.',
                'context' => [
                    'closure_id' => (string) $closure->id,
                    'sports_venue_id' => (string) $closure->sports_venue_id,
                    'sports_venue_lane_id' => $closure->sports_venue_lane_id
                        ? (string) $closure->sports_venue_lane_id
                        : null,
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

        if ($blocking !== null && $throwOnBlock) {
            throw ValidationException::withMessages([
                'schedule' => (string) ($blocking['message'] ?? 'Existe um conflito de planeamento bloqueante.'),
            ]);
        }

        $session->forceFill([
            'schedule_review_required' => $issues !== [],
            'schedule_conflicts_snapshot' => $issues === [] ? null : $issues,
        ])->save();

        return $issues;
    }

    /** @return Collection<int,Training> */
    private function overlappingSessions(Training $session): Collection
    {
        return Training::query()
            ->where('club_id', $this->clubContext->id())
            ->where('id', '<>', $session->id)
            ->whereDate('data', $session->data->toDateString())
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fim')
            ->where('hora_inicio', '<', $session->hora_fim)
            ->where('hora_fim', '>', $session->hora_inicio)
            ->get();
    }

    /** @return Collection<int,string> */
    private function laneIds(Training $session): Collection
    {
        $session->loadMissing('sessionGroups.lanes');

        return $session->sessionGroups
            ->flatMap(fn ($assignment) => $assignment->lanes->pluck('id'))
            ->map('strval')
            ->unique()
            ->values();
    }

    /** @return Collection<int,string> */
    private function athleteIds(Training $session): Collection
    {
        $session->loadMissing('sessionGroups', 'athleteRecords');
        $groupIds = $session->sessionGroups->pluck('training_group_id')->filter()->unique()->values();

        if ($groupIds->isNotEmpty() && $session->data !== null) {
            return TrainingGroupMembership::query()
                ->where('club_id', $this->clubContext->id())
                ->whereIn('training_group_id', $groupIds->all())
                ->activeOn($session->data->toDateString())
                ->pluck('user_id')
                ->map('strval')
                ->unique()
                ->values();
        }

        return $session->athleteRecords->pluck('user_id')->map('strval')->unique()->values();
    }

    /** @return Collection<int,SportsVenueClosure> */
    private function closureConflicts(Training $session, Collection $laneIds): Collection
    {
        if ($session->sports_venue_id === null) {
            return collect();
        }

        $date = $session->data->toDateString();
        $start = CarbonImmutable::parse($date . ' ' . $session->hora_inicio);
        $end = CarbonImmutable::parse($date . ' ' . $session->hora_fim);

        return SportsVenueClosure::query()
            ->where('club_id', $this->clubContext->id())
            ->where('sports_venue_id', $session->sports_venue_id)
            ->where('status', 'active')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->where(function ($query) use ($laneIds): void {
                $query->whereNull('sports_venue_lane_id');

                if ($laneIds->isNotEmpty()) {
                    $query->orWhereIn('sports_venue_lane_id', $laneIds->all());
                }
            })
            ->get();
    }

    /** @param array<string,mixed> $context
     *  @return array<string,mixed>|null
     */
    private function issue(string $type, string $message, array $context): ?array
    {
        $severity = $this->policyService->severityFor($type);

        if ($severity === null) {
            return null;
        }

        return [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function assertTenant(Training $session): void
    {
        if ((string) $session->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão de treino pertence a outro clube.',
            ]);
        }
    }
}
