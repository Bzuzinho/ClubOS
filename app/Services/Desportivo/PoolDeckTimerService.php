<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingPoolDeckTimer;
use App\Models\TrainingPoolDeckTimerEvent;
use App\Models\TrainingSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PoolDeckTimerService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function start(Training $training, array $data, User $actor): TrainingPoolDeckTimer
    {
        $this->assertTrainingOpen($training);

        $validated = validator($data, [
            'subject_type' => 'required|string|in:athlete,subgroup',
            'training_athlete_id' => 'nullable|uuid|exists:training_athletes,id',
            'subject_key' => 'nullable|string|max:128',
            'training_series_id' => 'nullable|uuid|exists:training_series,id',
            'exercise_label' => 'required|string|max:255',
            'repetition_number' => 'nullable|integer|min:1|max:999',
            'client_timer_id' => 'nullable|string|max:128',
            'client_event_id' => 'nullable|string|max:128',
            'occurred_at' => 'nullable|date',
        ])->validate();

        $trainingAthlete = null;
        if ($validated['subject_type'] === 'athlete') {
            if (empty($validated['training_athlete_id'])) {
                throw ValidationException::withMessages([
                    'training_athlete_id' => 'Seleciona o atleta para iniciar o cronómetro.',
                ]);
            }

            $trainingAthlete = TrainingAthlete::query()->findOrFail($validated['training_athlete_id']);
            if ((string) $trainingAthlete->treino_id !== (string) $training->id) {
                throw ValidationException::withMessages([
                    'training_athlete_id' => 'O atleta não pertence a esta sessão.',
                ]);
            }
        }

        if (!empty($validated['training_series_id'])) {
            $series = TrainingSeries::query()->findOrFail($validated['training_series_id']);
            if ((string) $series->treino_id !== (string) $training->id) {
                throw ValidationException::withMessages([
                    'training_series_id' => 'O exercício não pertence a esta sessão.',
                ]);
            }
        }

        $clientTimerId = trim((string) ($validated['client_timer_id'] ?? '')) ?: null;
        if ($clientTimerId !== null) {
            $existing = TrainingPoolDeckTimer::query()
                ->where('club_id', $this->clubContext->id())
                ->where('client_timer_id', $clientTimerId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $occurredAt = !empty($validated['occurred_at'])
            ? CarbonImmutable::parse($validated['occurred_at'])
            : CarbonImmutable::now();
        $subjectKey = $trainingAthlete?->id
            ?? trim((string) ($validated['subject_key'] ?? ''));

        if ($subjectKey === '') {
            throw ValidationException::withMessages([
                'subject_key' => 'O subgrupo precisa de um identificador.',
            ]);
        }

        return DB::transaction(function () use ($training, $validated, $actor, $trainingAthlete, $clientTimerId, $occurredAt, $subjectKey): TrainingPoolDeckTimer {
            $timer = TrainingPoolDeckTimer::query()->create([
                'club_id' => $this->clubContext->id(),
                'training_id' => $training->id,
                'training_athlete_id' => $trainingAthlete?->id,
                'user_id' => $trainingAthlete?->user_id,
                'training_series_id' => $validated['training_series_id'] ?? null,
                'subject_type' => $validated['subject_type'],
                'subject_key' => $subjectKey,
                'exercise_label' => trim((string) $validated['exercise_label']),
                'repetition_number' => $validated['repetition_number'] ?? null,
                'timer_state' => 'running',
                'elapsed_ms' => 0,
                'started_at' => $occurredAt,
                'last_resumed_at' => $occurredAt,
                'client_timer_id' => $clientTimerId,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->appendEvent(
                $timer,
                'start',
                0,
                $occurredAt,
                $validated['client_event_id'] ?? null,
                null,
                $actor,
            );

            return $timer->fresh(['trainingAthlete.athlete', 'series', 'events']);
        });
    }

    /** @param array<string,mixed> $data */
    public function event(TrainingPoolDeckTimer $timer, string $eventType, array $data, User $actor): TrainingPoolDeckTimer
    {
        if (!in_array($eventType, ['pause', 'resume', 'lap', 'stop'], true)) {
            throw ValidationException::withMessages(['event_type' => 'Evento de cronómetro inválido.']);
        }

        $validated = validator($data, [
            'client_event_id' => 'nullable|string|max:128',
            'occurred_at' => 'nullable|date',
            'payload' => 'nullable|array',
        ])->validate();

        $clientEventId = trim((string) ($validated['client_event_id'] ?? '')) ?: null;
        if ($clientEventId !== null) {
            $existingEvent = TrainingPoolDeckTimerEvent::query()
                ->where('club_id', $this->clubContext->id())
                ->where('client_event_id', $clientEventId)
                ->first();

            if ($existingEvent) {
                return $existingEvent->timer()->with(['trainingAthlete.athlete', 'series', 'events'])->firstOrFail();
            }
        }

        $occurredAt = !empty($validated['occurred_at'])
            ? CarbonImmutable::parse($validated['occurred_at'])
            : CarbonImmutable::now();

        return DB::transaction(function () use ($timer, $eventType, $validated, $clientEventId, $actor, $occurredAt): TrainingPoolDeckTimer {
            $locked = TrainingPoolDeckTimer::query()->whereKey($timer->id)->lockForUpdate()->firstOrFail();
            $training = Training::query()->findOrFail($locked->training_id);
            $this->assertTrainingOpen($training, allowClosedForStop: $eventType === 'stop');

            $elapsed = $this->elapsedAt($locked, $occurredAt);

            if ($eventType === 'pause' && $locked->timer_state !== 'running') {
                throw ValidationException::withMessages(['timer' => 'Só um cronómetro em execução pode ser pausado.']);
            }
            if ($eventType === 'resume' && $locked->timer_state !== 'paused') {
                throw ValidationException::withMessages(['timer' => 'Só um cronómetro pausado pode ser retomado.']);
            }
            if ($eventType === 'stop' && $locked->timer_state === 'stopped') {
                return $locked->fresh(['trainingAthlete.athlete', 'series', 'events']);
            }

            $updates = [
                'updated_by' => $actor->id,
                'version' => ((int) $locked->version) + 1,
            ];

            if ($eventType === 'pause') {
                $updates += [
                    'timer_state' => 'paused',
                    'elapsed_ms' => $elapsed,
                    'last_resumed_at' => null,
                ];
            } elseif ($eventType === 'resume') {
                $updates += [
                    'timer_state' => 'running',
                    'last_resumed_at' => $occurredAt,
                ];
            } elseif ($eventType === 'stop') {
                $updates += [
                    'timer_state' => 'stopped',
                    'elapsed_ms' => $elapsed,
                    'last_resumed_at' => null,
                    'stopped_at' => $occurredAt,
                ];
            }

            $locked->forceFill($updates)->save();

            $this->appendEvent(
                $locked,
                $eventType,
                $elapsed,
                $occurredAt,
                $clientEventId,
                $validated['payload'] ?? null,
                $actor,
            );

            return $locked->fresh(['trainingAthlete.athlete', 'series', 'events']);
        });
    }

    private function elapsedAt(TrainingPoolDeckTimer $timer, CarbonImmutable $at): int
    {
        $elapsed = (int) $timer->elapsed_ms;
        if ($timer->timer_state === 'running' && $timer->last_resumed_at) {
            $resumed = CarbonImmutable::parse($timer->last_resumed_at);
            if ($at->gt($resumed)) {
                $elapsed += (int) $resumed->diffInMilliseconds($at);
            }
        }

        return $elapsed;
    }

    private function appendEvent(
        TrainingPoolDeckTimer $timer,
        string $type,
        int $elapsed,
        CarbonImmutable $occurredAt,
        ?string $clientEventId,
        ?array $payload,
        User $actor,
    ): TrainingPoolDeckTimerEvent {
        return TrainingPoolDeckTimerEvent::query()->create([
            'club_id' => $this->clubContext->id(),
            'timer_id' => $timer->id,
            'training_id' => $timer->training_id,
            'event_type' => $type,
            'elapsed_ms' => $elapsed,
            'occurred_at' => $occurredAt,
            'client_event_id' => $clientEventId,
            'payload' => $payload,
            'recorded_by' => $actor->id,
        ]);
    }

    private function assertTrainingOpen(Training $training, bool $allowClosedForStop = false): void
    {
        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training' => 'A sessão pertence a outro clube.']);
        }

        if ($training->isCompleted() && !$allowClosedForStop) {
            throw ValidationException::withMessages(['training' => 'A sessão já está concluída.']);
        }

        if (!$training->isPoolDeckOpen() && !$allowClosedForStop) {
            throw ValidationException::withMessages(['training' => 'A sessão tem de estar aberta no Cais.']);
        }
    }
}