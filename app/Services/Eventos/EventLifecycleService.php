<?php

namespace App\Services\Eventos;

use App\Models\CompetitionEventProjection;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EventLifecycleService
{
    private const FINANCIAL_EVENT_FIELDS = [
        'titulo',
        'data_inicio',
        'tipo',
        'centro_custo_id',
        'taxa_inscricao',
        'custo_inscricao_por_prova',
        'custo_inscricao_por_salto',
        'custo_inscricao_estafeta',
    ];

    private const COMPETITION_PROJECTED_FIELDS = [
        'titulo',
        'data_inicio',
        'data_fim',
        'local',
        'tipo',
        'recorrente',
        'recorrencia_data_inicio',
        'recorrencia_data_fim',
        'recorrencia_dias_semana',
        'evento_pai_id',
    ];

    public function __construct(
        private readonly DeleteConvocationGroupAction $deleteConvocationGroup,
        private readonly SyncConvocationGroupFinancialMovementAction $syncConvocationFinancialMovement,
    ) {
    }

    /**
     * Eventos creates event-domain records only. A competition-category event is
     * never promoted into a Competition master by this service.
     *
     * @param array<string, mixed> $data
     * @param list<string> $ageGroupIds
     */
    public function create(array $data, array $ageGroupIds): Event
    {
        return DB::transaction(function () use ($data, $ageGroupIds): Event {
            $event = Event::query()->create($data);
            $event->syncAgeGroups($ageGroupIds);
            $this->generateRecurringChildren($event, $data, $ageGroupIds);

            return $event->fresh(['ageGroups']);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $ageGroupIds
     */
    public function update(Event $event, array $data, array $ageGroupIds): Event
    {
        return DB::transaction(function () use ($event, $data, $ageGroupIds): Event {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
            $data = $this->preserveCompetitionOwnedProjectionFields($lockedEvent, $data);

            $this->deleteRecurringChildren($lockedEvent);

            $lockedEvent->update($data);
            $lockedEvent->syncAgeGroups($ageGroupIds);

            if ($lockedEvent->wasChanged(self::FINANCIAL_EVENT_FIELDS)) {
                $lockedEvent->convocationGroups()
                    ->get()
                    ->each(fn ($group) => $this->syncConvocationFinancialMovement->execute($group));
            }

            $this->generateRecurringChildren($lockedEvent, $data, $ageGroupIds);

            return $lockedEvent->fresh(['ageGroups']);
        });
    }

    public function delete(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);

            $this->deleteRecurringChildren($lockedEvent);
            $this->deleteSingleEvent($lockedEvent);
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $ageGroupIds
     */
    private function generateRecurringChildren(Event $parentEvent, array $data, array $ageGroupIds): void
    {
        if (! (bool) ($data['recorrente'] ?? false)) {
            return;
        }

        $selectedDays = collect($data['recorrencia_dias_semana'] ?? [])
            ->map(fn ($day) => (string) $day)
            ->filter(fn (string $day) => in_array($day, ['0', '1', '2', '3', '4', '5', '6'], true))
            ->unique()
            ->values();

        if ($selectedDays->isEmpty()) {
            return;
        }

        $recurrenceStart = Carbon::parse($data['recorrencia_data_inicio'])->startOfDay();
        $recurrenceEnd = Carbon::parse($data['recorrencia_data_fim'])->startOfDay();
        $parentStart = Carbon::parse($data['data_inicio'])->startOfDay();
        $durationDays = filled($data['data_fim'] ?? null)
            ? $parentStart->diffInDays(Carbon::parse($data['data_fim'])->startOfDay())
            : null;

        for ($date = $recurrenceStart->copy(); $date->lte($recurrenceEnd); $date->addDay()) {
            if (! $selectedDays->contains((string) $date->dayOfWeek) || $date->isSameDay($parentStart)) {
                continue;
            }

            $childData = $data;
            $childData['data_inicio'] = $date->toDateString();
            $childData['data_fim'] = $durationDays === null
                ? null
                : $date->copy()->addDays($durationDays)->toDateString();
            $childData['recorrente'] = false;
            $childData['recorrencia_data_inicio'] = null;
            $childData['recorrencia_data_fim'] = null;
            $childData['recorrencia_dias_semana'] = null;
            $childData['evento_pai_id'] = $parentEvent->id;

            $childEvent = Event::query()->create($childData);
            $childEvent->syncAgeGroups($ageGroupIds);
        }
    }

    private function deleteRecurringChildren(Event $parentEvent): void
    {
        $parentEvent->childEvents()
            ->lockForUpdate()
            ->get()
            ->each(fn (Event $childEvent) => $this->deleteSingleEvent($childEvent));
    }

    private function deleteSingleEvent(Event $event): void
    {
        $event->convocationGroups()
            ->lockForUpdate()
            ->get()
            ->each(fn ($group) => $this->deleteConvocationGroup->execute($group));

        $this->detachCompetitionProjection($event);
        $event->delete();
    }

    /** @param array<string,mixed> $data */
    private function preserveCompetitionOwnedProjectionFields(Event $event, array $data): array
    {
        $isCompetitionProjection = CompetitionEventProjection::query()
            ->where('event_id', $event->id)
            ->where('status', 'linked')
            ->exists();

        if (! $isCompetitionProjection) {
            return $data;
        }

        foreach (self::COMPETITION_PROJECTED_FIELDS as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    private function detachCompetitionProjection(Event $event): void
    {
        $projection = CompetitionEventProjection::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->first();

        if (! $projection) {
            return;
        }

        $projection->event_id = null;
        $projection->legacy_event_id ??= $event->id;
        $projection->status = 'detached';
        $projection->manual_review_reason = 'event_deleted_from_events';
        $projection->projected_at = null;
        $projection->save();
    }
}
