<?php

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\Result;
use App\Models\SportsEvaluation;
use App\Models\SportsLiveMetricRecord;
use App\Models\TrainingAthlete;
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
        $presentRows = $trainingRows->filter(fn (TrainingAthlete $r) => $r->presente || in_array($r->estado, ['presente','atrasado'], true));
        $attendanceRate = $attendanceTotal > 0 ? round(($presentRows->count() / $attendanceTotal) * 100, 1) : null;
        $volume = (int) $presentRows->sum(fn (TrainingAthlete $r) => (int) ($r->volume_real_m ?? 0));
        $rpeRows = $trainingRows->filter(fn (TrainingAthlete $r) => $r->rpe !== null);
        $avgRpe = $rpeRows->isNotEmpty() ? round((float) $rpeRows->avg('rpe'), 2) : null;

        $weekly = $this->weeklyTrainingSeries($trainingRows, $from, $weeks);
        $evaluations = $this->evaluationSeries($athlete, $from);
        $results = $this->resultSeries($athlete, $from);
        $liveMetrics = $this->liveMetrics($athlete, $from);

        $evaluationAverage = $evaluations->whereNotNull('score')->isNotEmpty()
            ? round((float) $evaluations->whereNotNull('score')->avg('score'), 2)
            : null;
        $podiums = $results->filter(fn (array $r) => in_array($r['position'], [1,2,3], true))->count();

        $coverage = [
            'training_rows' => $trainingRows->count(),
            'presence_rows' => $trainingRows->whereNotNull('estado')->count(),
            'rpe_rows' => $rpeRows->count(),
            'evaluations' => $evaluations->count(),
            'results' => $results->count(),
            'live_metrics' => $liveMetrics->count(),
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
        $ids = TrainingGroupMembership::query()
            ->where('club_id', $this->clubContext->id())
            ->where('training_group_id', $group->id)
            ->activeOn(Carbon::today())
            ->pluck('user_id')->map('strval')->values();

        $summaries = $ids->map(function (string $id) use ($weeks) {
            $user = User::query()->find($id);
            if (! $user) return null;
            $a = $this->athlete($user, $weeks);
            return [
                'id' => $id,
                'name' => $a['athlete']['name'],
                'attendance_rate' => $a['kpis']['attendance_rate'],
                'volume_m' => $a['kpis']['volume_m'],
                'avg_rpe' => $a['kpis']['avg_rpe'],
                'evaluation_average' => $a['kpis']['evaluation_average'],
                'podiums' => $a['kpis']['podiums'],
                'coverage' => $a['coverage']['score'],
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
            'competition' => ['id'=>(string)$competition->id,'name'=>(string)$competition->nome,'starts_at'=>optional($competition->data_inicio)->format('Y-m-d')],
            'stats' => [
                'results' => $results->count(),
                'athletes' => $results->pluck('user_id')->unique()->count(),
                'podiums' => $results->filter(fn ($r) => in_array((int)$r->posicao,[1,2,3],true))->count(),
                'dsq' => $results->where('status','dsq')->count(),
                'dns' => $results->where('status','dns')->count(),
                'dnf' => $results->where('status','dnf')->count(),
                'points' => (int) $results->sum('pontos_fina'),
            ],
            'positions' => $results->groupBy(fn ($r) => $r->posicao ? (string)$r->posicao : 'sem_classificacao')->map->count()->all(),
            'races' => $competition->provas->map(fn ($p) => [
                'id'=>(string)$p->id,
                'label'=>trim($p->distancia_m.'m '.$p->estilo),
                'results'=>$p->results->count(),
                'podiums'=>$p->results->filter(fn ($r)=>in_array((int)$r->posicao,[1,2,3],true))->count(),
                'best_time'=>$p->results->where('status','ok')->min('tempo_oficial'),
            ])->values()->all(),
            'team_results' => $competition->teamResults->map(fn ($t) => ['team'=>$t->equipa,'position'=>$t->classificacao,'points'=>$t->pontos])->all(),
        ];
    }

    private function weeklyTrainingSeries(Collection $rows, Carbon $from, int $weeks): Collection
    {
        $buckets = collect(range(0, max(0, $weeks - 1)))->mapWithKeys(function (int $i) use ($from) {
            $start = $from->copy()->addWeeks($i)->startOfWeek();
            return [$start->toDateString() => ['week' => $start->toDateString(), 'volume_m' => 0, 'sessions' => 0, 'present' => 0, 'rpe_values' => []]];
        });
        foreach ($rows as $row) {
            if (! $row->training?->data) continue;
            $key = Carbon::parse($row->training->data)->startOfWeek()->toDateString();
            if (! $buckets->has($key)) continue;
            $b = $buckets->get($key); $b['sessions']++;
            if ($row->presente || in_array($row->estado,['presente','atrasado'],true)) { $b['present']++; $b['volume_m'] += (int)($row->volume_real_m ?? 0); }
            if ($row->rpe !== null) $b['rpe_values'][] = (int)$row->rpe;
            $buckets->put($key,$b);
        }
        return $buckets->map(function (array $b) { $vals=$b['rpe_values']; unset($b['rpe_values']); $b['avg_rpe']=$vals ? round(array_sum($vals)/count($vals),2) : null; return $b; });
    }

    private function evaluationSeries(User $athlete, Carbon $from): Collection
    {
        return SportsEvaluation::query()->with('campaign')
            ->where('athlete_user_id',$athlete->id)->where('state','completed')
            ->whereHas('campaign',fn($q)=>$q->where('club_id',$this->clubContext->id()))
            ->where(fn($q)=>$q->whereDate('completed_at','>=',$from->toDateString())->orWhereNull('completed_at'))
            ->orderBy('completed_at')->get()->map(fn($e)=>[
                'id'=>(string)$e->id,'campaign'=>(string)($e->campaign?->name ?? 'Avaliação'),'completed_at'=>optional($e->completed_at)->toIso8601String(),
                'score'=>$e->overall_score !== null ? (float)$e->overall_score : null,'summary'=>$e->summary,'objectives'=>$e->objectives,
            ]);
    }

    private function resultSeries(User $athlete, Carbon $from): Collection
    {
        return Result::query()->with(['prova.competition','splits'])->where('user_id',$athlete->id)
            ->whereHas('prova.competition',fn($q)=>$q->forClub($this->clubContext->id())->whereDate('data_inicio','>=',$from->toDateString()))
            ->get()->sortBy(fn($r)=>$r->prova?->competition?->data_inicio)->values()->map(fn($r)=>[
                'id'=>(string)$r->id,'competition_id'=>(string)$r->prova?->competition?->id,'competition'=>(string)($r->prova?->competition?->nome ?? 'Competição'),
                'date'=>optional($r->prova?->competition?->data_inicio)->format('Y-m-d'),'race_id'=>(string)$r->prova_id,'race'=>trim(($r->prova?->distancia_m ?? 0).'m '.($r->prova?->estilo ?? '')),
                'distance_m'=>(int)($r->prova?->distancia_m ?? 0),'official_time'=>$r->tempo_oficial !== null ? (float)$r->tempo_oficial : null,'position'=>$r->posicao,'points'=>$r->pontos_fina,'status'=>(string)($r->status ?? ($r->desclassificado?'dsq':'ok')),
                'splits'=>$r->splits->map(fn($s)=>['distance_m'=>(int)$s->distancia_parcial_m,'time'=>(float)$s->tempo_parcial])->values()->all(),
            ]);
    }

    private function liveMetrics(User $athlete, Carbon $from): Collection
    {
        return SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->where('user_id',$athlete->id)->whereNull('voided_at')->whereDate('recorded_at','>=',$from->toDateString())
            ->orderByDesc('recorded_at')->get()->groupBy('metric_code')->map(function(Collection $rows,string $code){$numeric=$rows->whereNotNull('value_number');return [
                'code'=>$code,'name'=>(string)($rows->first()?->metric_name ?? $code),'unit'=>$rows->first()?->unit_snapshot,'count'=>$rows->count(),
                'latest'=>$rows->first()?->value,'latest_number'=>$rows->first()?->value_number !== null ? (float)$rows->first()->value_number : null,
                'average'=>$numeric->isNotEmpty()?round((float)$numeric->avg('value_number'),2):null,
            ];})->values();
    }

    private function resultsByRace(Collection $results): array
    {
        return $results->groupBy('race')->map(function(Collection $rows,string $race){$ok=$rows->where('status','ok')->whereNotNull('official_time');return [
            'race'=>$race,'participations'=>$rows->count(),'best_time'=>$ok->isNotEmpty()?(float)$ok->min('official_time'):null,'latest_time'=>$ok->last()['official_time']??null,'podiums'=>$rows->filter(fn($r)=>in_array($r['position'],[1,2,3],true))->count(),
        ];})->values()->all();
    }

    private function relationships(Collection $weekly, Collection $results): array
    {
        $resultWeeks=$results->where('status','ok')->whereNotNull('official_time')->groupBy(fn($r)=>Carbon::parse($r['date'])->startOfWeek()->toDateString());
        $pairs=$weekly->map(function($w)use($resultWeeks){$r=$resultWeeks->get($w['week'],collect())->first();return $r?['week'=>$w['week'],'volume_m'=>$w['volume_m'],'avg_rpe'=>$w['avg_rpe'],'result_time'=>$r['official_time'],'race'=>$r['race']]:null;})->filter()->values();
        return ['weekly_training_vs_result'=>$pairs->all(),'sample_size'=>$pairs->count(),'interpretation'=>$pairs->count()>=3?'Amostra disponível para exploração visual; não implica causalidade.':'Amostra insuficiente para uma relação exploratória robusta.'];
    }

    private function coverageScore(array $coverage): int
    {
        $signals=0;$available=0;
        if($coverage['training_rows']>0){$available+=2;if($coverage['presence_rows']>0)$signals++;if($coverage['rpe_rows']>0)$signals++;}
        $available+=3;if($coverage['evaluations']>0)$signals++;if($coverage['results']>0)$signals++;if($coverage['live_metrics']>0)$signals++;
        return $available>0?(int)round(($signals/$available)*100):0;
    }

    private function nullableAverage(Collection $values): ?float
    {
        $v=$values->filter(fn($x)=>$x!==null);return $v->isNotEmpty()?round((float)$v->avg(),2):null;
    }

    private function assertActiveAthlete(User $athlete): void
    {
        abort_unless(in_array((string)$athlete->id,$this->audience->activeAthleteIds(),true),404);
    }

    private function indicatorCatalog(): array
    {
        return [
            ['code'=>'attendance','name'=>'Assiduidade','source'=>'Cais / training_athletes','nature'=>'factual'],
            ['code'=>'volume','name'=>'Volume realizado','source'=>'Treino + presença','nature'=>'derived'],
            ['code'=>'rpe','name'=>'RPE','source'=>'Cais / training_athletes','nature'=>'measured'],
            ['code'=>'live_metrics','name'=>'Métricas Live','source'=>'sports_live_metric_records','nature'=>'measured'],
            ['code'=>'evaluation','name'=>'Avaliação formal','source'=>'Avaliações','nature'=>'coach_appraisal'],
            ['code'=>'competition_time','name'=>'Tempo competitivo','source'=>'Resultados','nature'=>'factual'],
            ['code'=>'splits','name'=>'Splits competitivos','source'=>'Resultados','nature'=>'factual'],
        ];
    }
}
