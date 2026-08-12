<?php

namespace App\Services\Desportivo;

use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\Season;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsVenue;
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
    ) {}

    public function create(array $data, User $actor): TrainingRecurrence
    {
        $data = $this->validated($data);
        $clubId = $this->clubContext->id();
        [$venue, $pool] = $this->resolveLocation($data);
        $plan = $this->planForClub($data['training_plan_version_id'] ?? null);
        $this->assertPlanningContext($data);

        return DB::transaction(function () use ($data, $actor, $clubId, $venue, $pool, $plan): TrainingRecurrence {
            $recurrence = TrainingRecurrence::query()->create([
                'club_id' => $clubId,
                'name' => trim((string) $data['name']),
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null,
                'frequency' => $data['frequency'], 'interval' => (int) ($data['interval'] ?? 1),
                'weekdays' => $this->normalizeWeekdays($data['weekdays'] ?? []),
                'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
                'season_id' => $data['season_id'] ?? null, 'macrocycle_id' => $data['macrocycle_id'] ?? null,
                'mesocycle_id' => $data['mesocycle_id'] ?? null, 'microcycle_id' => $data['microcycle_id'] ?? null,
                'sports_venue_id' => $venue?->id, 'sports_pool_id' => $pool?->id,
                'local_snapshot' => $venue?->name ?? $this->nullableText($data['local_snapshot'] ?? null),
                'responsavel_id' => $data['responsavel_id'] ?? null, 'training_plan_version_id' => $plan?->id,
                'instruction' => $this->nullableText($data['instruction'] ?? null),
                'training_type' => $this->nullableText($data['training_type'] ?? null),
                'session_status_template' => $data['session_status_template'] ?? 'draft',
                'active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->replaceGroups($recurrence, $data['groups'] ?? []);
            return $recurrence->fresh($this->relations());
        }, 3);
    }

    public function update(TrainingRecurrence $recurrence, array $data, User $actor): TrainingRecurrence
    {
        $this->assertTenant($recurrence);
        $data = $this->validated($data, partial: true, existing: $recurrence);
        $merged = array_merge($recurrence->only([
            'name','starts_on','ends_on','frequency','interval','weekdays','start_time','end_time','season_id','macrocycle_id','mesocycle_id','microcycle_id',
            'sports_venue_id','sports_pool_id','responsavel_id','training_plan_version_id','instruction','training_type','session_status_template'
        ]), $data);
        $this->assertPlanningContext($merged);
        [$venue, $pool] = $this->resolveLocation($merged);
        $plan = $this->planForClub($merged['training_plan_version_id'] ?? null);

        return DB::transaction(function () use ($recurrence, $data, $merged, $venue, $pool, $plan, $actor): TrainingRecurrence {
            $recurrence->fill([
                'name' => trim((string) $merged['name']), 'starts_on' => $merged['starts_on'], 'ends_on' => $merged['ends_on'] ?? null,
                'frequency' => $merged['frequency'], 'interval' => (int) ($merged['interval'] ?? 1),
                'weekdays' => $this->normalizeWeekdays($merged['weekdays'] ?? []), 'start_time' => $merged['start_time'], 'end_time' => $merged['end_time'],
                'season_id' => $merged['season_id'] ?? null, 'macrocycle_id' => $merged['macrocycle_id'] ?? null,
                'mesocycle_id' => $merged['mesocycle_id'] ?? null, 'microcycle_id' => $merged['microcycle_id'] ?? null,
                'sports_venue_id' => $venue?->id, 'sports_pool_id' => $pool?->id,
                'local_snapshot' => $venue?->name ?? $this->nullableText($merged['local_snapshot'] ?? null),
                'responsavel_id' => $merged['responsavel_id'] ?? null, 'training_plan_version_id' => $plan?->id,
                'instruction' => $this->nullableText($merged['instruction'] ?? null),
                'training_type' => $this->nullableText($merged['training_type'] ?? null),
                'session_status_template' => $merged['session_status_template'] ?? 'draft', 'updated_by' => $actor->id,
            ])->save();
            if (array_key_exists('groups', $data)) $this->replaceGroups($recurrence, $data['groups'] ?? []);
            return $recurrence->refresh()->load($this->relations());
        }, 3);
    }

    public function archive(TrainingRecurrence $recurrence, User $actor): void
    {
        $this->assertTenant($recurrence);
        $recurrence->forceFill(['active' => false, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
    }

    public function generateUntil(TrainingRecurrence $recurrence, mixed $until, User $actor): array
    {
        $this->assertTenant($recurrence);
        if (! $recurrence->active) throw ValidationException::withMessages(['recurrence' => 'A recorrência está inativa.']);

        $start = CarbonImmutable::parse($recurrence->starts_on)->startOfDay();
        $requestedEnd = CarbonImmutable::parse($until)->startOfDay();
        $end = $recurrence->ends_on ? min($requestedEnd, CarbonImmutable::parse($recurrence->ends_on)->startOfDay()) : $requestedEnd;
        if ($end->lt($start)) return ['created' => [], 'skipped' => [], 'blocked' => []];
        if ((int) $start->diffInDays($end) > 730) throw ValidationException::withMessages(['until' => 'A geração de recorrência está limitada a 730 dias por operação.']);

        $recurrence->loadMissing($this->relations());
        $created = []; $skipped = []; $blocked = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (! $this->matches($recurrence, $date)) continue;
            $key = $date->format('Y-m-d');
            if (Training::query()->where('club_id', $this->clubContext->id())->where('training_recurrence_id', $recurrence->id)->where('recurrence_occurrence_key', $key)->exists()) {
                $skipped[] = $key; continue;
            }

            $groups = $recurrence->groups->map(fn (TrainingRecurrenceGroup $group): array => [
                'training_group_id' => (string) $group->training_group_id,
                'training_plan_version_id' => $group->training_plan_version_id ? (string) $group->training_plan_version_id : null,
                'instruction' => $group->instruction, 'sort_order' => $group->sort_order,
                'lanes' => $group->lanes->map(fn (SportsPoolLane $lane): array => [
                    'lane_id' => (string) $lane->id, 'planned_capacity' => $lane->pivot?->planned_capacity,
                ])->values()->all(),
            ])->values()->all();

            $ready = $this->isReadyForPublication($recurrence);
            $status = $recurrence->session_status_template === 'published' && $ready ? 'published' : 'draft';
            try {
                $created[] = $this->createTrainingAction->execute([
                    'data' => $key, 'hora_inicio' => substr((string) $recurrence->start_time, 0, 5), 'hora_fim' => substr((string) $recurrence->end_time, 0, 5),
                    'epoca_id' => $recurrence->season_id, 'macrocycle_id' => $recurrence->macrocycle_id,
                    'mesociclo_id' => $recurrence->mesocycle_id, 'microciclo_id' => $recurrence->microcycle_id,
                    'sports_venue_id' => $recurrence->sports_venue_id, 'sports_pool_id' => $recurrence->sports_pool_id,
                    'local' => $recurrence->local_snapshot, 'training_plan_version_id' => $recurrence->training_plan_version_id,
                    'responsavel_id' => $recurrence->responsavel_id, 'tipo_treino' => $recurrence->training_type,
                    'instrucao' => $recurrence->instruction, 'session_status' => $status, 'training_groups' => $groups,
                    'training_recurrence_id' => $recurrence->id, 'recurrence_occurrence_key' => $key,
                ], $actor);
            } catch (ValidationException $exception) {
                $blocked[] = ['date' => $key, 'errors' => $exception->errors()];
            }
        }
        $recurrence->forceFill(['last_generated_until' => $end->toDateString(), 'updated_by' => $actor->id])->save();
        return ['created' => $created, 'skipped' => $skipped, 'blocked' => $blocked];
    }

    private function validated(array $data, bool $partial = false, ?TrainingRecurrence $existing = null): array
    {
        $required = $partial ? 'sometimes|required' : 'required';
        $rules = [
            'name' => "$required|string|max:255", 'starts_on' => "$required|date", 'ends_on' => 'nullable|date',
            'frequency' => "$required|in:daily,weekly", 'interval' => 'nullable|integer|min:1|max:52',
            'weekdays' => 'nullable|array', 'weekdays.*' => 'integer|min:1|max:7', 'start_time' => "$required|date_format:H:i", 'end_time' => "$required|date_format:H:i",
            'season_id' => 'nullable|uuid|exists:seasons,id', 'macrocycle_id' => 'nullable|uuid|exists:macrocycles,id',
            'mesocycle_id' => 'nullable|uuid|exists:mesocycles,id', 'microcycle_id' => 'nullable|uuid|exists:microcycles,id',
            'sports_venue_id' => 'nullable|uuid|exists:sports_venues,id', 'sports_pool_id' => 'nullable|uuid|exists:sports_pools,id',
            'responsavel_id' => 'nullable|uuid|exists:users,id', 'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'instruction' => 'nullable|string', 'training_type' => 'nullable|string|max:100', 'session_status_template' => 'nullable|in:draft,published',
            'groups' => 'nullable|array', 'groups.*.training_group_id' => 'required|uuid|exists:training_groups,id',
            'groups.*.training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id', 'groups.*.instruction' => 'nullable|string',
            'groups.*.lanes' => 'nullable|array', 'groups.*.lanes.*.lane_id' => 'required|uuid|exists:sports_pool_lanes,id',
            'groups.*.lanes.*.planned_capacity' => 'nullable|integer|min:1|max:999',
        ];
        $validator = validator($data, $rules);
        if ($validator->fails()) throw new ValidationException($validator);
        $valid = $validator->validated();
        $starts = $valid['starts_on'] ?? $existing?->starts_on?->toDateString();
        $ends = array_key_exists('ends_on', $valid) ? $valid['ends_on'] : $existing?->ends_on?->toDateString();
        if ($ends && $starts && $ends < $starts) throw ValidationException::withMessages(['ends_on' => 'A data de fim não pode ser anterior ao início.']);
        $startTime = $valid['start_time'] ?? substr((string) $existing?->start_time, 0, 5);
        $endTime = $valid['end_time'] ?? substr((string) $existing?->end_time, 0, 5);
        if ($startTime && $endTime && $endTime <= $startTime) throw ValidationException::withMessages(['end_time' => 'A hora de fim tem de ser posterior à hora de início.']);
        return $valid;
    }

    private function assertPlanningContext(array $data): void
    {
        $club = $this->clubContext->id();
        $season = ! empty($data['season_id']) ? Season::query()->where('club_id', $club)->find($data['season_id']) : null;
        if (! empty($data['season_id']) && ! $season) throw ValidationException::withMessages(['season_id' => 'Época inválida para o clube ativo.']);
        $macro = ! empty($data['macrocycle_id']) ? Macrocycle::query()->where('club_id', $club)->find($data['macrocycle_id']) : null;
        if (! empty($data['macrocycle_id']) && (! $macro || ($season && (string) $macro->epoca_id !== (string) $season->id))) throw ValidationException::withMessages(['macrocycle_id' => 'Macrociclo fora da época selecionada.']);
        $meso = ! empty($data['mesocycle_id']) ? Mesocycle::query()->where('club_id', $club)->find($data['mesocycle_id']) : null;
        if (! empty($data['mesocycle_id']) && (! $meso || ($macro && (string) $meso->macrociclo_id !== (string) $macro->id))) throw ValidationException::withMessages(['mesocycle_id' => 'Mesociclo fora do macrociclo selecionado.']);
        $micro = ! empty($data['microcycle_id']) ? Microcycle::query()->where('club_id', $club)->find($data['microcycle_id']) : null;
        if (! empty($data['microcycle_id']) && (! $micro || ($meso && (string) $micro->mesociclo_id !== (string) $meso->id))) throw ValidationException::withMessages(['microcycle_id' => 'Microciclo fora do mesociclo selecionado.']);
    }

    private function resolveLocation(array $data): array
    {
        $venue = $this->venueForClub($data['sports_venue_id'] ?? null);
        $pool = $this->poolForClub($data['sports_pool_id'] ?? null);
        if ($pool) {
            $pool->loadMissing('venue');
            if (! $pool->venue?->active) throw ValidationException::withMessages(['sports_pool_id' => 'O local da piscina/área está inativo.']);
            if ($venue && (string) $pool->sports_venue_id !== (string) $venue->id) throw ValidationException::withMessages(['sports_pool_id' => 'A piscina/área não pertence ao local selecionado.']);
            $venue ??= $pool->venue;
        }
        return [$venue, $pool];
    }

    private function replaceGroups(TrainingRecurrence $recurrence, array $groups): void
    {
        $clubId = $this->clubContext->id();
        $recurrence->groups()->delete(); $seen = [];
        foreach (array_values($groups) as $index => $row) {
            $groupId = trim((string) ($row['training_group_id'] ?? ''));
            if (in_array($groupId, $seen, true)) throw ValidationException::withMessages(['groups' => 'O mesmo grupo não pode ser repetido na mesma recorrência.']);
            $seen[] = $groupId;
            $group = TrainingGroup::query()->where('club_id', $clubId)->where('active', true)->whereKey($groupId)->first();
            if (! $group) throw ValidationException::withMessages(['groups' => 'Existe pelo menos um grupo inválido ou inativo.']);
            $plan = $this->planForClub($row['training_plan_version_id'] ?? null);

            $laneRows = collect($row['lanes'] ?? [])->values();
            $laneIds = $laneRows->map(fn (array $lane): string => trim((string) ($lane['lane_id'] ?? '')))->filter()->unique()->values();
            $lanes = $laneIds->isEmpty() ? collect() : SportsPoolLane::query()->with('pool.venue')->where('club_id', $clubId)->where('active', true)
                ->whereIn('id', $laneIds->all())->get()->keyBy(fn (SportsPoolLane $lane): string => (string) $lane->id);
            if ($lanes->count() !== $laneIds->count()) throw ValidationException::withMessages(['groups' => 'Existe pelo menos uma pista canónica inválida ou inativa.']);
            $poolIds = $lanes->pluck('sports_pool_id')->map('strval')->unique()->values();
            if ($poolIds->count() > 1) throw ValidationException::withMessages(['groups' => 'As pistas de uma recorrência têm de pertencer à mesma piscina/área.']);
            if ($poolIds->isNotEmpty()) {
                /** @var SportsPoolLane $first */ $first = $lanes->first(); $pool = $first->pool; $venue = $pool?->venue;
                if (! $pool || ! $venue || ! $pool->active || ! $venue->active) throw ValidationException::withMessages(['groups' => 'A piscina/área ou local selecionado está inativo.']);
                if ($recurrence->sports_pool_id && (string) $recurrence->sports_pool_id !== (string) $pool->id) throw ValidationException::withMessages(['groups' => 'As pistas não pertencem à piscina/área da recorrência.']);
                if ($recurrence->sports_venue_id && (string) $recurrence->sports_venue_id !== (string) $venue->id) throw ValidationException::withMessages(['groups' => 'As pistas não pertencem ao local da recorrência.']);
                if (! $recurrence->sports_pool_id) $recurrence->forceFill(['sports_pool_id' => $pool->id, 'sports_venue_id' => $venue->id, 'local_snapshot' => $venue->name])->save();
            }

            $assignment = TrainingRecurrenceGroup::query()->create([
                'club_id' => $clubId, 'training_recurrence_id' => $recurrence->id, 'training_group_id' => $group->id,
                'training_plan_version_id' => $plan?->id, 'instruction' => $this->nullableText($row['instruction'] ?? null), 'sort_order' => (int) ($row['sort_order'] ?? $index),
            ]);
            $pivot = [];
            foreach ($laneRows as $laneRow) {
                $laneId = trim((string) ($laneRow['lane_id'] ?? '')); if ($laneId === '' || ! $lanes->has($laneId)) continue;
                $planned = $laneRow['planned_capacity'] ?? null;
                $pivot[$laneId] = ['club_id' => $clubId, 'planned_capacity' => $planned === null ? null : max(1, (int) $planned)];
            }
            if ($pivot !== []) $assignment->lanes()->sync($pivot);
        }
    }

    private function matches(TrainingRecurrence $recurrence, CarbonImmutable $date): bool
    {
        $start = CarbonImmutable::parse($recurrence->starts_on)->startOfDay(); if ($date->lt($start)) return false;
        $weekdays = collect($recurrence->weekdays ?? [])->map(fn ($day): int => (int) $day)->filter(fn (int $day): bool => $day >= 1 && $day <= 7)->unique()->values();
        if ($recurrence->frequency === 'daily') {
            $days = (int) $start->diffInDays($date);
            return $days % max(1, (int) $recurrence->interval) === 0 && ($weekdays->isEmpty() || $weekdays->contains($date->dayOfWeekIso));
        }
        $effective = $weekdays->isEmpty() ? collect([$start->dayOfWeekIso]) : $weekdays;
        $weeks = intdiv((int) $start->startOfWeek()->diffInDays($date->startOfWeek()), 7);
        return $weeks % max(1, (int) $recurrence->interval) === 0 && $effective->contains($date->dayOfWeekIso);
    }

    private function isReadyForPublication(TrainingRecurrence $recurrence): bool
    {
        $global = $recurrence->training_plan_version_id !== null || filled($recurrence->instruction);
        return $recurrence->groups->isEmpty() ? $global : $recurrence->groups->every(fn (TrainingRecurrenceGroup $group): bool => $global || $group->training_plan_version_id !== null || filled($group->instruction));
    }

    private function venueForClub(mixed $id): ?SportsVenue
    {
        $id = trim((string) $id); if ($id === '') return null;
        $venue = SportsVenue::query()->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($id)->first();
        if (! $venue) throw ValidationException::withMessages(['sports_venue_id' => 'O local selecionado não pertence ao clube ativo ou está inativo.']);
        return $venue;
    }

    private function poolForClub(mixed $id): ?SportsPool
    {
        $id = trim((string) $id); if ($id === '') return null;
        $pool = SportsPool::query()->where('club_id', $this->clubContext->id())->where('active', true)->whereKey($id)->first();
        if (! $pool) throw ValidationException::withMessages(['sports_pool_id' => 'A piscina/área selecionada não pertence ao clube ativo ou está inativa.']);
        return $pool;
    }

    private function planForClub(mixed $id): ?TrainingPlanVersion
    {
        $id = trim((string) $id); if ($id === '') return null;
        $plan = TrainingPlanVersion::query()->where('club_id', $this->clubContext->id())->whereKey($id)->first();
        if (! $plan) throw ValidationException::withMessages(['training_plan_version_id' => 'A versão do plano pertence a outro clube.']);
        return $plan;
    }

    private function normalizeWeekdays(array $weekdays): array { return collect($weekdays)->map(fn ($day): int => (int) $day)->filter(fn (int $day): bool => $day >= 1 && $day <= 7)->unique()->sort()->values()->all(); }
    private function nullableText(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function assertTenant(TrainingRecurrence $recurrence): void { if ((string) $recurrence->club_id !== $this->clubContext->id()) throw ValidationException::withMessages(['recurrence' => 'A recorrência pertence a outro clube.']); }
    private function relations(): array { return ['season','macrocycle','mesocycle','microcycle','venue','pool','planVersion','groups.group','groups.planVersion','groups.lanes.pool.venue']; }
}
