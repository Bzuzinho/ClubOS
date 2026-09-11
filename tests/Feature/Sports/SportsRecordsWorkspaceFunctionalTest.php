<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\SportsLiveMetricDefinition;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Desportivo\SportsLiveWorkspaceService;
use App\Services\Desportivo\SportsRecordsReadModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SportsRecordsWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id','bscn');
    }

    public function test_records_workspace_is_read_only_and_lists_training_history(): void
    {
        [$actor,$athlete,$training]=$this->fixture();
        TrainingMetric::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'ordem'=>1,'metrica'=>'behavior','valor'=>'Bom','registado_por'=>$actor->id]);
        $before=TrainingMetric::query()->count();

        $payload=app(SportsRecordsReadModelService::class)->workspace(Request::create('/desportivo/registos','GET',['view'=>'training']));
        $rows=$payload['trainings']->items();

        $this->assertSame('training',$payload['view']);
        $this->assertSame((string)$training->id,(string)$rows[0]['id']);
        $this->assertSame($before,TrainingMetric::query()->count());
    }

    public function test_training_detail_combines_live_timing_metrics_and_cais_without_copying_data(): void
    {
        [$actor,$athlete,$training,$record,$series]=$this->fixture();
        $live=app(SportsLiveWorkspaceService::class);
        $monitor=$live->startPlanned($training,$series,[(string)$record->id],$actor,'records-measure');
        $measurement=\App\Models\SportsLiveMeasurement::query()->findOrFail(data_get($monitor,'measurement.id'));
        $live->split($measurement,$athlete,31000,now()->toIso8601String(),'records-split',$actor);
        $live->stop($measurement,$athlete,63000,now()->toIso8601String(),'records-stop',$actor);
        $definition=SportsLiveMetricDefinition::query()->where('codigo','heart_rate')->firstOrFail();
        $live->saveMetric($training,$athlete,$definition,'176',null,(string)$series->id,(string)$measurement->id,$actor);
        TrainingMetric::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'ordem'=>1,'metrica'=>'material','valor'=>'OK','registado_por'=>$actor->id]);

        $detail=app(SportsRecordsReadModelService::class)->trainingDetail($training->fresh());

        $this->assertSame(1,$detail['summary']['measurement_count']);
        $this->assertSame(1,$detail['summary']['metric_count']);
        $this->assertSame(1,$detail['summary']['operational_count']);
        $this->assertSame(63000,data_get($detail,'execution.0.final_ms'));
        $this->assertSame('176',data_get($detail,'metrics.0.value'));
        $this->assertSame('material',data_get($detail,'operational.registers.0.code'));
        $other = User::factory()->create();
        TrainingMetric::query()->create(['treino_id'=>$training->id,'user_id'=>$other->id,'ordem'=>1,'metrica'=>'private_note','valor'=>'Outro atleta']);
        $timeline = app(SportsRecordsReadModelService::class)->athleteTimeline((string)$athlete->id,
            Request::create('/desportivo/registos/atletas/'.$athlete->id, 'GET', ['training_id'=>(string)$training->id]));
        $row = $timeline->items()[0];
        $this->assertSame(63000, data_get($row, 'execution.0.final_ms'));
        $this->assertSame(31000, data_get($row, 'execution.0.splits.0.elapsed_ms'));
        $this->assertSame(100, data_get($row, 'execution.0.distance_m'));
        $this->assertSame('176', data_get($row, 'live_metrics.0.value'));
        $this->assertCount(1, $row['cais_registers']);
        $this->assertSame('material', data_get($row, 'cais_registers.0.code'));
        \App\Models\SportsLiveMetricRecord::query()->where('user_id', $athlete->id)->update(['voided_at'=>now()]);
        $voided = app(SportsRecordsReadModelService::class)->athleteTimeline((string)$athlete->id,
            Request::create('/', 'GET', ['training_id'=>(string)$training->id]))->items()[0];
        $this->assertCount(0, $voided['live_metrics']);

    }

    public function test_unclassified_free_measurement_is_not_exposed_as_consolidated_result(): void
    {
        [$actor,$athlete,$training]=$this->fixture();
        $live=app(SportsLiveWorkspaceService::class);
        $monitor=$live->startFree($training,$athlete,$actor,'records-free');
        $measurement=\App\Models\SportsLiveMeasurement::query()->findOrFail(data_get($monitor,'measurement.id'));
        $live->stop($measurement,$athlete,30200,now()->toIso8601String(),'records-free-stop',$actor);

        $detail=app(SportsRecordsReadModelService::class)->trainingDetail($training->fresh());

        $this->assertSame(0,$detail['summary']['measurement_count']);
        $this->assertCount(0,$detail['execution']);
    }

    public function test_operational_archive_includes_attendance_and_cais_registers(): void
    {
        [$actor,$athlete,$training]=$this->fixture();
        TrainingMetric::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'ordem'=>1,'metrica'=>'behavior','valor'=>'Positivo','registado_por'=>$actor->id]);
        $payload=app(SportsRecordsReadModelService::class)->workspace(Request::create('/desportivo/registos','GET',['view'=>'type','record_type'=>'operational']));
        $kinds=collect($payload['records']->items())->pluck('kind');
        $this->assertTrue($kinds->contains('attendance'));
        $this->assertTrue($kinds->contains('register'));
    }

    public function test_records_routes_expose_get_only_contract(): void
    {
        $source=file_get_contents(base_path('routes/desportivo_records.php'));
        $this->assertStringContainsString("Route::get('/',",$source);
        $this->assertStringContainsString("Route::get('/export'",$source);
        $this->assertStringContainsString("Route::get('/treinos/{training}'",$source);
        $this->assertStringContainsString("Route::get('/atletas/{athlete}'",$source);
        $this->assertStringNotContainsString('Route::post(', $source);
        $this->assertStringNotContainsString('Route::put(', $source);
        $this->assertStringNotContainsString('Route::delete(', $source);
    }

    public function test_individual_training_detail_cannot_read_other_training_or_club(): void
    {
        [, $athlete, $training] = $this->fixture();
        $other = Training::query()->create(['numero_treino'=>'OTHER','tipo_treino'=>'Técnico','data'=>now(),'club_id'=>'other-club']);
        TrainingAthlete::query()->create(['treino_id'=>$other->id,'user_id'=>$athlete->id,'presente'=>true]);
        $service = app(SportsRecordsReadModelService::class);
        $this->assertSame(0, $service->athleteTimeline((string)$athlete->id, Request::create('/', 'GET', ['training_id'=>(string)$other->id]))->total());
        $stranger = User::factory()->create();
        $this->assertSame(0, $service->athleteTimeline((string)$stranger->id, Request::create('/', 'GET', ['training_id'=>(string)$training->id]))->total());
    }

    private function fixture(): array
    {
        $actor=User::factory()->create();
        $athlete=User::factory()->create(['estado'=>'ativo','tipo_membro'=>['atleta'],'ativo_desportivo'=>true]);
        $training=Training::query()->create(['numero_treino'=>'#REG','data'=>now()->toDateString(),'hora_inicio'=>'18:00','hora_fim'=>'19:30','tipo_treino'=>'Técnico','club_id'=>'bscn','session_status'=>'published','criado_por'=>$actor->id]);
        $record=TrainingAthlete::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'presente'=>true,'estado'=>'presente','registado_por'=>$actor->id,'registado_em'=>now()]);
        $series=TrainingSeries::query()->create(['treino_id'=>$training->id,'ordem'=>1,'descricao_texto'=>'Livre forte','distancia_total_m'=>400,'estilo'=>'Livre','repeticoes'=>4,'distancia_m'=>100,'block_name'=>'Principal','block_order'=>1,'block_rounds'=>1,'timing_mode'=>'each_rep']);
        return[$actor,$athlete,$training,$record,$series];
    }
}
