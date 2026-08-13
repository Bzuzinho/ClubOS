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
        $view = in_array($request->string('view')->toString(), ['training','athlete','type'], true)
            ? $request->string('view')->toString() : 'training';
        $filters = $this->filters($request);

        return [
            'view'=>$view,
            'filters'=>$filters,
            'filterOptions'=>$this->filterOptions(),
            'trainings'=>$view === 'training' ? $this->trainingIndex($filters) : null,
            'athletes'=>$view === 'athlete' ? $this->athleteIndex($filters) : null,
            'records'=>$view === 'type' ? $this->recordsByType($filters) : null,
        ];
    }

    public function trainingDetail(Training $training): array
    {
        $this->assertTrainingClub($training);
        $training->load([
            'responsibleCoach:id,name','responsibleCoach.dadosPessoais:id,user_id,nome_completo',
            'venue:id,name','pool:id,name,length_m','sessionGroups.group','series.stroke','series.zone',
            'athleteRecords.atleta:id,name','athleteRecords.atleta.dadosPessoais:id,user_id,nome_completo',
            'scheduleExceptions.recordedBy:id,name',
        ]);
        $names = $this->identityDisplayResolver->mapDisplayNames($training->athleteRecords->pluck('atleta')->filter()->values());

        $timings = SportsLiveMeasurementAthlete::query()
            ->where('state','stopped')
            ->whereHas('measurement.monitoring', fn (Builder $q) => $q
                ->where('club_id',$this->clubContext->id())->where('training_id',$training->id)->where('state','!=','cancelled'))
            ->where(fn (Builder $q) => $q
                ->whereHas('measurement.monitoring', fn (Builder $m) => $m->where('type','planned'))
                ->orWhereHas('classification'))
            ->with(['athlete.dadosPessoais','events','classification','measurement.series.stroke','measurement.series.zone','measurement.monitoring'])
            ->get();

        $metrics = SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())
            ->where('training_id',$training->id)->whereNull('voided_at')
            ->with(['athlete.dadosPessoais','series.stroke','series.zone'])->orderBy('recorded_at')->get();
        $caisRows = TrainingMetric::query()->where('treino_id',$training->id)->with(['atleta.dadosPessoais'])->orderBy('ordem')->get();

        return [
            'session'=>[
                'id'=>(string)$training->id,'number'=>$training->numero_treino,'date'=>$training->data?->toDateString(),
                'start_time'=>$training->hora_inicio?substr((string)$training->hora_inicio,0,5):null,
                'end_time'=>$training->hora_fim?substr((string)$training->hora_fim,0,5):null,
                'group'=>$training->sessionGroups->pluck('group.name')->filter()->join(', '),
                'coach'=>$training->responsibleCoach?$this->identityDisplayResolver->displayNameOrFallback($training->responsibleCoach,'Treinador'):null,
                'venue'=>$training->venue?->name??$training->local,'pool'=>$training->pool?->name,'pool_length_m'=>$training->pool?->length_m,
                'volume_m'=>(int)($training->volume_planeado_m??0),'status'=>$training->session_status,
            ],
            'summary'=>[
                'present_count'=>$training->athleteRecords->where('presente',true)->count(),
                'measurement_count'=>$timings->count(),'metric_count'=>$metrics->count(),
                'operational_count'=>$caisRows->count()+$training->scheduleExceptions->count(),
            ],
            'execution'=>$timings->map(fn(SportsLiveMeasurementAthlete $row)=>$this->timingPayload($row,$names))->values(),
            'metrics'=>$metrics->map(fn(SportsLiveMetricRecord $row)=>$this->metricPayload($row))->values(),
            'operational'=>[
                'attendance'=>$training->athleteRecords->map(fn(TrainingAthlete $row)=>[
                    'athlete_id'=>(string)$row->user_id,
                    'athlete'=>$names[(string)$row->user_id]??($row->atleta?$this->identityDisplayResolver->displayNameOrFallback($row->atleta,'Atleta'):'Atleta'),
                    'status'=>$row->estado?:($row->presente?'presente':'ausente'),
                    'recorded_at'=>$row->registado_em?->toIso8601String()??$row->created_at?->toIso8601String(),
                ])->values(),
                'registers'=>$caisRows->map(fn(TrainingMetric $row)=>[
                    'id'=>(string)$row->id,'athlete_id'=>(string)$row->user_id,
                    'athlete'=>$row->atleta?$this->identityDisplayResolver->displayNameOrFallback($row->atleta,'Atleta'):'Atleta',
                    'code'=>$row->metrica,'value'=>$row->valor,'note'=>$row->observacao,
                    'recorded_at'=>$row->recorded_at?->toIso8601String()??$row->created_at?->toIso8601String(),
                ])->values(),
                'occurrences'=>$training->scheduleExceptions->map(fn(TrainingScheduleException $row)=>[
                    'id'=>(string)$row->id,'type'=>$row->exception_type,'reason'=>$row->reason,
                    'recorded_at'=>$row->recorded_at?->toIso8601String(),'before'=>$row->before_state,'after'=>$row->after_state,
                ])->values(),
            ],
        ];
    }

    public function athleteTimeline(string $athleteId, Request $request): LengthAwarePaginator
    {
        $filters=$this->filters($request);$filters['athlete_id']=$athleteId;
        $p=$this->trainingScope($filters)->with('sessionGroups.group')->orderByDesc('data')->orderByDesc('hora_inicio')->paginate(25)->withQueryString();
        $ids=$p->getCollection()->pluck('id')->map('strval')->values(); if($ids->isEmpty()) return $p;
        $timings=SportsLiveMeasurementAthlete::query()->where('user_id',$athleteId)->where('state','stopped')
            ->whereHas('measurement.monitoring',fn(Builder $q)=>$q->where('club_id',$this->clubContext->id())->whereIn('training_id',$ids)->where('state','!=','cancelled'))
            ->with(['measurement.series.stroke','measurement.monitoring','classification'])->get()->groupBy(fn($row)=>(string)$row->measurement?->training_id);
        $metrics=SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->whereIn('training_id',$ids)->where('user_id',$athleteId)->whereNull('voided_at')->orderBy('recorded_at')->get()->groupBy('training_id');
        $ops=TrainingMetric::query()->whereIn('treino_id',$ids)->where('user_id',$athleteId)->get()->groupBy('treino_id');
        $p->setCollection($p->getCollection()->map(function(Training $t) use($timings,$metrics,$ops){
            $rows=$timings->get((string)$t->id,collect());
            $summary=$rows->groupBy(function($row){$m=$row->measurement?->monitoring;$s=$row->measurement?->series;$d=$m?->type==='free'?(int)($row->classification?->total_distance_m??0):(int)($s?->distancia_m??0);$stroke=$m?->type==='free'?($row->classification?->stroke_label??'—'):($s?->stroke?->name??$s?->estilo??'—');return $d.'|'.$stroke;})->map(function(Collection $items,string $key){[$d,$stroke]=explode('|',$key,2);return['distance_m'=>(int)$d,'stroke'=>$stroke,'count'=>$items->count(),'best_ms'=>$items->whereNotNull('duration_ms')->min('duration_ms')];})->values();
            $latest=$metrics->get((string)$t->id,collect())->groupBy('metric_code')->map(function(Collection $items){$last=$items->sortBy('recorded_at')->last();return['name'=>$last?->metric_name,'value'=>$last?->value,'unit'=>$last?->unit_snapshot];})->values();
            return['training'=>['id'=>(string)$t->id,'number'=>$t->numero_treino,'date'=>$t->data?->toDateString(),'start_time'=>$t->hora_inicio?substr((string)$t->hora_inicio,0,5):null,'group'=>$t->sessionGroups->pluck('group.name')->filter()->join(', ')],'measurement_count'=>$rows->count(),'execution_summary'=>$summary,'metrics'=>$latest,'operational_count'=>$ops->get((string)$t->id,collect())->count()];
        }));return$p;
    }

    public function exportRows(Request $request): Collection
    {
        $filters=$this->filters($request);$type=$filters['record_type']?:'timing';
        if($type==='metric')return$this->metricQuery($filters)->limit(10000)->get()->map(fn($r)=>['data_hora'=>$r->recorded_at?->toDateTimeString(),'atleta'=>$r->athlete?$this->identityDisplayResolver->displayNameOrFallback($r->athlete,'Atleta'):'Atleta','metrica'=>$r->metric_name,'valor'=>$r->value,'unidade'=>$r->unit_snapshot,'exercicio'=>$r->series?->descricao_texto,'nota'=>$r->note]);
        if($type==='operational')return$this->operationalQuery($filters)->limit(10000)->get()->map(fn($r)=>['data_hora'=>$r->recorded_at,'tipo'=>$r->kind,'atleta_id'=>$r->user_id,'codigo'=>$r->code,'valor'=>$r->value,'nota'=>$r->note]);
        return$this->timingQuery($filters)->limit(10000)->get()->map(function($r){$x=$this->timingPayload($r);return['data_hora'=>$r->stopped_at?->toDateTimeString(),'atleta'=>$x['athlete'],'tipo'=>$x['measurement_type'],'distancia_m'=>$x['distance_m'],'estilo'=>$x['stroke'],'exercicio'=>$x['exercise'],'ronda'=>$x['round'],'repeticao'=>$x['repetition'],'tempo_final_ms'=>$x['final_ms'],'splits_ms'=>$x['splits']->pluck('elapsed_ms')->join('|')];});
    }

    private function trainingIndex(array $filters): LengthAwarePaginator
    {
        $p=$this->trainingScope($filters)->with('sessionGroups.group')->orderByDesc('data')->orderByDesc('hora_inicio')->paginate(25)->withQueryString();$ids=$p->getCollection()->pluck('id')->map('strval')->values();if($ids->isEmpty())return$p;
        $present=TrainingAthlete::query()->whereIn('treino_id',$ids)->where('presente',true)->selectRaw('treino_id,COUNT(*) aggregate')->groupBy('treino_id')->pluck('aggregate','treino_id');
        $timings=SportsLiveMeasurementAthlete::query()->where('state','stopped')->whereHas('measurement.monitoring',fn(Builder $q)=>$q->where('club_id',$this->clubContext->id())->whereIn('training_id',$ids)->where('state','!=','cancelled'))->with('measurement:id,training_id')->get()->groupBy(fn($r)=>(string)$r->measurement?->training_id)->map->count();
        $metrics=SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->whereIn('training_id',$ids)->whereNull('voided_at')->selectRaw('training_id,COUNT(*) aggregate')->groupBy('training_id')->pluck('aggregate','training_id');
        $regs=TrainingMetric::query()->whereIn('treino_id',$ids)->selectRaw('treino_id,COUNT(*) aggregate')->groupBy('treino_id')->pluck('aggregate','treino_id');
        $exc=TrainingScheduleException::query()->where('club_id',$this->clubContext->id())->whereIn('training_id',$ids)->selectRaw('training_id,COUNT(*) aggregate')->groupBy('training_id')->pluck('aggregate','training_id');
        $p->setCollection($p->getCollection()->map(fn(Training $t)=>['id'=>(string)$t->id,'number'=>$t->numero_treino,'date'=>$t->data?->toDateString(),'start_time'=>$t->hora_inicio?substr((string)$t->hora_inicio,0,5):null,'group'=>$t->sessionGroups->pluck('group.name')->filter()->join(', '),'training_type'=>$t->tipo_treino,'volume_m'=>(int)($t->volume_planeado_m??0),'present_count'=>(int)($present[(string)$t->id]??0),'measurement_count'=>(int)($timings[(string)$t->id]??0),'metric_count'=>(int)($metrics[(string)$t->id]??0),'operational_count'=>(int)($regs[(string)$t->id]??0)+(int)($exc[(string)$t->id]??0)]));return$p;
    }

    private function athleteIndex(array $filters): LengthAwarePaginator
    {
        $athleteIds=TrainingAthlete::query()->whereIn('treino_id',$this->trainingScope($filters)->select('id'))->when($filters['athlete_id'],fn(Builder $q,string $id)=>$q->where('user_id',$id))->distinct()->pluck('user_id');
        $p=User::query()->whereIn('id',$athleteIds)->with('dadosPessoais')->orderBy('name')->paginate(40)->withQueryString();$users=$p->getCollection();$ids=$users->pluck('id')->map('strval')->values();$names=$this->identityDisplayResolver->mapDisplayNames($users);if($ids->isEmpty())return$p;
        $tc=TrainingAthlete::query()->whereIn('user_id',$ids)->whereIn('treino_id',$this->trainingScope($filters)->select('id'))->selectRaw('user_id,COUNT(DISTINCT treino_id) aggregate')->groupBy('user_id')->pluck('aggregate','user_id');
        $mc=SportsLiveMeasurementAthlete::query()->whereIn('user_id',$ids)->where('state','stopped')->whereHas('measurement.monitoring',fn(Builder $q)=>$q->where('club_id',$this->clubContext->id())->whereIn('training_id',$this->trainingScope($filters)->select('id'))->where('state','!=','cancelled'))->selectRaw('user_id,COUNT(*) aggregate')->groupBy('user_id')->pluck('aggregate','user_id');
        $xc=SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->whereIn('user_id',$ids)->whereIn('training_id',$this->trainingScope($filters)->select('id'))->whereNull('voided_at')->selectRaw('user_id,COUNT(*) aggregate')->groupBy('user_id')->pluck('aggregate','user_id');
        $p->setCollection($users->map(fn(User $u)=>['id'=>(string)$u->id,'name'=>$names[(string)$u->id]??$this->identityDisplayResolver->displayNameOrFallback($u,'Atleta'),'training_count'=>(int)($tc[(string)$u->id]??0),'measurement_count'=>(int)($mc[(string)$u->id]??0),'metric_count'=>(int)($xc[(string)$u->id]??0)]));return$p;
    }

    private function recordsByType(array $filters): LengthAwarePaginator
    {
        $type=$filters['record_type']?:'timing';
        if($type==='metric'){$p=$this->metricQuery($filters)->paginate(50)->withQueryString();$p->setCollection($p->getCollection()->map(fn($r)=>['kind'=>'metric',...$this->metricPayload($r)]));return$p;}
        if($type==='operational')return$this->operationalQuery($filters)->paginate(50)->withQueryString();
        $p=$this->timingQuery($filters)->paginate(50)->withQueryString();$p->setCollection($p->getCollection()->map(fn($r)=>['kind'=>'timing',...$this->timingPayload($r),'recorded_at'=>$r->stopped_at?->toIso8601String(),'training_id'=>(string)$r->measurement?->training_id,'training_date'=>$r->measurement?->monitoring?->training?->data?->toDateString(),'training_number'=>$r->measurement?->monitoring?->training?->numero_treino]));return$p;
    }

    private function timingQuery(array $filters): Builder
    {
        $q=SportsLiveMeasurementAthlete::query()->where('state','stopped')->whereHas('measurement.monitoring',fn(Builder $m)=>$m->where('club_id',$this->clubContext->id())->whereIn('training_id',$this->trainingScope($filters)->select('id'))->where('state','!=','cancelled'))->where(fn(Builder $x)=>$x->whereHas('measurement.monitoring',fn(Builder $m)=>$m->where('type','planned'))->orWhereHas('classification'))->with(['athlete.dadosPessoais','events','classification','measurement.series.stroke','measurement.series.zone','measurement.monitoring.training']);
        if($filters['athlete_id'])$q->where('user_id',$filters['athlete_id']);if($filters['measurement_type'])$q->whereHas('measurement.monitoring',fn(Builder $m)=>$m->where('type',$filters['measurement_type']));if($filters['stroke_id'])$q->where(fn(Builder $x)=>$x->whereHas('measurement.series',fn(Builder $s)=>$s->where('sports_stroke_id',$filters['stroke_id']))->orWhereHas('classification',fn(Builder $c)=>$c->where('sports_stroke_id',$filters['stroke_id'])));if($filters['distance_m'])$q->where(fn(Builder $x)=>$x->whereHas('measurement.series',fn(Builder $s)=>$s->where('distancia_m',$filters['distance_m']))->orWhereHas('classification',fn(Builder $c)=>$c->where('total_distance_m',$filters['distance_m'])));if($filters['zone_id'])$q->whereHas('measurement.series',fn(Builder $s)=>$s->where('training_zone_config_id',$filters['zone_id']));return$q->orderByDesc('stopped_at');
    }

    private function metricQuery(array $filters): Builder
    {
        $q=SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->whereIn('training_id',$this->trainingScope($filters)->select('id'))->whereNull('voided_at')->with(['athlete.dadosPessoais','series.stroke','series.zone']);if($filters['athlete_id'])$q->where('user_id',$filters['athlete_id']);if($filters['metric_definition_id'])$q->where('metric_definition_id',$filters['metric_definition_id']);if($filters['stroke_id'])$q->whereHas('series',fn(Builder $s)=>$s->where('sports_stroke_id',$filters['stroke_id']));if($filters['distance_m'])$q->whereHas('series',fn(Builder $s)=>$s->where('distancia_m',$filters['distance_m']));if($filters['zone_id'])$q->whereHas('series',fn(Builder $s)=>$s->where('training_zone_config_id',$filters['zone_id']));return$q->orderByDesc('recorded_at');
    }

    private function operationalQuery(array $filters)
    {
        $a=DB::table('training_athletes')->select(['id','treino_id as training_id','user_id'])->selectRaw("'attendance' kind")->selectRaw("COALESCE(estado,CASE WHEN presente=1 THEN 'presente' ELSE 'ausente' END) code")->selectRaw('NULL value,NULL note,COALESCE(registado_em,created_at) recorded_at')->whereIn('treino_id',$this->trainingScope($filters)->select('id'));
        $r=DB::table('training_metrics')->select(['id','treino_id as training_id','user_id'])->selectRaw("'register' kind")->selectRaw('metrica code,valor value,observacao note,COALESCE(recorded_at,created_at) recorded_at')->whereIn('treino_id',$this->trainingScope($filters)->select('id'));
        $o=DB::table('training_schedule_exceptions')->select(['id','training_id'])->selectRaw('NULL user_id')->selectRaw("'occurrence' kind")->selectRaw('exception_type code,reason value,NULL note,recorded_at')->where('club_id',$this->clubContext->id())->whereIn('training_id',$this->trainingScope($filters)->select('id'));
        if($filters['athlete_id']){$a->where('user_id',$filters['athlete_id']);$r->where('user_id',$filters['athlete_id']);$o->whereRaw('1=0');}return DB::query()->fromSub($a->unionAll($r)->unionAll($o),'sports_records')->orderByDesc('recorded_at');
    }

    private function trainingScope(array $filters): Builder
    {
        $q=Training::query()->where('club_id',$this->clubContext->id());if($filters['from'])$q->whereDate('data','>=',$filters['from']);if($filters['to'])$q->whereDate('data','<=',$filters['to']);if($filters['athlete_id'])$q->whereHas('athleteRecords',fn(Builder $x)=>$x->where('user_id',$filters['athlete_id']));if($filters['group_id'])$q->whereHas('sessionGroups',fn(Builder $x)=>$x->where('training_group_id',$filters['group_id']));if($filters['coach_id'])$q->where('responsavel_id',$filters['coach_id']);if($filters['stroke_id'])$q->whereHas('series',fn(Builder $x)=>$x->where('sports_stroke_id',$filters['stroke_id']));if($filters['distance_m'])$q->whereHas('series',fn(Builder $x)=>$x->where('distancia_m',$filters['distance_m']));if($filters['zone_id'])$q->whereHas('series',fn(Builder $x)=>$x->where('training_zone_config_id',$filters['zone_id']));if($filters['metric_definition_id'])$q->whereIn('id',SportsLiveMetricRecord::query()->select('training_id')->where('club_id',$this->clubContext->id())->whereNull('voided_at')->where('metric_definition_id',$filters['metric_definition_id']));if($filters['measurement_type'])$q->whereIn('id',SportsLiveMonitoring::query()->select('training_id')->where('club_id',$this->clubContext->id())->where('state','!=','cancelled')->where('type',$filters['measurement_type']));return$q;
    }

    private function filterOptions(): array
    {
        $athletes=User::query()->whereIn('id',TrainingAthlete::query()->whereHas('training',fn(Builder $q)=>$q->where('club_id',$this->clubContext->id()))->distinct()->pluck('user_id'))->with('dadosPessoais')->get();$names=$this->identityDisplayResolver->mapDisplayNames($athletes);
        return['athletes'=>$athletes->map(fn(User $u)=>['id'=>(string)$u->id,'name'=>$names[(string)$u->id]??$this->identityDisplayResolver->displayNameOrFallback($u,'Atleta')])->sortBy('name')->values(),'groups'=>TrainingGroup::query()->forClub($this->clubContext->id())->orderBy('name')->get(['id','name'])->map(fn($r)=>['id'=>(string)$r->id,'name'=>$r->name])->values(),'strokes'=>SportsStroke::query()->forClub($this->clubContext->id())->orderBy('sort_order')->get(['id','name'])->map(fn($r)=>['id'=>(string)$r->id,'name'=>$r->name])->values(),'zones'=>TrainingZoneConfig::query()->forClub($this->clubContext->id())->orderBy('ordem')->get(['id','codigo','nome'])->map(fn($r)=>['id'=>(string)$r->id,'name'=>trim(($r->codigo?$r->codigo.' · ':'').$r->nome)])->values(),'metrics'=>SportsLiveMetricDefinition::query()->where('club_id',$this->clubContext->id())->orderBy('ordem')->get(['id','nome','unit'])->map(fn($r)=>['id'=>(string)$r->id,'name'=>$r->nome,'unit'=>$r->unit])->values(),'distances'=>TrainingSeries::query()->whereHas('training',fn(Builder $q)=>$q->where('club_id',$this->clubContext->id()))->where('distancia_m','>',0)->distinct()->orderBy('distancia_m')->pluck('distancia_m')->map(fn($v)=>(int)$v)->values(),'recordTypes'=>[['value'=>'timing','label'=>'Tempos & splits'],['value'=>'metric','label'=>'Métricas'],['value'=>'operational','label'=>'Operacional']],'measurementTypes'=>[['value'=>'planned','label'=>'Planeada'],['value'=>'free','label'=>'Livre']]];
    }

    private function timingPayload(SportsLiveMeasurementAthlete $row,array|Collection $names=[]): array
    {
        $m=$row->measurement?->monitoring;$s=$row->measurement?->series;return['id'=>(string)$row->id,'athlete_id'=>(string)$row->user_id,'athlete'=>$names[(string)$row->user_id]??($row->athlete?$this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta'):'Atleta'),'measurement_type'=>$m?->type,'series_id'=>$s?->id?(string)$s->id:null,'block'=>$s?->block_name??$s?->bloco,'exercise'=>$s?->descricao_texto,'stroke'=>$m?->type==='free'?($row->classification?->stroke_label??'—'):($s?->stroke?->name??$s?->estilo),'distance_m'=>$m?->type==='free'?(int)($row->classification?->total_distance_m??0):(int)($s?->distancia_m??0),'round'=>(int)($row->measurement?->round_number??1),'repetition'=>(int)($row->measurement?->repetition_number??1),'splits'=>$row->events->where('event_type','split')->values()->map(fn($e)=>['sequence'=>(int)$e->sequence,'elapsed_ms'=>(int)$e->elapsed_ms]),'final_ms'=>$row->duration_ms!==null?(int)$row->duration_ms:null,'segment_count'=>$row->classification?->segment_count,'segment_distance_m'=>$row->classification?->segment_distance_m];
    }

    private function metricPayload(SportsLiveMetricRecord $row): array
    {
        return['id'=>(string)$row->id,'athlete_id'=>(string)$row->user_id,'athlete'=>$row->athlete?$this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta'):'Atleta','metric'=>$row->metric_name,'value'=>$row->value,'unit'=>$row->unit_snapshot,'note'=>$row->note,'recorded_at'=>$row->recorded_at?->toIso8601String(),'training_id'=>(string)$row->training_id,'exercise'=>$row->series?->descricao_texto,'stroke'=>$row->series?->stroke?->name??$row->series?->estilo,'distance_m'=>$row->series?->distancia_m,'zone'=>$row->series?->zone?->codigo??$row->series?->zona_intensidade];
    }

    private function filters(Request $request): array
    {
        $r=$request->string('record_type')->toString();$m=$request->string('measurement_type')->toString();return['from'=>$request->date('from')?->toDateString(),'to'=>$request->date('to')?->toDateString(),'athlete_id'=>$request->string('athlete_id')->toString()?:null,'group_id'=>$request->string('group_id')->toString()?:null,'stroke_id'=>$request->string('stroke_id')->toString()?:null,'distance_m'=>$request->integer('distance_m')?:null,'zone_id'=>$request->string('zone_id')->toString()?:null,'metric_definition_id'=>$request->string('metric_definition_id')->toString()?:null,'record_type'=>in_array($r,['timing','metric','operational'],true)?$r:null,'measurement_type'=>in_array($m,['planned','free'],true)?$m:null,'coach_id'=>$request->string('coach_id')->toString()?:null];
    }

    private function assertTrainingClub(Training $training): void
    {
        if((string)$training->club_id!==$this->clubContext->id())abort(404);
    }
}
