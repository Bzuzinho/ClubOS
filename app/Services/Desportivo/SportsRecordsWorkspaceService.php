<?php

namespace App\Services\Desportivo;

use App\Models\SportsLiveMeasurementAthlete;
use App\Models\SportsLiveMetricRecord;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class SportsRecordsWorkspaceService
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
        $training->load(['responsibleCoach:id,name','responsibleCoach.dadosPessoais:id,user_id,nome_completo','venue:id,name','pool:id,name,length_m','sessionGroups.group','series.stroke','series.zone','athleteRecords.atleta:id,name','athleteRecords.atleta.dadosPessoais:id,user_id,nome_completo','scheduleExceptions.recordedBy:id,name']);
        $athleteUsers = $training->athleteRecords->pluck('atleta')->filter()->values();
        $names = $this->identityDisplayResolver->mapDisplayNames($athleteUsers);

        $timings = SportsLiveMeasurementAthlete::query()
            ->whereHas('measurement.monitoring', fn ($q) => $q->where('club_id', $this->clubContext->id())->where('training_id', $training->id)->where('state', '!=', 'cancelled'))
            ->where('state', 'stopped')
            ->with(['athlete.dadosPessoais','events','classification','measurement.series.stroke','measurement.series.zone','measurement.monitoring'])
            ->get();

        $metrics = SportsLiveMetricRecord::query()
            ->where('club_id', $this->clubContext->id())->where('training_id', $training->id)->whereNull('voided_at')
            ->with(['athlete.dadosPessoais','definition','series.stroke','series.zone'])->orderBy('recorded_at')->get();

        $caisRows = TrainingMetric::query()->where('treino_id', $training->id)->with(['atleta.dadosPessoais'])->get();

        return [
            'session' => [
                'id'=>(string)$training->id,'number'=>$training->numero_treino,'date'=>$training->data?->toDateString(),
                'start_time'=>$training->hora_inicio ? substr((string)$training->hora_inicio,0,5) : null,
                'end_time'=>$training->hora_fim ? substr((string)$training->hora_fim,0,5) : null,
                'group'=>$training->sessionGroups->pluck('group.name')->filter()->join(', '),
                'coach'=>$training->responsibleCoach ? $this->identityDisplayResolver->displayNameOrFallback($training->responsibleCoach,'Treinador') : null,
                'venue'=>$training->venue?->name ?? $training->local,'pool'=>$training->pool?->name,'pool_length_m'=>$training->pool?->length_m,
                'volume_m'=>(int)($training->volume_planeado_m ?? 0),'status'=>$training->session_status,
            ],
            'summary' => [
                'present_count'=>$training->athleteRecords->where('presente',true)->count(),
                'measurement_count'=>$timings->count(),
                'metric_count'=>$metrics->count(),
                'operational_count'=>$caisRows->count() + $training->scheduleExceptions->count(),
            ],
            'execution' => $timings->map(function (SportsLiveMeasurementAthlete $row) use ($names): array {
                $measurement = $row->measurement; $series = $measurement?->series; $monitoring = $measurement?->monitoring;
                return [
                    'id'=>(string)$row->id,'athlete_id'=>(string)$row->user_id,
                    'athlete'=>$names[(string)$row->user_id] ?? ($row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta') : 'Atleta'),
                    'measurement_type'=>$monitoring?->type,'series_id'=>$series?->id ? (string)$series->id : null,
                    'exercise'=>$series?->descricao_texto,'stroke'=>$series?->stroke?->name ?? $series?->estilo,
                    'distance_m'=>$monitoring?->type === 'free' ? (int)($row->classification?->total_distance_m ?? 0) : (int)($series?->distancia_m ?? 0),
                    'round'=>(int)($measurement?->round_number ?? 1),'repetition'=>(int)($measurement?->repetition_number ?? 1),
                    'splits'=>$row->events->where('event_type','split')->values()->map(fn($e)=>['sequence'=>(int)$e->sequence,'elapsed_ms'=>(int)$e->elapsed_ms]),
                    'final_ms'=>$row->duration_ms !== null ? (int)$row->duration_ms : null,
                    'segment_count'=>$row->classification?->segment_count,'segment_distance_m'=>$row->classification?->segment_distance_m,
                ];
            })->values(),
            'metrics' => $metrics->map(fn (SportsLiveMetricRecord $row): array => [
                'id'=>(string)$row->id,'athlete_id'=>(string)$row->user_id,
                'athlete'=>$row->athlete ? $this->identityDisplayResolver->displayNameOrFallback($row->athlete,'Atleta') : 'Atleta',
                'metric'=>$row->metric_name,'value'=>$row->value,'unit'=>$row->unit_snapshot,'note'=>$row->note,
                'recorded_at'=>$row->recorded_at?->toIso8601String(),'exercise'=>$row->series?->descricao_texto,
            ])->values(),
            'operational' => [
                'attendance'=>$training->athleteRecords->map(fn(TrainingAthlete $r)=>['athlete_id'=>(string)$r->user_id,'athlete'=>$names[(string)$r->user_id] ?? ($r->atleta ? $this->identityDisplayResolver->displayNameOrFallback($r->atleta,'Atleta') : 'Atleta'),'status'=>$r->estado ?: ($r->presente?'presente':'ausente')])->values(),
                'registers'=>$caisRows->map(fn(TrainingMetric $r)=>['id'=>(string)$r->id,'athlete_id'=>(string)$r->user_id,'athlete'=>$r->atleta ? $this->identityDisplayResolver->displayNameOrFallback($r->atleta,'Atleta') : 'Atleta','code'=>$r->metrica,'value'=>$r->valor,'note'=>$r->observacao,'recorded_at'=>$r->recorded_at?->toIso8601String() ?? $r->created_at?->toIso8601String()])->values(),
                'occurrences'=>$training->scheduleExceptions->map(fn($r)=>['id'=>(string)$r->id,'type'=>$r->exception_type,'reason'=>$r->reason,'recorded_at'=>$r->recorded_at?->toIso8601String(),'before'=>$r->before_state,'after'=>$r->after_state])->values(),
            ],
        ];
    }

    public function athleteTimeline(string $athleteId, array $filters): LengthAwarePaginator
    {
        $query = Training::query()->where('club_id',$this->clubContext->id())
            ->whereHas('athleteRecords', fn($q)=>$q->where('user_id',$athleteId));
        $this->applyTrainingDateFilters($query,$filters);
        $paginator = $query->orderByDesc('data')->orderByDesc('hora_inicio')->paginate(25)->withQueryString();
        $paginator->getCollection()->transform(function(Training $training) use ($athleteId): array {
            $times = SportsLiveMeasurementAthlete::query()->where('user_id',$athleteId)->where('state','stopped')->whereHas('measurement',fn($q)=>$q->where('training_id',$training->id))->get();
            $metrics = SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->where('training_id',$training->id)->where('user_id',$athleteId)->whereNull('voided_at')->get();
            return ['training'=>['id'=>(string)$training->id,'number'=>$training->numero_treino,'date'=>$training->data?->toDateString(),'start_time'=>$training->hora_inicio ? substr((string)$training->hora_inicio,0,5):null], 'measurement_count'=>$times->count(),'best_ms'=>$times->whereNotNull('duration_ms')->min('duration_ms'),'metrics'=>$metrics->groupBy('metric_code')->map(fn($rows)=>['name'=>$rows->last()->metric_name,'value'=>$rows->last()->value,'unit'=>$rows->last()->unit_snapshot])->values()];
        });
        return $paginator;
    }

    private function trainingIndex(array $filters): LengthAwarePaginator
    {
        $query = Training::query()->where('club_id',$this->clubContext->id())->with(['sessionGroups.group']);
        $this->applyTrainingDateFilters($query,$filters);
        if ($filters['athlete_id']) $query->whereHas('athleteRecords',fn($q)=>$q->where('user_id',$filters['athlete_id']));
        if ($filters['group_id']) $query->whereHas('sessionGroups',fn($q)=>$q->where('sports_training_group_id',$filters['group_id']));
        $paginator=$query->orderByDesc('data')->orderByDesc('hora_inicio')->paginate(25)->withQueryString();
        $paginator->getCollection()->transform(function(Training $training): array {
            return ['id'=>(string)$training->id,'number'=>$training->numero_treino,'date'=>$training->data?->toDateString(),'start_time'=>$training->hora_inicio?substr((string)$training->hora_inicio,0,5):null,'group'=>$training->sessionGroups->pluck('group.name')->filter()->join(', '),'volume_m'=>(int)($training->volume_planeado_m??0),'present_count'=>TrainingAthlete::query()->where('treino_id',$training->id)->where('presente',true)->count(),'measurement_count'=>SportsLiveMeasurementAthlete::query()->where('state','stopped')->whereHas('measurement',fn($q)=>$q->where('training_id',$training->id))->count(),'metric_count'=>SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->where('training_id',$training->id)->whereNull('voided_at')->count(),'operational_count'=>TrainingMetric::query()->where('treino_id',$training->id)->count()];
        }); return $paginator;
    }

    private function athleteIndex(array $filters): LengthAwarePaginator
    {
        $query = TrainingAthlete::query()->select('user_id')->whereHas('treino',fn($q)=>$q->where('club_id',$this->clubContext->id()))->groupBy('user_id');
        return $query->paginate(40)->withQueryString();
    }

    private function recordsByType(array $filters): LengthAwarePaginator
    {
        $type=$filters['record_type'] ?: 'timing';
        if($type==='metric') return SportsLiveMetricRecord::query()->where('club_id',$this->clubContext->id())->whereNull('voided_at')->orderByDesc('recorded_at')->paginate(50)->withQueryString();
        if($type==='operational') return TrainingMetric::query()->whereHas('treino',fn($q)=>$q->where('club_id',$this->clubContext->id()))->orderByDesc('created_at')->paginate(50)->withQueryString();
        return SportsLiveMeasurementAthlete::query()->where('state','stopped')->whereHas('measurement.monitoring',fn($q)=>$q->where('club_id',$this->clubContext->id())->where('state','!=','cancelled'))->orderByDesc('stopped_at')->paginate(50)->withQueryString();
    }

    private function filterOptions(): array
    {
        return ['recordTypes'=>[['value'=>'timing','label'=>'Tempos & splits'],['value'=>'metric','label'=>'Métricas'],['value'=>'operational','label'=>'Operacional']]];
    }

    private function filters(Request $request): array
    {
        return ['from'=>$request->date('from')?->toDateString(),'to'=>$request->date('to')?->toDateString(),'athlete_id'=>$request->string('athlete_id')->toString()?:null,'group_id'=>$request->string('group_id')->toString()?:null,'stroke_id'=>$request->string('stroke_id')->toString()?:null,'distance_m'=>$request->integer('distance_m')?:null,'zone_id'=>$request->string('zone_id')->toString()?:null,'metric_definition_id'=>$request->string('metric_definition_id')->toString()?:null,'record_type'=>$request->string('record_type')->toString()?:null,'measurement_type'=>$request->string('measurement_type')->toString()?:null,'coach_id'=>$request->string('coach_id')->toString()?:null];
    }

    private function applyTrainingDateFilters($query,array $filters): void
    {
        if($filters['from']) $query->whereDate('data','>=',$filters['from']);
        if($filters['to']) $query->whereDate('data','<=',$filters['to']);
    }

    private function assertTrainingClub(Training $training): void
    {
        if((string)$training->club_id!==$this->clubContext->id()) abort(404);
    }
}
