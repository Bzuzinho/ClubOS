<?php

namespace App\Services\Eventos;

use App\Models\Competition;
use App\Models\Event;
use App\Models\EventType;
use App\Services\Desportivo\DeleteCompetitionRegistrationAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function __construct(
        private readonly DeleteConvocationGroupAction $deleteConvocationGroup,
        private readonly SyncConvocationGroupFinancialMovementAction $syncConvocationFinancialMovement,
        private readonly DeleteCompetitionRegistrationAction $deleteCompetitionRegistration,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $ageGroupIds
     */
    public function create(array $data, array $ageGroupIds): Event
    {
        return DB::transaction(function () use ($data, $ageGroupIds): Event {
            $event = Event::query()->create($data);
            $event->syncAgeGroups($ageGroupIds);
            $this->syncCompetition($event);
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

            $this->deleteRecurringChildren($lockedEvent);

            $lockedEvent->update($data);
            $lockedEvent->syncAgeGroups($ageGroupIds);
            $this->syncCompetition($lockedEvent);

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
            $this->syncCompetition($childEvent);
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

        $this->deleteCompetitionForEvent($event);
        $event->delete();
    }

    private function syncCompetition(Event $event): void
    {
        $competitionType = $this->resolveCompetitionType($event);

        if ($competitionType === null) {
            $this->deleteCompetitionForEvent($event);

            return;
        }

        Competition::query()->updateOrCreate(
            ['evento_id' => $event->id],
            [
                'nome' => $event->titulo,
                'local' => $event->local ?: 'N/A',
                'data_inicio' => $event->data_inicio,
                'data_fim' => $event->data_fim,
                'tipo' => $competitionType,
            ]
        );
    }

    private function resolveCompetitionType(Event $event): ?string
    {
        $normalizedType = $this->normalizeType((string) $event->tipo);
        if (in_array($normalizedType, ['prova', 'competicao'], true)) {
            return $normalizedType;
        }

        $configuredType = EventType::query()
            ->get(['nome', 'categoria'])
            ->first(fn (EventType $type) => $this->normalizeType((string) $type->nome) === $normalizedType);
        $configuredCategory = $this->normalizeType((string) ($configuredType?->categoria ?? ''));

        return in_array($configuredCategory, ['prova', 'competicao'], true)
            ? $configuredCategory
            : null;
    }

    private function normalizeType(string $value): string
    {
        return Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    private function deleteCompetitionForEvent(Event $event): void
    {
        Competition::query()
            ->where('evento_id', $event->id)
            ->lockForUpdate()
            ->get()
            ->each(function (Competition $competition): void {
                $competition->load('provas.registrations');

                $competition->provas
                    ->flatMap(fn ($prova) => $prova->registrations)
                    ->each(fn ($registration) => $this->deleteCompetitionRegistration->execute($registration));

                $competition->provas()->delete();
                $competition->delete();
            });
    }
}
