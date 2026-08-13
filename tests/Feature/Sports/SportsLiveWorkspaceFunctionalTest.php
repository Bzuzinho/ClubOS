<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\SportsLiveMeasurementEvent;
use App\Models\SportsLiveMetricDefinition;
use App\Models\SportsLiveMetricRecord;
use App\Models\SportsLiveMonitoring;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Desportivo\SportsLiveWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SportsLiveWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); config()->set('sports.club_id','bscn'); }

    public function test_workspace_lists_only_present_athletes(): void
    {
        [$actor,$present,$training,$record]=$this->fixture();
        $absent=User::factory()->create(['estado'=>'ativo','tipo_membro'=>['atleta'],'ativo_desportivo'=>true]);
        TrainingAthlete::query()->create(['treino_id'=>$training->id,'user_id'=>$absent->id,'presente'=>false,'estado'=>'ausente','registado_por'=>$actor->id,'registado_em'=>now()]);
        $payload=app(SportsLiveWorkspaceService::class)->payload(Request::create('/desportivo/live','GET',['date'=>$training->data->toDateString(),'training_id'=>$training->id]));
        $ids=collect(data_get($payload,'selectedSession.athletes'))->pluck('id');
        $this->assertTrue($ids->contains((string)$present->id)); $this->assertFalse($ids->contains((string)$absent->id));
    }

    public function test_planned_measurement_supports_shared_start_splits_and_individual_stop(): void
    {
        [$actor,$a1,$training,$r1,$series]=$this->fixture(true);
        $a2=User::factory()->create(['estado'=>'ativo','tipo_membro'=>['atleta'],'ativo_desportivo'=>true]);
        $r2=TrainingAthlete::query()->create(['treino_id'=>$training->id,'user_id'=>$a2->id,'presente'=>true,'estado'=>'presente','registado_por'=>$actor->id,'registado_em'=>now()]);
        $service=app(SportsLiveWorkspaceService::class);
        $monitor=$service->startPlanned($training,$series,[(string)$r1->id,(string)$r2->id],$actor,'measure-1');
        $measurementId=data_get($monitor,'measurement.id');
        $measurement=\App\Models\SportsLiveMeasurement::query()->findOrFail($measurementId);
        $service->split($measurement,$a1,15000,now()->toIso8601String(),'split-a1-1',$actor);
        $service->stop($measurement,$a1,30200,now()->toIso8601String(),'stop-a1-1',$actor);
        $this->assertDatabaseHas('sports_live_measurement_athletes',['measurement_id'=>$measurementId,'user_id'=>$a1->id,'state'=>'stopped','duration_ms'=>30200]);
        $this->assertDatabaseHas('sports_live_measurement_athletes',['measurement_id'=>$measurementId,'user_id'=>$a2->id,'state'=>'active']);
        $service->stopAll($measurement->fresh(),31500,now()->toIso8601String(),'stop-all-1',$actor);
        $this->assertSame(1,SportsLiveMeasurementEvent::query()->where('measurement_id',$measurementId)->where('event_type','start')->count());
        $this->assertDatabaseHas('sports_live_measurements',['id'=>$measurementId,'state'=>'stopped']);
    }

    public function test_athlete_cannot_be_in_two_active_monitorings(): void
    {
        [$actor,$athlete,$training,$record,$series]=$this->fixture(true); $service=app(SportsLiveWorkspaceService::class);
        $service->startPlanned($training,$series,[(string)$record->id],$actor,'measure-1');
        $this->expectException(ValidationException::class);
        $service->startFree($training,$athlete,$actor,'measure-2');
    }

    public function test_metric_save_is_append_only_history_with_training_and_exercise_context(): void
    {
        [$actor,$athlete,$training,$record,$series]=$this->fixture(true); $service=app(SportsLiveWorkspaceService::class);
        $definition=SportsLiveMetricDefinition::query()->where('codigo','heart_rate')->firstOrFail();
        $service->saveMetric($training,$athlete,$definition,'168','fim da primeira repetição',(string)$series->id,null,$actor);
        $service->saveMetric($training,$athlete,$definition,'176','pós sprint',(string)$series->id,null,$actor);
        $history=$service->metricHistory($training,$athlete,$definition);
        $this->assertCount(2,$history); $this->assertSame('176',$history[0]['value']);
        $this->assertSame(2,SportsLiveMetricRecord::query()->where('training_id',$training->id)->where('user_id',$athlete->id)->where('metric_code','heart_rate')->count());
        $this->assertDatabaseHas('sports_live_metric_records',['training_id'=>$training->id,'training_series_id'=>$series->id,'training_athlete_id'=>$record->id,'value'=>'168']);
    }

    public function test_free_measurement_classification_divides_distance_by_splits_plus_final_stop(): void
    {
        [$actor,$athlete,$training]=$this->fixture(); $service=app(SportsLiveWorkspaceService::class);
        $monitor=$service->startFree($training,$athlete,$actor,'free-1');
        $measurement=\App\Models\SportsLiveMeasurement::query()->findOrFail(data_get($monitor,'measurement.id'));
        $service->split($measurement,$athlete,15000,now()->toIso8601String(),'free-s1',$actor);
        $service->split($measurement,$athlete,30000,now()->toIso8601String(),'free-s2',$actor);
        $service->split($measurement,$athlete,45000,now()->toIso8601String(),'free-s3',$actor);
        $service->stop($measurement,$athlete,60000,now()->toIso8601String(),'free-stop',$actor);
        $result=$service->classifyFree($measurement->fresh(),$athlete,100,null,'Livre',$actor);
        $classification=data_get($result,'measurement.athletes.0.classification');
        $this->assertSame(4,$classification['segment_count']); $this->assertSame(25.0,$classification['segment_distance_m']);
    }

    public function test_next_starts_a_new_measurement_for_next_repetition(): void
    {
        [$actor,$athlete,$training,$record,$series]=$this->fixture(true); $service=app(SportsLiveWorkspaceService::class);
        $monitor=$service->startPlanned($training,$series,[(string)$record->id],$actor,'m1');
        $measurement=\App\Models\SportsLiveMeasurement::query()->findOrFail(data_get($monitor,'measurement.id'));
        $service->stop($measurement,$athlete,30000,now()->toIso8601String(),'s1',$actor);
        $monitoring=SportsLiveMonitoring::query()->findOrFail($monitor['id']);
        $next=$service->next($monitoring,$actor,'m2');
        $this->assertSame(2,$next['current_repetition']); $this->assertNotSame($measurement->id,data_get($next,'measurement.id'));
    }

    private function fixture(bool $withSeries=false): array
    {
        $actor=User::factory()->create(); $athlete=User::factory()->create(['estado'=>'ativo','tipo_membro'=>['atleta'],'ativo_desportivo'=>true]);
        $training=Training::query()->create(['numero_treino'=>'#LIVE','data'=>now()->toDateString(),'hora_inicio'=>'18:00','hora_fim'=>'19:30','tipo_treino'=>'Técnico','club_id'=>'bscn','session_status'=>'published','criado_por'=>$actor->id]);
        $record=TrainingAthlete::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'presente'=>true,'estado'=>'presente','registado_por'=>$actor->id,'registado_em'=>now()]);
        if(!$withSeries)return[$actor,$athlete,$training,$record];
        $series=TrainingSeries::query()->create(['treino_id'=>$training->id,'ordem'=>1,'descricao_texto'=>'Livre forte','distancia_total_m'=>400,'estilo'=>'Livre','repeticoes'=>4,'distancia_m'=>100,'block_name'=>'Principal','block_order'=>1,'block_rounds'=>1,'timing_mode'=>'each_rep']);
        return[$actor,$athlete,$training,$record,$series];
    }
}
