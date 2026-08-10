<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingPoolDeckSyncConflict;
use App\Models\TrainingPoolDeckTimer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PoolDeckSessionService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function open(Training $training, User $actor): Training
    {
        return DB::transaction(function () use ($training, $actor): Training {
            $locked = Training::query()->whereKey($training->id)->lockForUpdate()->firstOrFail();
            $this->assertTrainingTenant($locked);

            if ($locked->isCompleted() || $locked->pool_deck_status === 'closed') {
                throw ValidationException::withMessages([
                    'training' => 'Uma sessão concluída não pode ser reaberta no Cais.',
                ]);
            }

            if ($locked->pool_deck_status !== 'open') {
                TrainingAthlete::query()
                    ->where('treino_id', $locked->id)
                    ->whereNull('atualizado_por_utilizador_em')
                    ->where(function ($query): void {
                        $query->whereNull('cais_status_source')->orWhere('cais_status_source', 'planning');
                    })
                    ->update([
                        'presente' => true,
                        'estado' => 'presente',
                        'cais_status_source' => 'pool_deck_default',
                        'cais_last_modified_at' => now(),
                        'cais_last_modified_by' => $actor->id,
                        'cais_version' => DB::raw('cais_version + 1'),
                        'atualizado_por' => $actor->id,
                    ]);

                $locked->forceFill([
                    'pool_deck_status' => 'open',
                    'pool_deck_opened_at' => now(),
                    'pool_deck_opened_by' => $actor->id,
                    'pool_deck_version' => ((int) $locked->pool_deck_version) + 1,
                ])->save();
            }

            return $locked->fresh([
                'athleteRecords.athlete',
                'series',
                'sessionGroups.group',
                'sessionGroups.lanes',
            ]);
        });
    }

    /** @param array<string,mixed> $data */
    public function updateAthlete(
        TrainingAthlete $record,
        array $data,
        User $actor,
    ): TrainingAthlete {
        $training = $record->training()->firstOrFail();
        $this->assertTrainingTenant($training);

        if (!$training->isPoolDeckOpen()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão tem de estar aberta no Cais para registar execução.',
            ]);
        }

        if ($training->isCompleted()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão já está concluída.',
            ]);
        }

        $validated = validator($data, [
            'estado' => 'sometimes|string|in:presente,ausente,justificado,lesionado,limitado,doente,dispensado',
            'volume_real_m' => 'sometimes|nullable|integer|min:0|max:50000',
            'rpe' => 'sometimes|nullable|integer|min:1|max:10',
            'observacoes_tecnicas' => 'sometimes|nullable|string|max:5000',
            'client_version' => 'sometimes|nullable|integer|min:0',
            'client_modified_at' => 'sometimes|nullable|date',
            'client_event_id' => 'sometimes|nullable|string|max:128',
        ])->validate();

        return DB::transaction(function () use ($record, $validated, $actor, $training): TrainingAthlete {
            $locked = TrainingAthlete::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $serverVersion = (int) $locked->cais_version;
            $clientVersion = array_key_exists('client_version', $validated)
                ? (int) ($validated['client_version'] ?? 0)
                : $serverVersion;
            $clientTime = !empty($validated['client_modified_at'])
                ? CarbonImmutable::parse($validated['client_modified_at'])
                : null;
            $serverTime = $locked->cais_last_modified_at
                ? CarbonImmutable::parse($locked->cais_last_modified_at)
                : null;

            $fields = collect($validated)
                ->only(['estado', 'volume_real_m', 'rpe', 'observacoes_tecnicas'])
                ->all();

            $stale = $clientVersion < $serverVersion
                && $clientTime !== null
                && $serverTime !== null
                && $clientTime->lt($serverTime);

            if ($stale) {
                foreach ($fields as $field => $value) {
                    if ($locked->getAttribute($field) == $value) {
                        continue;
                    }

                    TrainingPoolDeckSyncConflict::query()->create([
                        'club_id' => $this->clubContext->id(),
                        'training_id' => $training->id,
                        'entity_type' => 'training_athlete',
                        'entity_id' => $locked->id,
                        'field' => $field,
                        'client_value' => ['value' => $value],
                        'server_value' => ['value' => $locked->getAttribute($field)],
                        'client_version' => $clientVersion,
                        'server_version' => $serverVersion,
                        'client_event_id' => $validated['client_event_id'] ?? null,
                        'recorded_by' => $actor->id,
                    ]);
                }

                return $locked->fresh();
            }

            if (array_key_exists('estado', $fields)) {
                $fields['presente'] = in_array($fields['estado'], ['presente', 'limitado'], true);
            }

            $locked->forceFill($fields + [
                'cais_status_source' => 'pool_deck',
                'cais_last_modified_at' => $clientTime ?? now(),
                'cais_last_modified_by' => $actor->id,
                'cais_version' => $serverVersion + 1,
                'atualizado_por' => $actor->id,
                'atualizado_por_utilizador_em' => now(),
            ])->save();

            return $locked->fresh(['athlete']);
        });
    }

    public function close(Training $training, User $actor): Training
    {
        return DB::transaction(function () use ($training, $actor): Training {
            $locked = Training::query()->whereKey($training->id)->lockForUpdate()->firstOrFail();
            $this->assertTrainingTenant($locked);

            $activeTimers = TrainingPoolDeckTimer::query()
                ->where('club_id', $this->clubContext->id())
                ->where('training_id', $locked->id)
                ->whereIn('timer_state', ['running', 'paused'])
                ->count();

            if ($activeTimers > 0) {
                throw ValidationException::withMessages([
                    'timers' => "Existem {$activeTimers} cronómetros ativos ou pausados. Termina-os antes de fechar a sessão.",
                ]);
            }

            if ($locked->pool_deck_status !== 'open') {
                throw ValidationException::withMessages([
                    'training' => 'A sessão não está aberta no Cais.',
                ]);
            }

            $locked->forceFill([
                'pool_deck_status' => 'closed',
                'pool_deck_closed_at' => now(),
                'pool_deck_closed_by' => $actor->id,
                'pool_deck_version' => ((int) $locked->pool_deck_version) + 1,
                'session_status' => 'completed',
                'completed_at' => $locked->completed_at ?? now(),
            ])->save();

            return $locked->fresh(['athleteRecords.athlete', 'series']);
        });
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