<?php

namespace App\Services\Desportivo;

use App\Models\SportsVenue;
use App\Models\SportsVenueLane;
use App\Models\Training;
use App\Models\TrainingGroup;
use App\Models\TrainingPlanVersion;
use App\Models\TrainingRecurrence;
use App\Models\TrainingRecurrenceGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingRecurrenceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly CreateTrainingAction $createTrainingAction,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data, User $actor): TrainingRecurrence
    {
        $validator = validator($data, [
            'name' => 'required|string|max:255',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'frequency' => 'required|in:daily,weekly',
            'interval' => 'nullable|integer|min:1|max:52',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'sports_venue_id' => 'nullable|uuid|exists:sports_venues,id',
            'responsavel_id' => 'nullable|uuid|exists:users,id',
            'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'instruction' => 'nullable|string',
            'training_type' => 'nullable|string|max:100',
            'session_status_template' => 'nullable|in:draft,published',
            'groups' => 'nullable|array',
            'groups.*.training_group_id' => 'required|uuid|exists:training_groups,id',
            'groups.*.training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'groups.*.instruction' => 'nullable|string',
            'groups.*.lanes' => 'nullable|array',
            'groups.*.lanes.*.lane_id' => 'required|uuid|exists:sports_venue_lanes,id',
            'groups.*.lanes.*.planned_capacity' => 'nullable|integer|min:1|max:999',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $clubId = $this->clubContext->id();
        $venue = $this->venueForClub($data['sports_venue_id'] ?? null);
        $planVersion = $this->planForClub($data['training_plan_version_id'] ?? null);

        return DB::transaction(function () use ($data, $actor, $clubId, $venue, $planVersion): TrainingRecurrence {
            $recurrence = TrainingRecurrence::query()->create([
                'club_id' => $clubId,
                'name' => trim((string) $data['name']),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'frequency' => $data['frequency'],
                'interval' => (int) ($data['interval'] ?? 1),
                'weekdays' => $this->normalizeWeekdays($data['weekdays'] ?? []),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'sports_venue_id' => $venue?->id,
                'local_snapshot' => $venue?->name ?? $this->nullableText($data['local_snapshot'] ?? null),
                'responsavel_id' => $data['responsavel_id'] ?? null,
                'training_plan_version_id' => $planVersion?->id,
                'instruction' => $this->nullableText($data['instruction'] ?? null),
                'training_type' => $this->nullableText($data['training_type'] ?? null),
                'session_status_template' => $data['session_status_template'] ?? 'draft',
                'active' => true,
                'created_by' => $actor->id,
            ]);

            $this->replaceGroups($recurrence, $data['groups'] ?? []);

            return $recurrence->fresh(['venue', 'planVersion', 'groups.group', 'groups.planVersion', 'groups.lanes']);
        });
    }

    /**
     * Expand the recurrence up to the supplied date.
     *
     * Closures never auto-delete/skip a generated occurrence: they are created as
     * review-required sessions. Blocker policies (lane/athlete/capacity) stop only
     * the affected occurrence and are returned in `blocked`.
     *
     * @return array{created:array<int,Training>,skipped:array<int,string>,blocked:array<int,array<string,mixed>>}
     */
    public function generateUntil(TrainingRecurrence $recurrence, mixed $until, User $actor): array
    {
        $this->assertTenant($recurrence);

        if (!$recurrence->active) {
            throw ValidationException::withMessages([
                'recurrence' => 'A recorrência está inativa.',
            ]);
        }

        $start = CarbonImmutable::parse($recurrence->starts_on)->startOfDay();
        $requestedEnd = CarbonImmutable::parse($until)->startOfDay();
        if ($recurrence->ends_on !== null) {
            $configuredEnd = CarbonImmutable::parse($recurrence->ends_on)->startOfDay();
            $end = $requestedEnd->lte($configuredEnd) ? $requestedEnd : $configuredEnd;
        } else {
            $end = $requestedEnd;
        }

        if ($end->lt($start)) {
            return ['created' => [], 'skipped' => [], 'blocked' => []];
        }

        if ((int) $start->diffInDays($end) > 730) {
            throw ValidationException::withMessages([
                'until' => 'A geração de recorrência está limitada a 730 dias por operação.',
            ]);
        }

        $recurrence->loadMissing(['venue', 'planVersion', 'groups.group', 'groups.planVersion', 'groups.lanes']);

        $created = [];
        $skipped = [];
        $blocked = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (!$this->matches($recurrence, $date)) {
                continue;
            }

            $occurrenceKey = $date->format('Y-m-d');

            if (Training::query()
                ->where('club_id', $this->clubContext->id())
                ->where('training_recurrence_id', $recurrence->id)
                ->where('recurrence_occurrence_key', $occurrenceKey)
                ->exists()) {
                $skipped[] = $occurrenceKey;
                continue;
            }

            $groupPayload = $recurrence->groups->map(function (TrainingRecurrenceGroup $group): array {
                return [
                    'training_group_id' => (string) $group->training_group_id,
                    'training_plan_version_id' => $group->training_plan_version_id
                        ? (string) $group->training_plan_version_id
                        : null,
                    'instruction' => $group->instruction,
                    'sort_order' => $group->sort_order,
                    'lanes' => $group->lanes->map(fn (SportsVenueLane $lane): array => [
                        'lane_id' => (string) $lane->id,
                        'planned_capacity' => $lane->pivot?->planned_capacity,
                    ])->values()->all(),
                ];
            })->values()->all();

            $ready = $this->isReadyForPublication($recurrence);
            $sessionStatus = $recurrence->session_status_template === 'published' && $ready
                ? 'published'
                : 'draft';

            try {
                $created[] = $this->createTrainingAction->execute([
                    'data' => $occurrenceKey,
                    'hora_inicio' => substr((string) $recurrence->start_time, 0, 5),
                    'hora_fim' => substr((string) $recurrence->end_time, 0, 5),
                    'sports_venue_id' => $recurrence->sports_venue_id,
                    'local' => $recurrence->local_snapshot,
                    'training_plan_version_id' => $recurrence->training_plan_version_id,
                    'responsavel_id' => $recurrence->responsavel_id,
                    'tipo_treino' => $recurrence->training_type,
                    'instrucao' => $recurrence->instruction,
                    'session_status' => $sessionStatus,
                    'training_groups' => $groupPayload,
                    'training_recurrence_id' => $recurrence->id,
                    'recurrence_occurrence_key' => $occurrenceKey,
                ], $actor);
            } catch (ValidationException $exception) {
                $blocked[] = [
                    'date' => $occurrenceKey,
                    'errors' => $exception->errors(),
                ];
            }
        }

        $recurrence->forceFill(['last_generated_until' => $end->toDateString()])->save();

        return [
            'created' => $created,
            'skipped' => $skipped,
            'blocked' => $blocked,
        ];
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function replaceGroups(TrainingRecurrence $recurrence, array $groups): void
    {
        $clubId = $this->clubContext->id();
        $recurrence->groups()->delete();
        $seen = [];

        foreach (array_values($groups) as $index => $row) {
            $groupId = trim((string) ($row['training_group_id'] ?? ''));

            if (in_array($groupId, $seen, true)) {
                throw ValidationException::withMessages([
                    'groups' => 'O mesmo grupo não pode ser repetido na mesma recorrência.',
                ]);
            }
            $seen[] = $groupId;

            $group = TrainingGroup::query()
                ->where('club_id', $clubId)
                ->where('active', true)
                ->whereKey($groupId)
                ->first();

            if ($group === null) {
                throw ValidationException::withMessages([
                    'groups' => 'Existe pelo menos um grupo inválido ou inativo.',
                ]);
            }

            $plan = $this->planForClub($row['training_plan_version_id'] ?? null);
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
                    'groups' => 'Existe pelo menos uma pista inválida ou inativa.',
                ]);
            }

            $venueIds = $lanes->pluck('sports_venue_id')->map('strval')->unique()->values();
            if ($venueIds->count() > 1) {
                throw ValidationException::withMessages([
                    'groups' => 'As pistas de uma recorrência têm de pertencer ao mesmo local.',
                ]);
            }

            if ($venueIds->isNotEmpty()) {
                $laneVenueId = (string) $venueIds->first();

                if ($recurrence->sports_venue_id !== null
                    && (string) $recurrence->sports_venue_id !== $laneVenueId) {
                    throw ValidationException::withMessages([
                        'groups' => 'As pistas não pertencem ao local da recorrência.',
                    ]);
                }

                if ($recurrence->sports_venue_id === null) {
                    /** @var SportsVenueLane $firstLane */
                    $firstLane = $lanes->first();
                    $recurrence->forceFill([
                        'sports_venue_id' => $laneVenueId,
                        'local_snapshot' => $firstLane->venue?->name,
                    ])->save();
                }
            }

            $assignment = TrainingRecurrenceGroup::query()->create([
                'club_id' => $clubId,
                'training_recurrence_id' => $recurrence->id,
                'training_group_id' => $group->id,
                'training_plan_version_id' => $plan?->id,
                'instruction' => $this->nullableText($row['instruction'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
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
        }
    }

    private function matches(TrainingRecurrence $recurrence, CarbonImmutable $date): bool
    {
        $start = CarbonImmutable::parse($recurrence->starts_on)->startOfDay();
        if ($date->lt($start)) {
            return false;
        }

        $weekdays = collect($recurrence->weekdays ?? [])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->values();

        if ($recurrence->frequency === 'daily') {
            $days = (int) $start->diffInDays($date);
            $matchesInterval = $days % max(1, (int) $recurrence->interval) === 0;

            return $matchesInterval
                && ($weekdays->isEmpty() || $weekdays->contains($date->dayOfWeekIso));
        }

        $effectiveWeekdays = $weekdays->isEmpty()
            ? collect([$start->dayOfWeekIso])
            : $weekdays;

        $startWeek = $start->startOfWeek();
        $dateWeek = $date->startOfWeek();
        $weeks = intdiv((int) $startWeek->diffInDays($dateWeek), 7);

        return $weeks % max(1, (int) $recurrence->interval) === 0
            && $effectiveWeekdays->contains($date->dayOfWeekIso);
    }

    private function isReadyForPublication(TrainingRecurrence $recurrence): bool
    {
        $globalContent = $recurrence->training_plan_version_id !== null
            || filled($recurrence->instruction);

        if ($recurrence->groups->isEmpty()) {
            return $globalContent;
        }

        return $recurrence->groups->every(
            fn (TrainingRecurrenceGroup $group): bool => $globalContent
                || $group->training_plan_version_id !== null
                || filled($group->instruction)
        );
    }

    private function venueForClub(mixed $id): ?SportsVenue
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        $venue = SportsVenue::query()
            ->where('club_id', $this->clubContext->id())
            ->where('active', true)
            ->whereKey($id)
            ->first();

        if ($venue === null) {
            throw ValidationException::withMessages([
                'sports_venue_id' => 'O local selecionado não pertence ao clube ativo ou está inativo.',
            ]);
        }

        return $venue;
    }

    private function planForClub(mixed $id): ?TrainingPlanVersion
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        $plan = TrainingPlanVersion::query()
            ->where('club_id', $this->clubContext->id())
            ->whereKey($id)
            ->first();

        if ($plan === null) {
            throw ValidationException::withMessages([
                'training_plan_version_id' => 'A versão do plano não pertence ao clube ativo.',
            ]);
        }

        return $plan;
    }

    private function assertTenant(TrainingRecurrence $recurrence): void
    {
        if ((string) $recurrence->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'recurrence' => 'A recorrência pertence a outro clube.',
            ]);
        }
    }

    /** @param array<int,mixed> $weekdays
     *  @return array<int,int>|null
     */
    private function normalizeWeekdays(array $weekdays): ?array
    {
        $normalized = collect($weekdays)
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
