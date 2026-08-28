<?php

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\Result;
use App\Models\SportsCaisMetricDefinition;
use App\Models\SportsEvaluation;
use App\Models\SportsLiveMetricRecord;
use App\Models\TrainingAthlete;
use App\Models\TrainingAthleteCaisMetric;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SportsAnalysisWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly CanonicalSportsAudienceService $audience,
        private readonly MemberIdentityDisplayResolver $identity,
    ) {}

    public function workspace(): array
    {
        $ids = $this->audience->activeAthleteIds();
        $users = User::query()->whereIn('id', $ids)->get()->keyBy(fn (User $u) => (string) $u->id);
        $names = $this->identity->mapDisplayNames($users);

        $memberships = TrainingGroupMembership::query()
            ->with('group')
            ->where('club_id', $this->clubContext->id())
            ->activeOn(Carbon::today())
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy('user_id');

        $athletes = collect($ids)->map(function (string $id) use ($users, $names, $memberships): array {
            $user = $users->get($id);

            return [
                'id' => $id,
                'name' => $user ? ($names[$id] ?? $this->identity->displayName($user)) : 'Atleta indisponível',
                'member_number' => $user?->numero_socio,
                'groups' => $memberships->get($id, collect())->map(fn ($m) => [
                    'id' => (string) $m->training_group_id,
                    'name' => (string) ($m->group?->name ?? 'Grupo'),
                ])->values()->all(),
            ];
        })->values();

        $groups = TrainingGroup::query()
            ->forClub($this->clubContext->id())
            ->active()
            ->whereNull('archived_at')
            ->withCount(['memberships as active_members_count' => fn ($q) => $q->activeOn(Carbon::today())])
            ->orderBy('name')
            ->get()
            ->map(fn (TrainingGroup $g) => [
                'id' => (string) $g->id,
                'name' => (string) $g->name,
                'modality' => $g->modality,
                'athlete_count' => (int) $g->active_members_count,
            ])->values();

        $competitions = Competition::query()
            ->forClub($this->clubContext->id())
            ->withCount(['provas as result_count' => fn ($q) => $q->whereHas('results')])
            ->orderByDesc('data_inicio')
            ->limit(100)
            ->get()
            ->map(fn (Competition $c) => [
                'id' => (string) $c->id,
                'name' => (string) $c->nome,
                'starts_at' => optional($c->data_inicio)->format('Y-m-d') ?: (string) $c->data_inicio,
                'location' => $c->local,
                'status' => (string) $c->status,
            ])->values();

        return [
            'athletes' => $athletes->all(),
            'groups' => $groups->all(),
            'competitions' => $competitions->all(),
            'windows' => [4, 12, 26, 52],
            'indicators' => $this->indicatorCatalog(),
            'principles' => [
                'read_only' => true,
                'causality_disclaimer' => 'Associações e tendências são exploratórias e não demonstram causalidade.',
                'legacy_performance_kv_active' => false,
            ],
        ];
    }

    public function athlete(User $athlete, int $weeks = 12): array
    {
        $this->assertActiveAthlete($athlete);
        $weeks = max(1, min($weeks, 104));
        $from = Carbon::today()->subWeeks($weeks)->startOfDay();

        $trainingRows = TrainingAthlete::query()
            ->with('training')
            ->where('user_id', $athlete->id)
            ->whereHas('training', fn ($q) => $q
                ->where('club_id', $this->clubContext->id())
                ->whereDate('data', '>=', $from->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->get();

        $attendanceTotal = $trainingRows->count();
        $presentRows = $trainingRows->filter(fn (TrainingAthlete $r) => $r->presente || in_array($r->estado, ['presente', 'atrasado'], true));
        $attendanceRate = $attendanceTotal > 0 ? round(($presentRows->count() / $attendanceTotal) * 100, 1) : null;
        $volume = (int) $presentRows->sum(fn (TrainingAthlete $r) => (int) ($r->volume_real_m ?? 0));
        $rpeRows = $trainingRows->filter(fn (TrainingAthlete $r) => $r->rpe !== null);
        $avgRpe = $rpeRows->isNotEmpty() ? round((float) $rpeRows->avg('rpe'), 2) : null;

        $weekly = $this->weeklyTrainingSeries($trainingRows, $from, $weeks);
        $evaluations = $this->evaluationSeries($athlete, $from);
        $results = $this->resultSeries($athlete, $from);
        $caisMetrics = $this->caisMetrics($athlete, $from);
        $liveMetrics = $this->liveMetrics($athlete, $from);

        $evaluationAverage = $evaluations->whereNotNull('score')->isNotEmpty()
            ? round((float) $evaluations->whereNotNull('score')->avg('score'), 2)
            : null;
        $podiums = $results->filter(fn (array $r) => in_array($r['position'], [1, 2, 3], true))->count();

        $coverage = [
            'training_rows' => $trainingRows->count(),
            'presence_rows' => $trainingRows->whereNotNull('estado')->count(),
            'rpe_rows' => $rpeRows->count(),
            'cais_metrics' => (int) $caisMetrics->sum('count'),
            'evaluations' => $evaluations->count(),
            'results' => $results->count(),
            'live_metrics' => (int) $liveMetrics->sum('count'),
        ];
        $coverage['score'] = $this->coverageScore($coverage);

        return [
            'athlete' => [
                'id' => (string) $athlete->id,
                'name' => $this->identity->displayName($athlete),
                'member_number' => $athlete->numero_socio,
            ],
            'window' => ['weeks' => $weeks, 'from' => $from->toDateString(), 'to' => Carbon::today()->toDateString()],
            'kpis' => [
                'attendance_rate' => $attendanceRate,
                'volume_m' => $volume,
                'avg_rpe' => $avgRpe,
                'evaluation_average' => $evaluationAverage,
                'podiums' => $podiums,
            ],
            'training' => [
                'scheduled_rows' => $attendanceTotal,
                'present' => $presentRows->count(),
                'late' => $trainingRows->where('estado', 'atrasado')->count(),
                'absent' => $trainingRows->where('estado', 'ausente')->count(),
                'volume_m' => $volume,
                'avg_rpe' => $avgRpe,
                'rpe_coverage' => $attendanceTotal > 0 ? round(($rpeRows->count() / $attendanceTotal) * 100, 1) : null,
                'weekly' => $weekly->values()->all(),
                'cais_metrics' => $caisMetrics->values()->all(),
                'live_metrics' => $liveMetrics->values()->all(),
            ],
            'evaluations' => $evaluations->values()->all(),
            'results' => $results->values()->all(),
            'results_by_race' => $this->resultsByRace($results),
            'relationships' => $this->relationships($weekly, $results),
            'coverage' => $coverage,
            'disclaimer' => 'As relações apresentadas são descritivas/exploratórias. Não são diagnósticos, previsões de lesão nem prova de causalidade.',
        ];
    }

    public function group(TrainingGroup $group, int $weeks = 12): array
    {
        abort_unless((string) $group->club_id === $this->clubContext->id(), 404);
        $weeks = max(1, min($weeks, 104));
        $from = Carbon::today()->subWeeks($weeks)->startOfDay();
        $activeAthletes = collect($this->audience->activeAthleteIds());

        $ids = TrainingGroupMembership::query()
            ->where('club_id', $this->clubContext->id())
            ->where('training_group_id', $group->id)
            ->activeOn(Carbon::today())
            ->pluck('user_id')
            ->map('strval')
            ->intersect($activeAthletes)
            ->values();

        $users = User::query()->whereIn('id', $ids)->get()->keyBy(fn (User $u) => (string) $u->id);
        $names = $this->identity->mapDisplayNames($users);

        $trainingRows = TrainingAthlete::query()
            ->with('training')
            ->whereIn('user_id', $ids)
            ->whereHas('training', fn ($q) => $q
                ->where('club_id', $this->clubContext->id())
                ->whereDate('data', '>=', $from->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->get()
            ->groupBy(fn (TrainingAthlete $row) => (string) $row->user_id);

        $evaluations = SportsEvaluation::query()
            ->with('campaign')
            ->whereIn('athlete_user_id', $ids)
            ->where('state', 'completed')
            ->whereHas('campaign', fn ($q) => $q->where('club_id', $this->clubContext->id()))
            ->where(fn ($q) => $q->whereDate('completed_at', '>=', $from->toDateString())->orWhereNull('completed_at'))
            ->get()
            ->groupBy(fn (SportsEvaluation $row) => (string) $row->athlete_user_id);

        $results = Result::query()
            ->with('prova.competition')
            ->whereIn('user_id', $ids)
            ->whereHas('prova.competition', fn ($q) => $q
                ->forClub($this->clubContext->id())
                ->whereDate('data_inicio', '>=', $from->toDateString()))
            ->get()
            ->groupBy(fn (Result $row) => (string) $row->user_id);

        $liveCounts = SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())
            ->whereIn('user_id', $ids)
            ->whereNull('voided_at')
            ->whereDate('recorded_at', '>=', $from->toDateString())
            ->get()
            ->groupBy(fn (SportsLiveMetricRecord $row) => (string) $row->user_id)
            ->map->count();

        $caisCounts = TrainingAthleteCaisMetric::query()
            ->whereIn('user_id', $ids)
            ->whereHas('training', fn ($q) => $q
                ->where('club_id', $this->clubContext->id())
                ->whereDate('data', '>=', $from->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->get()
            ->groupBy(fn (TrainingAthleteCaisMetric $row) => (string) $row->user_id)
            ->map->count();

        $summaries = $ids->map(function (string $id) use ($users, $names, $trainingRows, $evaluations, $results, $liveCounts, $caisCounts): ?array {
            $user = $users->get($id);
            if (! $user) {
                return null;
            }

            $athleteTraining = $trainingRows->get($id, collect());
            $present = $athleteTraining->filter(fn (TrainingAthlete $row) => $row->presente || in_array($row->estado, ['presente', 'atrasado'], true));
            $rpeRows = $athleteTraining->filter(fn (TrainingAthlete $row) => $row->rpe !== null);
            $athleteEvaluations = $evaluations->get($id, collect());
            $athleteResults = $results->get($id, collect());
            $scoredEvaluations = $athleteEvaluations->whereNotNull('overall_score');
            $attendanceRate = $athleteTraining->isNotEmpty()
                ? round(($present->count() / $athleteTraining->count()) * 100, 1)
                : null;

            $coverage = [
                'training_rows' => $athleteTraining->count(),
                'presence_rows' => $athleteTraining->whereNotNull('estado')->count(),
                'rpe_rows' => $rpeRows->count(),
                'cais_metrics' => (int) ($caisCounts->get($id) ?? 0),
                'evaluations' => $athleteEvaluations->count(),
                'results' => $athleteResults->count(),
                'live_metrics' => (int) ($liveCounts->get($id) ?? 0),
            ];

            return [
                'id' => $id,
                'name' => $names[$id] ?? $this->identity->displayName($user),
                'attendance_rate' => $attendanceRate,
                'volume_m' => (int) $present->sum(fn (TrainingAthlete $row) => (int) ($row->volume_real_m ?? 0)),
                'avg_rpe' => $rpeRows->isNotEmpty() ? round((float) $rpeRows->avg('rpe'), 2) : null,
                'evaluation_average' => $scoredEvaluations->isNotEmpty() ? round((float) $scoredEvaluations->avg('overall_score'), 2) : null,
                'podiums' => $athleteResults->filter(fn (Result $row) => in_array((int) $row->posicao, [1, 2, 3], true))->count(),
                'coverage' => $this->coverageScore($coverage),
            ];
        })->filter()->values();

        return [
            'group' => ['id' => (string) $group->id, 'name' => (string) $group->name],
            'window_weeks' => $weeks,
            'athlete_count' => $summaries->count(),
            'attendance_average' => $this->nullableAverage($summaries->pluck('attendance_rate')),
            'volume_total_m' => (int) $summaries->sum('volume_m'),
            'rpe_average' => $this->nullableAverage($summaries->pluck('avg_rpe')),
            'evaluation_average' => $this->nullableAverage($summaries->pluck('evaluation_average')),
            'podiums' => (int) $summaries->sum('podiums'),
            'athletes' => $summaries->all(),
        ];
    }

    public function competition(Competition $competition): array
    {
        $competition = Competition::query()
            ->forClub($this->clubContext->id())
            ->with(['provas.results.athlete', 'teamResults'])
            ->findOrFail($competition->id);
        $results = $competition->provas->flatMap->results;

        return [
            'competition' => [
                'id' => (string) $competition->id,
                'name' => (string) $competition->nome,
                'starts_at' => optional($competition->data_inicio)->format('Y-m-d'),
            ],
            'stats' => [
                'results' => $results->count(),
                'athletes' => $results->pluck('user_id')->unique()->count(),
                'podiums' => $results->filter(fn ($r) => in_array((int) $r->posicao, [1, 2, 3], true))->count(),
                'dsq' => $results->where('status', 'dsq')->count(),
                'dns' => $results->where('status', 'dns')->count(),
                'dnf' => $results->where('status', 'dnf')->count(),
                'points' => (int) $results->sum('pontos_fina'),
            ],
            'positions' => $results
                ->groupBy(fn ($r) => $r->posicao ? (string) $r->posicao : 'sem_classificacao')
                ->map->count()
                ->all(),
            'races' => $competition->provas->map(fn ($p) => [
                'id' => (string) $p->id,
                'label' => trim($p->distancia_m.'m '.$p->estilo),
                'results' => $p->results->count(),
                'podiums' => $p->results->filter(fn ($r) => in_array((int) $r->posicao, [1, 2, 3], true))->count(),
                'best_time' => $p->results->where('status', 'ok')->min('tempo_oficial'),
            ])->values()->all(),
            'team_results' => $competition->teamResults->map(fn ($t) => [
                'team' => $t->equipa,
                'position' => $t->classificacao,
                'points' => $t->pontos,
            ])->all(),
        ];
    }

    private function weeklyTrainingSeries(Collection $rows, Carbon $from, int $weeks): Collection
    {
        $buckets = collect(range(0, max(0, $weeks - 1)))->mapWithKeys(function (int $i) use ($from) {
            $start = $from->copy()->addWeeks($i)->startOfWeek();

            return [$start->toDateString() => [
                'week' => $start->toDateString(),
                'volume_m' => 0,
                'sessions' => 0,
                'present' => 0,
                'rpe_values' => [],
            ]];
        });

        foreach ($rows as $row) {
            if (! $row->training?->data) {
                continue;
            }
            $key = Carbon::parse($row->training->data)->startOfWeek()->toDateString();
            if (! $buckets->has($key)) {
                continue;
            }

            $bucket = $buckets->get($key);
            $bucket['sessions']++;
            if ($row->presente || in_array($row->estado, ['presente', 'atrasado'], true)) {
                $bucket['present']++;
                $bucket['volume_m'] += (int) ($row->volume_real_m ?? 0);
            }
            if ($row->rpe !== null) {
                $bucket['rpe_values'][] = (int) $row->rpe;
            }
            $buckets->put($key, $bucket);
        }

        return $buckets->map(function (array $bucket) {
            $values = $bucket['rpe_values'];
            unset($bucket['rpe_values']);
            $bucket['avg_rpe'] = $values ? round(array_sum($values) / count($values), 2) : null;

            return $bucket;
        });
    }

    private function evaluationSeries(User $athlete, Carbon $from): Collection
    {
        return SportsEvaluation::query()
            ->with('campaign')
            ->where('athlete_user_id', $athlete->id)
            ->where('state', 'completed')
            ->whereHas('campaign', fn ($q) => $q->where('club_id', $this->clubContext->id()))
            ->where(fn ($q) => $q->whereDate('completed_at', '>=', $from->toDateString())->orWhereNull('completed_at'))
            ->orderBy('completed_at')
            ->get()
            ->map(fn ($evaluation) => [
                'id' => (string) $evaluation->id,
                'campaign' => (string) ($evaluation->campaign?->name ?? 'Avaliação'),
                'completed_at' => optional($evaluation->completed_at)->toIso8601String(),
                'score' => $evaluation->overall_score !== null ? (float) $evaluation->overall_score : null,
                'summary' => $evaluation->summary,
                'objectives' => $evaluation->objectives,
            ]);
    }

    private function resultSeries(User $athlete, Carbon $from): Collection
    {
        return Result::query()
            ->with(['prova.competition', 'splits'])
            ->where('user_id', $athlete->id)
            ->whereHas('prova.competition', fn ($q) => $q
                ->forClub($this->clubContext->id())
                ->whereDate('data_inicio', '>=', $from->toDateString()))
            ->get()
            ->sortBy(fn ($result) => $result->prova?->competition?->data_inicio)
            ->values()
            ->map(fn ($result) => [
                'id' => (string) $result->id,
                'competition_id' => (string) $result->prova?->competition?->id,
                'competition' => (string) ($result->prova?->competition?->nome ?? 'Competição'),
                'date' => optional($result->prova?->competition?->data_inicio)->format('Y-m-d'),
                'race_id' => (string) $result->prova_id,
                'race' => trim(($result->prova?->distancia_m ?? 0).'m '.($result->prova?->estilo ?? '')),
                'distance_m' => (int) ($result->prova?->distancia_m ?? 0),
                'official_time' => $result->tempo_oficial !== null ? (float) $result->tempo_oficial : null,
                'position' => $result->posicao,
                'points' => $result->pontos_fina,
                'status' => (string) ($result->status ?? ($result->desclassificado ? 'dsq' : 'ok')),
                'splits' => $result->splits->map(fn ($split) => [
                    'distance_m' => (int) $split->distancia_parcial_m,
                    'time' => (float) $split->tempo_parcial,
                ])->values()->all(),
            ]);
    }

    private function caisMetrics(User $athlete, Carbon $from): Collection
    {
        $definitions = SportsCaisMetricDefinition::query()
            ->where('club_id', $this->clubContext->id())
            ->get();

        return TrainingAthleteCaisMetric::query()
            ->where('user_id', $athlete->id)
            ->whereHas('training', fn ($q) => $q
                ->where('club_id', $this->clubContext->id())
                ->whereDate('data', '>=', $from->toDateString())
                ->where('session_status', '!=', 'cancelled'))
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (TrainingAthleteCaisMetric $row) => trim((string) $row->metrica) ?: 'metrica')
            ->map(function (Collection $rows, string $code) use ($definitions): array {
                $definition = $definitions->first(fn (SportsCaisMetricDefinition $item) =>
                    (string) $item->codigo === $code || (string) $item->nome === $code
                );
                $numeric = $rows
                    ->map(fn (TrainingAthleteCaisMetric $row) => is_numeric($row->valor) ? (float) $row->valor : null)
                    ->filter(fn ($value) => $value !== null)
                    ->values();
                $latest = $rows->first();
                $latestValue = $latest?->valor ?? $latest?->tempo;

                return [
                    'code' => $definition?->codigo ?: $code,
                    'name' => $definition?->nome ?: $code,
                    'unit' => $definition?->unit,
                    'count' => $rows->count(),
                    'latest' => $latestValue,
                    'latest_number' => is_numeric($latestValue) ? (float) $latestValue : null,
                    'average' => $numeric->isNotEmpty() ? round((float) $numeric->avg(), 2) : null,
                ];
            })
            ->values();
    }

    private function liveMetrics(User $athlete, Carbon $from): Collection
    {
        return SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())
            ->where('user_id', $athlete->id)
            ->whereNull('voided_at')
            ->whereDate('recorded_at', '>=', $from->toDateString())
            ->orderByDesc('recorded_at')
            ->get()
            ->groupBy('metric_code')
            ->map(function (Collection $rows, string $code): array {
                $numeric = $rows->whereNotNull('value_number');

                return [
                    'code' => $code,
                    'name' => (string) ($rows->first()?->metric_name ?? $code),
                    'unit' => $rows->first()?->unit_snapshot,
                    'count' => $rows->count(),
                    'latest' => $rows->first()?->value,
                    'latest_number' => $rows->first()?->value_number !== null ? (float) $rows->first()->value_number : null,
                    'average' => $numeric->isNotEmpty() ? round((float) $numeric->avg('value_number'), 2) : null,
                ];
            })
            ->values();
    }

    private function resultsByRace(Collection $results): array
    {
        return $results->groupBy('race')->map(function (Collection $rows, string $race): array {
            $ok = $rows->where('status', 'ok')->whereNotNull('official_time');

            return [
                'race' => $race,
                'participations' => $rows->count(),
                'best_time' => $ok->isNotEmpty() ? (float) $ok->min('official_time') : null,
                'latest_time' => $ok->last()['official_time'] ?? null,
                'podiums' => $rows->filter(fn ($row) => in_array($row['position'], [1, 2, 3], true))->count(),
            ];
        })->values()->all();
    }

    private function relationships(Collection $weekly, Collection $results): array
    {
        $resultWeeks = $results
            ->where('status', 'ok')
            ->whereNotNull('official_time')
            ->groupBy(fn ($result) => Carbon::parse($result['date'])->startOfWeek()->toDateString());

        $pairs = $weekly->map(function ($week) use ($resultWeeks) {
            $result = $resultWeeks->get($week['week'], collect())->first();

            return $result ? [
                'week' => $week['week'],
                'volume_m' => $week['volume_m'],
                'avg_rpe' => $week['avg_rpe'],
                'result_time' => $result['official_time'],
                'race' => $result['race'],
            ] : null;
        })->filter()->values();

        return [
            'weekly_training_vs_result' => $pairs->all(),
            'sample_size' => $pairs->count(),
            'interpretation' => $pairs->count() >= 3
                ? 'Amostra disponível para exploração visual; não implica causalidade.'
                : 'Amostra insuficiente para uma relação exploratória robusta.',
        ];
    }

    private function coverageScore(array $coverage): int
    {
        $signals = 0;
        $available = 0;

        if ($coverage['training_rows'] > 0) {
            $available += 3;
            if ($coverage['presence_rows'] > 0) {
                $signals++;
            }
            if ($coverage['rpe_rows'] > 0) {
                $signals++;
            }
            if (($coverage['cais_metrics'] ?? 0) > 0) {
                $signals++;
            }
        }

        $available += 3;
        if ($coverage['evaluations'] > 0) {
            $signals++;
        }
        if ($coverage['results'] > 0) {
            $signals++;
        }
        if ($coverage['live_metrics'] > 0) {
            $signals++;
        }

        return $available > 0 ? (int) round(($signals / $available) * 100) : 0;
    }

    private function nullableAverage(Collection $values): ?float
    {
        $values = $values->filter(fn ($value) => $value !== null);

        return $values->isNotEmpty() ? round((float) $values->avg(), 2) : null;
    }

    private function assertActiveAthlete(User $athlete): void
    {
        abort_unless(in_array((string) $athlete->id, $this->audience->activeAthleteIds(), true), 404);
    }

    private function indicatorCatalog(): array
    {
        return [
            ['code' => 'attendance', 'name' => 'Assiduidade', 'source' => 'Cais / training_athletes', 'nature' => 'factual'],
            ['code' => 'volume', 'name' => 'Volume realizado', 'source' => 'Treino + presença', 'nature' => 'derived'],
            ['code' => 'rpe', 'name' => 'RPE', 'source' => 'Cais / training_athletes', 'nature' => 'measured'],
            ['code' => 'cais_metrics', 'name' => 'Métricas Cais', 'source' => 'training_athlete_cais_metrics + catálogo Cais', 'nature' => 'measured'],
            ['code' => 'live_metrics', 'name' => 'Métricas Live', 'source' => 'sports_live_metric_records', 'nature' => 'measured'],
            ['code' => 'evaluation', 'name' => 'Avaliação formal', 'source' => 'Avaliações', 'nature' => 'coach_appraisal'],
            ['code' => 'competition_time', 'name' => 'Tempo competitivo', 'source' => 'Resultados', 'nature' => 'factual'],
            ['code' => 'splits', 'name' => 'Splits competitivos', 'source' => 'Resultados', 'nature' => 'factual'],
        ];
    }
}
