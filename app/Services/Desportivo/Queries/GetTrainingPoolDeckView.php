<?php

namespace App\Services\Desportivo\Queries;

use App\Models\Training;
use App\Services\Desportivo\SportsClubContext;
use App\Support\LegacySportsGuard;
use Illuminate\Validation\ValidationException;

class GetTrainingPoolDeckView
{
    public function __construct(
        private LegacySportsGuard $legacySportsGuard,
        private SportsClubContext $clubContext,
    ) {
    }

    public function __invoke(string $trainingId): array
    {
        $this->legacySportsGuard->assertTableAllowed('trainings', self::class);

        $training = Training::query()
            ->with([
                'venue',
                'season',
                'macrocycle',
                'mesocycle',
                'microcycle',
                'series',
                'sessionGroups.group',
                'sessionGroups.lanes',
                'athleteRecords.athlete',
                'metrics.trainingAthlete',
                'metrics.series',
                'poolDeckTimers.trainingAthlete.athlete',
                'poolDeckTimers.series',
                'scheduleExceptions',
                'poolDeckSyncConflicts' => fn ($query) => $query->whereNull('resolved_at'),
            ])
            ->where('club_id', $this->clubContext->id())
            ->findOrFail($trainingId);

        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages(['training' => 'A sessão pertence a outro clube.']);
        }

        $metricsByRecord = $training->metrics
            ->groupBy(fn ($metric) => (string) ($metric->training_athlete_id ?: $metric->user_id));
        $timersByRecord = $training->poolDeckTimers
            ->groupBy(fn ($timer) => (string) ($timer->training_athlete_id ?: $timer->subject_key));

        return [
            'training' => [
                'id' => $training->id,
                'numero_treino' => $training->numero_treino,
                'data' => optional($training->data)->format('Y-m-d'),
                'hora_inicio' => $training->hora_inicio,
                'hora_fim' => $training->hora_fim,
                'tipo_treino' => $training->tipo_treino,
                'descricao_treino' => $training->descricao_treino,
                'instrucao' => $training->instrucao,
                'volume_planeado_m' => $training->volume_planeado_m,
                'local' => $training->venue?->name ?? $training->local,
                'sports_venue_id' => $training->sports_venue_id,
                'session_status' => $training->session_status,
                'pool_deck_status' => $training->pool_deck_status,
                'pool_deck_version' => $training->pool_deck_version,
                'pool_deck_opened_at' => $training->pool_deck_opened_at,
                'pool_deck_closed_at' => $training->pool_deck_closed_at,
                'schedule_review_required' => (bool) $training->schedule_review_required,
                'schedule_conflicts' => $training->schedule_conflicts_snapshot ?? [],
                'series' => $training->series->map(fn ($line) => [
                    'id' => $line->id,
                    'ordem' => $line->ordem,
                    'bloco' => $line->bloco,
                    'descricao_texto' => $line->descricao_texto,
                    'repeticoes' => $line->repeticoes,
                    'distancia_m' => $line->distancia_m,
                    'distancia_total_m' => $line->distancia_total_m,
                    'estilo' => $line->estilo,
                    'zona_intensidade' => $line->zona_intensidade,
                    'intervalo' => $line->intervalo,
                    'saida' => $line->saida,
                    'observacoes' => $line->observacoes,
                ])->values(),
                'groups' => $training->sessionGroups->map(fn ($sessionGroup) => [
                    'id' => $sessionGroup->id,
                    'training_group_id' => $sessionGroup->training_group_id,
                    'name' => $sessionGroup->group?->name,
                    'instruction' => $sessionGroup->instruction,
                    'lanes' => $sessionGroup->lanes->map(fn ($lane) => [
                        'id' => $lane->id,
                        'name' => $lane->name,
                        'number' => $lane->number,
                        'capacity' => $lane->capacity,
                        'planned_capacity' => $lane->pivot?->planned_capacity,
                    ])->values(),
                ])->values(),
                'schedule_exceptions' => $training->scheduleExceptions->map(fn ($exception) => [
                    'id' => $exception->id,
                    'type' => $exception->exception_type,
                    'before' => $exception->before_state,
                    'after' => $exception->after_state,
                    'reason' => $exception->reason,
                    'recorded_at' => $exception->recorded_at,
                    'recorded_by' => $exception->recorded_by,
                ])->values(),
                'sync_conflicts' => $training->poolDeckSyncConflicts->map(fn ($conflict) => [
                    'id' => $conflict->id,
                    'entity_type' => $conflict->entity_type,
                    'entity_id' => $conflict->entity_id,
                    'field' => $conflict->field,
                    'client_value' => $conflict->client_value,
                    'server_value' => $conflict->server_value,
                    'created_at' => $conflict->created_at,
                ])->values(),
            ],
            'athlete_records' => $training->athleteRecords
                ->sortBy(fn ($record) => $record->athlete?->nome_completo ?? '')
                ->map(function ($record) use ($metricsByRecord, $timersByRecord) {
                    $metrics = $metricsByRecord->get((string) $record->id)
                        ?? $metricsByRecord->get((string) $record->user_id)
                        ?? collect();
                    $timers = $timersByRecord->get((string) $record->id) ?? collect();

                    return [
                        'id' => $record->id,
                        'user_id' => $record->user_id,
                        'athlete_name' => $record->athlete?->nome_completo,
                        'estado' => $record->estado,
                        'presente' => (bool) $record->presente,
                        'volume_real_m' => $record->volume_real_m,
                        'rpe' => $record->rpe,
                        'observacoes_tecnicas' => $record->observacoes_tecnicas,
                        'cais_version' => $record->cais_version,
                        'cais_status_source' => $record->cais_status_source,
                        'cais_last_modified_at' => $record->cais_last_modified_at,
                        'cais_last_modified_by' => $record->cais_last_modified_by,
                        'metrics' => $metrics->sortByDesc('recorded_at')->map(fn ($metric) => [
                            'id' => $metric->id,
                            'training_series_id' => $metric->training_series_id,
                            'measurement_type' => $metric->measurement_type ?? $metric->metrica,
                            'total_distance_m' => $metric->total_distance_m,
                            'repetition_mode' => $metric->repetition_mode,
                            'repetition_number' => $metric->repetition_number,
                            'duration_ms' => $metric->duration_ms,
                            'splits' => $metric->splits_json ?? [],
                            'valor' => $metric->valor,
                            'tempo' => $metric->tempo,
                            'observacao' => $metric->observacao,
                            'recorded_at' => $metric->recorded_at,
                            'captured_by' => $metric->captured_by ?? $metric->registado_por,
                        ])->values(),
                        'timers' => $timers->map(fn ($timer) => [
                            'id' => $timer->id,
                            'training_series_id' => $timer->training_series_id,
                            'exercise_label' => $timer->exercise_label,
                            'repetition_number' => $timer->repetition_number,
                            'timer_state' => $timer->timer_state,
                            'elapsed_ms' => $timer->elapsed_ms,
                            'started_at' => $timer->started_at,
                            'last_resumed_at' => $timer->last_resumed_at,
                            'stopped_at' => $timer->stopped_at,
                            'version' => $timer->version,
                        ])->values(),
                    ];
                })
                ->values(),
            'subgroup_timers' => $training->poolDeckTimers
                ->where('subject_type', 'subgroup')
                ->map(fn ($timer) => [
                    'id' => $timer->id,
                    'subject_key' => $timer->subject_key,
                    'exercise_label' => $timer->exercise_label,
                    'training_series_id' => $timer->training_series_id,
                    'timer_state' => $timer->timer_state,
                    'elapsed_ms' => $timer->elapsed_ms,
                    'started_at' => $timer->started_at,
                    'last_resumed_at' => $timer->last_resumed_at,
                    'version' => $timer->version,
                ])->values(),
        ];
    }
}
