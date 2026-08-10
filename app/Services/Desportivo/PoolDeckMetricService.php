<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Models\TrainingSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PoolDeckMetricService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function record(Training $training, array $data, User $actor): TrainingMetric
    {
        $this->assertTrainingTenant($training);

        if (!$training->isPoolDeckOpen() || $training->isCompleted()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão tem de estar aberta no Cais para registar medições.',
            ]);
        }

        $validated = validator($data, [
            'training_athlete_id' => 'required|uuid|exists:training_athletes,id',
            'training_series_id' => 'required|uuid|exists:training_series,id',
            'measurement_type' => 'required|string|in:time,distance,value,rpe,note',
            'total_distance_m' => 'required|integer|min:1|max:50000',
            'duration_ms' => 'nullable|integer|min:0|max:86400000',
            'value' => 'nullable|string|max:255',
            'splits' => 'nullable|array|max:200',
            'splits.*.distance_m' => 'required_with:splits|integer|min:1|max:50000',
            'splits.*.duration_ms' => 'required_with:splits|integer|min:0|max:86400000',
            'repetition_mode' => 'nullable|string|in:one_off,repetition',
            'repetition_number' => 'nullable|integer|min:1|max:999',
            'observacao' => 'nullable|string|max:5000',
            'client_event_id' => 'nullable|string|max:128',
            'client_recorded_at' => 'nullable|date',
        ])->validate();

        if (($validated['measurement_type'] ?? null) === 'time' && !array_key_exists('duration_ms', $validated)) {
            throw ValidationException::withMessages([
                'duration_ms' => 'O tempo total é obrigatório para uma medição cronometrada.',
            ]);
        }

        if (($validated['repetition_mode'] ?? 'one_off') === 'repetition' && empty($validated['repetition_number'])) {
            throw ValidationException::withMessages([
                'repetition_number' => 'Indica a repetição a que esta medição pertence.',
            ]);
        }

        $record = TrainingAthlete::query()->findOrFail($validated['training_athlete_id']);
        if ((string) $record->treino_id !== (string) $training->id) {
            throw ValidationException::withMessages([
                'training_athlete_id' => 'O atleta não está atribuído a esta sessão.',
            ]);
        }

        $series = TrainingSeries::query()->findOrFail($validated['training_series_id']);
        if ((string) $series->treino_id !== (string) $training->id) {
            throw ValidationException::withMessages([
                'training_series_id' => 'O exercício não pertence a esta sessão.',
            ]);
        }

        $clientEventId = trim((string) ($validated['client_event_id'] ?? '')) ?: null;
        if ($clientEventId !== null) {
            $existing = TrainingMetric::query()
                ->where('club_id', $this->clubContext->id())
                ->where('client_event_id', $clientEventId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $splits = collect($validated['splits'] ?? [])
            ->values()
            ->map(fn (array $split, int $index): array => [
                'index' => $index + 1,
                'distance_m' => (int) $split['distance_m'],
                'duration_ms' => (int) $split['duration_ms'],
            ])
            ->all();

        return DB::transaction(function () use ($training, $record, $series, $validated, $splits, $clientEventId, $actor): TrainingMetric {
            $nextOrder = ((int) TrainingMetric::query()
                ->where('treino_id', $training->id)
                ->where('user_id', $record->user_id)
                ->max('ordem')) + 1;

            $duration = array_key_exists('duration_ms', $validated)
                ? (int) $validated['duration_ms']
                : null;

            return TrainingMetric::query()->create([
                'treino_id' => $training->id,
                'training_athlete_id' => $record->id,
                'user_id' => $record->user_id,
                'ordem' => $nextOrder,
                'metrica' => $validated['measurement_type'],
                'valor' => $validated['value'] ?? (string) $validated['total_distance_m'],
                'tempo' => $duration !== null ? $this->formatDuration($duration) : null,
                'recorded_at' => $validated['client_recorded_at'] ?? now(),
                'observacao' => $validated['observacao'] ?? null,
                'registado_por' => $actor->id,
                'club_id' => $this->clubContext->id(),
                'training_series_id' => $series->id,
                'measurement_type' => $validated['measurement_type'],
                'total_distance_m' => (int) $validated['total_distance_m'],
                'repetition_mode' => $validated['repetition_mode'] ?? 'one_off',
                'repetition_number' => $validated['repetition_number'] ?? null,
                'duration_ms' => $duration,
                'splits_json' => $splits,
                'source' => 'pool_deck',
                'client_event_id' => $clientEventId,
                'client_recorded_at' => $validated['client_recorded_at'] ?? null,
                'captured_by' => $actor->id,
                'server_version' => 1,
            ]);
        });
    }

    private function formatDuration(int $milliseconds): string
    {
        $minutes = intdiv($milliseconds, 60000);
        $remaining = $milliseconds % 60000;
        $seconds = intdiv($remaining, 1000);
        $ms = $remaining % 1000;

        return sprintf('%02d:%02d.%03d', $minutes, $seconds, $ms);
    }

    private function assertTrainingTenant(Training $training): void
    {
        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão de treino pertence a outro clube.',
            ]);
        }
    }
}
