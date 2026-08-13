<?php

namespace App\Services\Desportivo;

use App\Models\SportsLiveMeasurementAthlete;
use App\Models\SportsLiveMetricDefinition;
use App\Models\SportsLiveMetricRecord;
use App\Models\SportsLiveMonitoring;
use App\Models\SportsStroke;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingGroup;
use App\Models\TrainingMetric;
use App\Models\TrainingScheduleException;
use App\Models\TrainingSeries;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SportsRecordsReadModelService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberIdentityDisplayResolver $identityDisplayResolver,
    ) {}

    public function workspace(Request $request): array
    {
        $view = in_array($request->string('view')->toString(), ['training', 'athlete', 'type'], true)
            ? $request->string('view')->toString()
            : 'training';
        $filters = $this->filters($request);

        return [
            'view' => $view,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'trainings' => $view === 'training' ? $this->trainingIndex($filters) : null,
            'athletes' => $view === 'athlete' ? $this->athleteIndex($filters) : null,
            'records' => $view === 'type' ? $this->recordsByType($filters) : null,
        ];
    }

    public function trainingDetail(Training $training): array
    {
        $this->assertTrainingClub($training);
        $training->load([
            'responsibleCoach:id,name',
            'responsibleCoach.dadosPessoais:id,user_id,nome_completo',
            'venue:id,name',
            'pool:id,name,length_m',
            'sessionGroups.group',
            'series.stroke',
            'series.zone',
            'athleteRecords.atleta:id,name',
            'athleteRecords.atleta.dadosPessoais:id,user_id,nome_completo',
            'scheduleExceptions.recordedBy:id,name',
        ]);

        $names = $this->identityDisplayResolver->mapDisplayNames(
            $training->athleteRecords->pluck('atleta')->filter()->values()
        );

        $timings = $this->consolidatedTimingQuery()
            ->whereHas('measurement.monitoring', fn (Builder $query) => $query
                ->where('club_id', $this->clubContext->id())
                ->where('training_id', $training->id))
            ->with($this->timingRelations())
            ->get();

        $metrics = SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())
            ->where('training_id', $training->id)
            ->whereNull('voided_at')
            ->with(['athlete.dadosPessoais', 'series.stroke', 'series.zone'])
            ->orderBy('recorded_at')
            ->get();

        $caisRows = TrainingMetric::query()
            ->where('treino_id', $training->id)
            ->with('atleta.dadosPessoais')
            ->orderBy('ordem')
            ->get();

        return [
            'session' => [
                'id' => (string) $training->id,
                'number' => $training->numero_treino,
                'date' => $training->data?->toDateString(),
                'start_time' => $training->hora_inicio ? substr((string) $training->hora_inicio, 0, 5) : null,
                'end_time' => $training->hora_fim ? substr((string) $training->hora_fim, 0, 5) : null,
                'group' => $training->sessionGroups->pluck('group.name')->filter()->join(', '),
                'coach' => $training->responsibleCoach
                    ? $this->identityDisplayResolver->displayNameOrFallback($training->responsibleCoach, 'Treinador')
                    : null,
                'venue' => $training->venue?->name ?? $training->local,
                'pool' => $training->pool?->name,
                'pool_length_m' => $training->pool?->length_m,
                'volume_m' => (int) ($training->volume_planeado_m ?? 0),
                'status' => $training->session_status,
            ],
            'summary' => [
                'present_count' => $training->athleteRecords->where('presente', true)->count(),
                'measurement_count' => $timings->count(),
                'metric_count' => $metrics->count(),
                'operational_count' => $caisRows->count() + $training->scheduleExceptions->count(),
            ],
            'execution' => $timings->map(fn (SportsLiveMeasurementAthlete $row): array => $this->timingPayload($row, $names))->values(),
            'metrics' => $metrics->map(fn (SportsLiveMetricRecord $row): array => $this->metricPayload($row))->values(),
            'operational' => [
                'attendance' => $training->athleteRecords->map(fn (TrainingAthlete $row): array => [
                    'athlete_id' => (string) $row->user_id,
                    'athlete' => $names[(string) $row->user_id]
                        ?? ($row->atleta ? $this->identityDisplayResolver->displayNameOrFallback($row->atleta, 'Atleta') : 'Atleta'),
                    'status' => $row->estado ?: ($row->presente ? 'presente' : 'ausente'),
                    'recorded_at' => $row->registado_em?->toIso8601String() ?? $row->created_at?->toIso8601String(),
                ])->values(),
                'registers' => $caisRows->map(fn (TrainingMetric $row): array => [
                    'id' => (string) $row->id,
                    'athlete_id' => (string) $row->user_id,
                    'athlete' => $row->atleta
                        ? $this->identityDisplayResolver->displayNameOrFallback($row->atleta, 'Atleta')
                        : 'Atleta',
                    'code' => $row->metrica,
                    'value' => $row->valor,
                    'note' => $row->observacao,
                    'recorded_at' => $row->recorded_at?->toIso8601String() ?? $row->created_at?->toIso8601String(),
                ])->values(),
                'occurrences' => $training->scheduleExceptions->map(fn (TrainingScheduleException $row): array => [
                    'id' => (string) $row->id,
                    'type' => $row->exception_type,
                    'reason' => $row->reason,
                    'recorded_at' => $row->recorded_at?->toIso8601String(),
                    'before' => $row->before_state,
                    'after' => $row->after_state,
                ])->values(),
            ],
        ];
    }

    public function athleteTimeline(string $athleteId, Request $request): LengthAwarePaginator
    {
        $filters = $this->filters($request);
        $filters['athlete_id'] = $athleteId;

        $paginator = $this->trainingScope($filters)
            ->with('sessionGroups.group')
            ->orderByDesc('data')
            ->orderByDesc('hora_inicio')
            ->paginate(25)
            ->withQueryString();

        $trainingIds = $paginator->getCollection()->pluck('id')->map('strval')->values();
        if ($trainingIds->isEmpty()) return $paginator;

        $timings = $this->consolidatedTimingQuery()
            ->where('user_id', $athleteId)
            ->whereHas('measurement.monitoring', fn (Builder $query) => $query
                ->where('club_id', $this->clubContext->id())
                ->whereIn('training_id', $trainingIds))
            ->with(['measurement.series.stroke', 'measurement.monitoring', 'classification'])
            ->get()
            ->groupBy(fn (SportsLiveMeasurementAthlete $row): string => (string) $row->measurement?->training_id);

        $metrics = SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())
            ->whereIn('training_id', $trainingIds)
            ->where('user_id', $athleteId)
            ->whereNull('voided_at')
            ->orderBy('recorded_at')
            ->get()
            ->groupBy('training_id');

        $operations = TrainingMetric::query()
            ->whereIn('treino_id', $trainingIds)
            ->where('user_id', $athleteId)
            ->get()
            ->groupBy('treino_id');

        $paginator->setCollection($paginator->getCollection()->map(function (Training $training) use ($timings, $metrics, $operations): array {
            $trainingTimings = $timings->get((string) $training->id, collect());
            $execution = $trainingTimings->groupBy(function (SportsLiveMeasurementAthlete $row): string {
                $monitoring = $row->measurement?->monitoring;
                $series = $row->measurement?->series;
                $distance = $monitoring?->type === 'free'
                    ? (int) ($row->classification?->total_distance_m ?? 0)
                    : (int) ($series?->distancia_m ?? 0);
                $stroke = $monitoring?->type === 'free'
                    ? ($row->classification?->stroke_label ?? '—')
                    : ($series?->stroke?->name ?? $series?->estilo ?? '—');
                return $distance.'|'.$stroke;
            })->map(function (Collection $rows, string $key): array {
                [$distance, $stroke] = explode('|', $key, 2);
                return [
                    'distance_m' => (int) $distance,
                    'stroke' => $stroke,
                    'count' => $rows->count(),
                    'best_ms' => $rows->whereNotNull('duration_ms')->min('duration_ms'),
                ];
            })->values();

            $latestMetrics = $metrics->get((string) $training->id, collect())
                ->groupBy('metric_code')
                ->map(function (Collection $rows): array {
                    $last = $rows->sortBy('recorded_at')->last();
                    return ['name' => $last?->metric_name, 'value' => $last?->value, 'unit' => $last?->unit_snapshot];
                })->values();

            return [
                'training' => [
                    'id' => (string) $training->id,
                    'number' => $training->numero_treino,
                    'date' => $training->data?->toDateString(),
                    'start_time' => $training->hora_inicio ? substr((string) $training->hora_inicio, 0, 5) : null,
                    'group' => $training->sessionGroups->pluck('group.name')->filter()->join(', '),
                ],
                'measurement_count' => $trainingTimings->count(),
                'execution_summary' => $execution,
                'metrics' => $latestMetrics,
                'operational_count' => $operations->get((string) $training->id, collect())->count(),
            ];
        }));

        return $paginator;
    }

    public function exportRows(Request $request): Collection
    {
        $filters = $this->filters($request);
        $type = $filters['record_type'] ?: 'timing';

        if ($type === 'metric') {
            return $this->metricQuery($filters)->limit(10000)->get()->map(fn (SportsLiveMetricRecord $row): array => [
                'data_hora' => $row->recorded_at?->toDateTimeString(),
                'atleta' => $row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete, 'Atleta') : 'Atleta',
                'metrica' => $row->metric_name,
                'valor' => $row->value,
                'unidade' => $row->unit_snapshot,
                'exercicio' => $row->series?->descricao_texto,
                'nota' => $row->note,
            ]);
        }

        if ($type === 'operational') {
            return $this->mapOperationalRows($this->operationalQuery($filters)->limit(10000)->get())
                ->map(fn (array $row): array => [
                    'data_hora' => $row['recorded_at'],
                    'tipo' => $row['kind'],
                    'atleta' => $row['athlete'],
                    'codigo' => $row['code'],
                    'valor' => $row['value'],
                    'nota' => $row['note'],
                ]);
        }

        return $this->timingQuery($filters)->limit(10000)->get()->map(function (SportsLiveMeasurementAthlete $row): array {
            $payload = $this->timingPayload($row);
            return [
                'data_hora' => $row->stopped_at?->toDateTimeString(),
                'atleta' => $payload['athlete'],
                'tipo' => $payload['measurement_type'],
                'distancia_m' => $payload['distance_m'],
                'estilo' => $payload['stroke'],
                'exercicio' => $payload['exercise'],
                'ronda' => $payload['round'],
                'repeticao' => $payload['repetition'],
                'tempo_final_ms' => $payload['final_ms'],
                'splits_ms' => $payload['splits']->pluck('elapsed_ms')->join('|'),
            ];
        });
    }

    private function trainingIndex(array $filters): LengthAwarePaginator
    {
        $paginator = $this->trainingScope($filters)
            ->with('sessionGroups.group')
            ->orderByDesc('data')
            ->orderByDesc('hora_inicio')
            ->paginate(25)
            ->withQueryString();
        $ids = $paginator->getCollection()->pluck('id')->map('strval')->values();
        if ($ids->isEmpty()) return $paginator;

        $present = TrainingAthlete::query()->whereIn('treino_id', $ids)->where('presente', true)
            ->selectRaw('treino_id, COUNT(*) aggregate')->groupBy('treino_id')->pluck('aggregate', 'treino_id');

        $timings = $this->consolidatedTimingQuery()
            ->whereHas('measurement.monitoring', fn (Builder $query) => $query
                ->where('club_id', $this->clubContext->id())->whereIn('training_id', $ids))
            ->with('measurement:id,training_id')->get()
            ->groupBy(fn (SportsLiveMeasurementAthlete $row): string => (string) $row->measurement?->training_id)
            ->map->count();

        $metrics = SportsLiveMetricRecord::query()->where('club_id', $this->clubContext->id())->whereIn('training_id', $ids)->whereNull('voided_at')
            ->selectRaw('training_id, COUNT(*) aggregate')->groupBy('training_id')->pluck('aggregate', 'training_id');
        $registers = TrainingMetric::query()->whereIn('treino_id', $ids)
            ->selectRaw('treino_id, COUNT(*) aggregate')->groupBy('treino_id')->pluck('aggregate', 'treino_id');
        $exceptions = TrainingScheduleException::query()->where('club_id', $this->clubContext->id())->whereIn('training_id', $ids)
            ->selectRaw('training_id, COUNT(*) aggregate')->groupBy('training_id')->pluck('aggregate', 'training_id');

        $paginator->setCollection($paginator->getCollection()->map(fn (Training $training): array => [
            'id' => (string) $training->id,
            'number' => $training->numero_treino,
            'date' => $training->data?->toDateString(),
            'start_time' => $training->hora_inicio ? substr((string) $training->hora_inicio, 0, 5) : null,
            'group' => $training->sessionGroups->pluck('group.name')->filter()->join(', '),
            'training_type' => $training->tipo_treino,
            'volume_m' => (int) ($training->volume_planeado_m ?? 0),
            'present_count' => (int) ($present[(string) $training->id] ?? 0),
            'measurement_count' => (int) ($timings[(string) $training->id] ?? 0),
            'metric_count' => (int) ($metrics[(string) $training->id] ?? 0),
            'operational_count' => (int) ($registers[(string) $training->id] ?? 0) + (int) ($exceptions[(string) $training->id] ?? 0),
        ]));

        return $paginator;
    }

    private function athleteIndex(array $filters): LengthAwarePaginator
    {
        $athleteIds = TrainingAthlete::query()
            ->whereIn('treino_id', $this->trainingScope($filters)->select('id'))
            ->when($filters['athlete_id'], fn (Builder $query, string $id) => $query->where('user_id', $id))
            ->distinct()->pluck('user_id');

        $paginator = User::query()->whereIn('id', $athleteIds)->with('dadosPessoais')->orderBy('name')->paginate(40)->withQueryString();
        $users = $paginator->getCollection();
        $ids = $users->pluck('id')->map('strval')->values();
        if ($ids->isEmpty()) return $paginator;
        $names = $this->identityDisplayResolver->mapDisplayNames($users);

        $trainingCounts = TrainingAthlete::query()->whereIn('user_id', $ids)->whereIn('treino_id', $this->trainingScope($filters)->select('id'))
            ->selectRaw('user_id, COUNT(DISTINCT treino_id) aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');

        $timingCounts = $this->consolidatedTimingQuery()->whereIn('user_id', $ids)
            ->whereHas('measurement.monitoring', fn (Builder $query) => $query
                ->where('club_id', $this->clubContext->id())->whereIn('training_id', $this->trainingScope($filters)->select('id')))
            ->selectRaw('user_id, COUNT(*) aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');

        $metricCounts = SportsLiveMetricRecord::query()->where('club_id', $this->clubContext->id())->whereIn('user_id', $ids)
            ->whereIn('training_id', $this->trainingScope($filters)->select('id'))->whereNull('voided_at')
            ->selectRaw('user_id, COUNT(*) aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');

        $paginator->setCollection($users->map(fn (User $user): array => [
            'id' => (string) $user->id,
            'name' => $names[(string) $user->id] ?? $this->identityDisplayResolver->displayNameOrFallback($user, 'Atleta'),
            'training_count' => (int) ($trainingCounts[(string) $user->id] ?? 0),
            'measurement_count' => (int) ($timingCounts[(string) $user->id] ?? 0),
            'metric_count' => (int) ($metricCounts[(string) $user->id] ?? 0),
        ]));

        return $paginator;
    }

    private function recordsByType(array $filters): LengthAwarePaginator
    {
        $type = $filters['record_type'] ?: 'timing';

        if ($type === 'metric') {
            $paginator = $this->metricQuery($filters)->paginate(50)->withQueryString();
            $paginator->setCollection($paginator->getCollection()->map(fn (SportsLiveMetricRecord $row): array => ['kind' => 'metric', ...$this->metricPayload($row)]));
            return $paginator;
        }

        if ($type === 'operational') {
            $paginator = $this->operationalQuery($filters)->paginate(50)->withQueryString();
            $paginator->setCollection($this->mapOperationalRows($paginator->getCollection()));
            return $paginator;
        }

        $paginator = $this->timingQuery($filters)->paginate(50)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (SportsLiveMeasurementAthlete $row): array => [
            'kind' => 'timing',
            ...$this->timingPayload($row),
            'recorded_at' => $row->stopped_at?->toIso8601String(),
            'training_id' => (string) $row->measurement?->training_id,
            'training_date' => $row->measurement?->monitoring?->training?->data?->toDateString(),
            'training_number' => $row->measurement?->monitoring?->training?->numero_treino,
        ]));
        return $paginator;
    }

    private function consolidatedTimingQuery(): Builder
    {
        return SportsLiveMeasurementAthlete::query()
            ->where('state', 'stopped')
            ->whereHas('measurement.monitoring', fn (Builder $query) => $query->where('state', '!=', 'cancelled'))
            ->where(fn (Builder $query) => $query
                ->whereHas('measurement.monitoring', fn (Builder $monitoring) => $monitoring->where('type', 'planned'))
                ->orWhereHas('classification'));
    }

    private function timingQuery(array $filters): Builder
    {
        $query = $this->consolidatedTimingQuery()
            ->whereHas('measurement.monitoring', fn (Builder $monitoring) => $monitoring
                ->where('club_id', $this->clubContext->id())
                ->whereIn('training_id', $this->trainingScope($filters)->select('id')))
            ->with($this->timingRelations());

        if ($filters['athlete_id']) $query->where('user_id', $filters['athlete_id']);
        if ($filters['measurement_type']) $query->whereHas('measurement.monitoring', fn (Builder $monitoring) => $monitoring->where('type', $filters['measurement_type']));
        if ($filters['stroke_id']) {
            $query->where(fn (Builder $inner) => $inner
                ->whereHas('measurement.series', fn (Builder $series) => $series->where('sports_stroke_id', $filters['stroke_id']))
                ->orWhereHas('classification', fn (Builder $classification) => $classification->where('sports_stroke_id', $filters['stroke_id'])));
        }
        if ($filters['distance_m']) {
            $query->where(fn (Builder $inner) => $inner
                ->whereHas('measurement.series', fn (Builder $series) => $series->where('distancia_m', $filters['distance_m']))
                ->orWhereHas('classification', fn (Builder $classification) => $classification->where('total_distance_m', $filters['distance_m'])));
        }
        if ($filters['zone_id']) $query->whereHas('measurement.series', fn (Builder $series) => $series->where('training_zone_config_id', $filters['zone_id']));

        return $query->orderByDesc('stopped_at');
    }

    private function metricQuery(array $filters): Builder
    {
        $query = SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())
            ->whereIn('training_id', $this->trainingScope($filters)->select('id'))
            ->whereNull('voided_at')
            ->with(['athlete.dadosPessoais', 'series.stroke', 'series.zone']);

        if ($filters['athlete_id']) $query->where('user_id', $filters['athlete_id']);
        if ($filters['metric_definition_id']) $query->where('metric_definition_id', $filters['metric_definition_id']);
        if ($filters['stroke_id']) $query->whereHas('series', fn (Builder $series) => $series->where('sports_stroke_id', $filters['stroke_id']));
        if ($filters['distance_m']) $query->whereHas('series', fn (Builder $series) => $series->where('distancia_m', $filters['distance_m']));
        if ($filters['zone_id']) $query->whereHas('series', fn (Builder $series) => $series->where('training_zone_config_id', $filters['zone_id']));

        return $query->orderByDesc('recorded_at');
    }

    private function operationalQuery(array $filters)
    {
        $trainingIds = $this->trainingScope($filters)->select('id');

        $attendance = DB::table('training_athletes')
            ->select(['id', 'treino_id as training_id', 'user_id'])
            ->selectRaw("'attendance' as kind")
            ->selectRaw("COALESCE(estado, CASE WHEN presente THEN 'presente' ELSE 'ausente' END) as code")
            ->selectRaw('NULL as value, NULL as note, COALESCE(registado_em, created_at) as recorded_at')
            ->whereIn('treino_id', $trainingIds);

        $registers = DB::table('training_metrics')
            ->select(['id', 'treino_id as training_id', 'user_id'])
            ->selectRaw("'register' as kind")
            ->selectRaw('metrica as code, valor as value, observacao as note, COALESCE(recorded_at, created_at) as recorded_at')
            ->whereIn('treino_id', $this->trainingScope($filters)->select('id'));

        $occurrences = DB::table('training_schedule_exceptions')
            ->select(['id', 'training_id'])
            ->selectRaw('NULL as user_id')
            ->selectRaw("'occurrence' as kind")
            ->selectRaw('exception_type as code, reason as value, NULL as note, recorded_at')
            ->where('club_id', $this->clubContext->id())
            ->whereIn('training_id', $this->trainingScope($filters)->select('id'));

        if ($filters['athlete_id']) {
            $attendance->where('user_id', $filters['athlete_id']);
            $registers->where('user_id', $filters['athlete_id']);
            $occurrences->whereRaw('1 = 0');
        }

        return DB::query()->fromSub($attendance->unionAll($registers)->unionAll($occurrences), 'sports_records')
            ->orderByDesc('recorded_at');
    }

    private function mapOperationalRows(Collection $rows): Collection
    {
        $userIds = $rows->pluck('user_id')->filter()->unique()->values();
        $users = User::query()->whereIn('id', $userIds)->with('dadosPessoais')->get();
        $names = $this->identityDisplayResolver->mapDisplayNames($users);
        $trainingIds = $rows->pluck('training_id')->filter()->unique()->values();
        $trainings = Training::query()->where('club_id', $this->clubContext->id())->whereIn('id', $trainingIds)->get()->keyBy('id');

        return $rows->map(function ($row) use ($names, $trainings): array {
            $training = $trainings->get((string) $row->training_id);
            return [
                'kind' => $row->kind,
                'id' => (string) $row->id,
                'training_id' => (string) $row->training_id,
                'training_number' => $training?->numero_treino,
                'training_date' => $training?->data?->toDateString(),
                'athlete_id' => $row->user_id ? (string) $row->user_id : null,
                'athlete' => $row->user_id ? ($names[(string) $row->user_id] ?? 'Atleta') : null,
                'code' => $row->code,
                'value' => $row->value,
                'note' => $row->note,
                'recorded_at' => $row->recorded_at,
            ];
        });
    }

    private function trainingScope(array $filters): Builder
    {
        $query = Training::query()->where('club_id', $this->clubContext->id());
        if ($filters['from']) $query->whereDate('data', '>=', $filters['from']);
        if ($filters['to']) $query->whereDate('data', '<=', $filters['to']);
        if ($filters['athlete_id']) $query->whereHas('athleteRecords', fn (Builder $rows) => $rows->where('user_id', $filters['athlete_id']));
        if ($filters['group_id']) $query->whereHas('sessionGroups', fn (Builder $groups) => $groups->where('training_group_id', $filters['group_id']));
        if ($filters['stroke_id']) $query->whereHas('series', fn (Builder $series) => $series->where('sports_stroke_id', $filters['stroke_id']));
        if ($filters['distance_m']) $query->whereHas('series', fn (Builder $series) => $series->where('distancia_m', $filters['distance_m']));
        if ($filters['zone_id']) $query->whereHas('series', fn (Builder $series) => $series->where('training_zone_config_id', $filters['zone_id']));
        if ($filters['metric_definition_id']) {
            $query->whereIn('id', SportsLiveMetricRecord::query()->select('training_id')
                ->where('club_id', $this->clubContext->id())->whereNull('voided_at')->where('metric_definition_id', $filters['metric_definition_id']));
        }
        if ($filters['measurement_type']) {
            $query->whereIn('id', SportsLiveMonitoring::query()->select('training_id')
                ->where('club_id', $this->clubContext->id())->where('state', '!=', 'cancelled')->where('type', $filters['measurement_type']));
        }
        return $query;
    }

    private function filterOptions(): array
    {
        $athleteIds = TrainingAthlete::query()
            ->whereHas('training', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
            ->distinct()->pluck('user_id');
        $athletes = User::query()->whereIn('id', $athleteIds)->with('dadosPessoais')->get();
        $names = $this->identityDisplayResolver->mapDisplayNames($athletes);

        return [
            'athletes' => $athletes->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => $names[(string) $user->id] ?? $this->identityDisplayResolver->displayNameOrFallback($user, 'Atleta'),
            ])->sortBy('name')->values(),
            'groups' => TrainingGroup::query()->forClub($this->clubContext->id())->orderBy('name')->get(['id', 'name'])
                ->map(fn (TrainingGroup $row): array => ['id' => (string) $row->id, 'name' => $row->name])->values(),
            'strokes' => SportsStroke::query()->forClub($this->clubContext->id())->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                ->map(fn (SportsStroke $row): array => ['id' => (string) $row->id, 'name' => $row->name])->values(),
            'zones' => TrainingZoneConfig::query()->forClub($this->clubContext->id())->orderBy('ordem')->get(['id', 'codigo', 'nome'])
                ->map(fn (TrainingZoneConfig $row): array => ['id' => (string) $row->id, 'name' => trim(($row->codigo ? $row->codigo.' · ' : '').$row->nome)])->values(),
            'metrics' => SportsLiveMetricDefinition::query()->where('club_id', $this->clubContext->id())->orderBy('ordem')->get(['id', 'nome', 'unit'])
                ->map(fn (SportsLiveMetricDefinition $row): array => ['id' => (string) $row->id, 'name' => $row->nome, 'unit' => $row->unit])->values(),
            'distances' => TrainingSeries::query()->whereHas('training', fn (Builder $query) => $query->where('club_id', $this->clubContext->id()))
                ->where('distancia_m', '>', 0)->distinct()->orderBy('distancia_m')->pluck('distancia_m')->map(fn ($value): int => (int) $value)->values(),
            'recordTypes' => [
                ['value' => 'timing', 'label' => 'Tempos & splits'],
                ['value' => 'metric', 'label' => 'Métricas'],
                ['value' => 'operational', 'label' => 'Operacional'],
            ],
            'measurementTypes' => [
                ['value' => 'planned', 'label' => 'Planeada'],
                ['value' => 'free', 'label' => 'Livre'],
            ],
        ];
    }

    private function timingRelations(): array
    {
        return ['athlete.dadosPessoais', 'events', 'classification', 'measurement.series.stroke', 'measurement.series.zone', 'measurement.monitoring.training'];
    }

    private function timingPayload(SportsLiveMeasurementAthlete $row, array|Collection $names = []): array
    {
        $monitoring = $row->measurement?->monitoring;
        $series = $row->measurement?->series;
        return [
            'id' => (string) $row->id,
            'athlete_id' => (string) $row->user_id,
            'athlete' => $names[(string) $row->user_id]
                ?? ($row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete, 'Atleta') : 'Atleta'),
            'measurement_type' => $monitoring?->type,
            'series_id' => $series?->id ? (string) $series->id : null,
            'block' => $series?->block_name ?? $series?->bloco,
            'exercise' => $series?->descricao_texto,
            'stroke' => $monitoring?->type === 'free' ? ($row->classification?->stroke_label ?? '—') : ($series?->stroke?->name ?? $series?->estilo),
            'distance_m' => $monitoring?->type === 'free' ? (int) ($row->classification?->total_distance_m ?? 0) : (int) ($series?->distancia_m ?? 0),
            'round' => (int) ($row->measurement?->round_number ?? 1),
            'repetition' => (int) ($row->measurement?->repetition_number ?? 1),
            'splits' => $row->events->where('event_type', 'split')->values()->map(fn ($event): array => [
                'sequence' => (int) $event->sequence,
                'elapsed_ms' => (int) $event->elapsed_ms,
            ]),
            'final_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'segment_count' => $row->classification?->segment_count,
            'segment_distance_m' => $row->classification?->segment_distance_m,
        ];
    }

    private function metricPayload(SportsLiveMetricRecord $row): array
    {
        return [
            'id' => (string) $row->id,
            'athlete_id' => (string) $row->user_id,
            'athlete' => $row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete, 'Atleta') : 'Atleta',
            'metric' => $row->metric_name,
            'value' => $row->value,
            'unit' => $row->unit_snapshot,
            'note' => $row->note,
            'recorded_at' => $row->recorded_at?->toIso8601String(),
            'training_id' => (string) $row->training_id,
            'exercise' => $row->series?->descricao_texto,
            'stroke' => $row->series?->stroke?->name ?? $row->series?->estilo,
            'distance_m' => $row->series?->distancia_m,
            'zone' => $row->series?->zone?->codigo ?? $row->series?->zona_intensidade,
        ];
    }

    private function filters(Request $request): array
    {
        $recordType = $request->string('record_type')->toString();
        $measurementType = $request->string('measurement_type')->toString();
        return [
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'athlete_id' => $request->string('athlete_id')->toString() ?: null,
            'group_id' => $request->string('group_id')->toString() ?: null,
            'stroke_id' => $request->string('stroke_id')->toString() ?: null,
            'distance_m' => $request->integer('distance_m') ?: null,
            'zone_id' => $request->string('zone_id')->toString() ?: null,
            'metric_definition_id' => $request->string('metric_definition_id')->toString() ?: null,
            'record_type' => in_array($recordType, ['timing', 'metric', 'operational'], true) ? $recordType : null,
            'measurement_type' => in_array($measurementType, ['planned', 'free'], true) ? $measurementType : null,
        ];
    }

    private function assertTrainingClub(Training $training): void
    {
        if ((string) $training->club_id !== $this->clubContext->id()) abort(404);
    }
}
