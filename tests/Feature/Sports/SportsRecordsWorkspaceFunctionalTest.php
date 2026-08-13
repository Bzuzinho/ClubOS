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
use App\Services\Desportivo\SportsRecordsWorkspaceService;
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
        [$actor,$athlete,$training,$record,$series]=$this->fixture();
        TrainingMetric::query()->create(['treino_id'=>$training->id,'user_id'=>$athlete->id,'ordem'=>1,'metrica'=>'behavior','valor'=>'Bom','registado_por'=>$actor->id]);

        $payload=app(SportsRecordsWorkspaceService::class)->workspace(Request::create('/desportivo/registos','GET',['view'=>'training']));

        $this->assertSame('training',$payload['view']);
        $this->assertSame((string)$training->id,(string)data_get($payload,'trainings.data.0.id'));
        $this->assertDatabaseCount('training_metrics',1);
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

        $detail=app(SportsRecordsWorkspaceService::class)->trainingDetail($training->fresh());

        $this->assertSame(1,$detail['summary']['measurement_count']);
        $this->assertSame(1,$detail['summary']['metric_count']);
        $this->assertSame(1,$detail['summary']['operational_count']);
        $this->assertSame(63000,data_get($detail,'execution.0.final_ms'));
        $this->assertSame('176',data_get($detail,'metrics.0.value'));
        $this->assertSame('material',data_get($detail,'operational.registers.0.code'));
    }

    public function test_records_routes_expose_get_only_contract(): void
    {
        $source=file_get_contents(base_path('routes/desportivo_records.php'));
        $this->assertStringContainsString("Route::get('/',",$source);
        $this->assertStringContainsString("Route::get('/treinos/{training}'",$source);
        $this->assertStringContainsString("Route::get('/atletas/{athlete}'",$source);
        $this->assertStringNotContainsString('Route::post(', $source);
        $this->assertStringNotContainsString('Route::put(', $source);
        $this->assertStringNotContainsString('Route::delete(', $source);
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
