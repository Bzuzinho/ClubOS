<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\SportsModality;
use App\Models\SportsStroke;
use App\Models\SportsTrainingMaterial;
use App\Models\Training;
use App\Models\TrainingPlan;
use App\Models\TrainingZoneConfig;
use App\Models\User;
use App\Services\Desportivo\SportsTrainingLibraryQueryService;
use App\Services\Desportivo\TrainingPlanService;
use App\Services\Desportivo\TrainingSessionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrainingLibraryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_library_schema_supports_blocks_canonical_catalogues_and_live_timing_semantics(): void
    {
        $this->assertTrue(Schema::hasTable('training_plan_blocks'));
        $this->assertTrue(Schema::hasTable('sports_strokes'));
        $this->assertTrue(Schema::hasTable('sports_training_materials'));
        $this->assertTrue(Schema::hasTable('training_plan_series_materials'));
        $this->assertTrue(Schema::hasColumns('training_plans', ['sports_modality_id', 'tags']));
        $this->assertTrue(Schema::hasColumns('training_plan_series', ['training_plan_block_id','training_zone_config_id','sports_stroke_id','timing_mode']));
        $this->assertTrue(Schema::hasColumns('training_series', ['training_zone_config_id','sports_stroke_id','block_name','block_order','block_rounds','timing_mode']));
    }

    public function test_structured_block_rounds_drive_volume_without_collapsing_repetition_distance(): void
    {
        $actor = User::factory()->create();
        $modality = SportsModality::query()->firstOrCreate(['club_id' => 'bscn', 'code' => 'SWIM'], ['name' => 'Natação', 'active' => true]);
        $zone = TrainingZoneConfig::query()->firstOrCreate(['club_id' => 'bscn', 'codigo' => 'Z4'], ['nome' => 'Zona 4', 'ativo' => true, 'ordem' => 4]);
        $stroke = SportsStroke::query()->where('club_id', 'bscn')->where('code', 'LIVRE')->firstOrFail();
        $material = SportsTrainingMaterial::query()->where('club_id', 'bscn')->where('code', 'PALAS')->firstOrFail();

        $plan = app(TrainingPlanService::class)->create([
            'nome' => 'Série principal lactato', 'sports_modality_id' => $modality->id, 'tags' => ['lactato', 'competição'], 'tipo_treino' => 'Competição',
            'blocks' => [[ 'nome' => 'Principal', 'rondas' => 3, 'series' => [
                ['repeticoes' => 4,'distancia_m' => 100,'exercicio' => 'Ritmo prova','sports_stroke_id' => $stroke->id,'training_zone_config_id' => $zone->id,'timing_mode' => 'each_rep','material_ids' => [$material->id]],
                ['repeticoes' => 4,'distancia_m' => 50,'exercicio' => 'Velocidade','sports_stroke_id' => $stroke->id,'training_zone_config_id' => $zone->id,'timing_mode' => 'each_rep'],
            ]]],
        ], $actor);

        $version = $plan->currentVersion()->with(['blocks.series.materials'])->firstOrFail();
        $this->assertSame(1800, (int) $version->volume_planeado_m);
        $this->assertSame(3, (int) $version->blocks->first()->rounds);
        $this->assertSame(4, (int) $version->blocks->first()->series->first()->repeticoes);
        $this->assertSame(100, (int) $version->blocks->first()->series->first()->distancia_m);
        $this->assertSame(400, (int) $version->blocks->first()->series->first()->distancia_total_m);
        $this->assertSame('each_rep', $version->blocks->first()->series->first()->timing_mode);
        $this->assertSame((string) $material->id, (string) $version->blocks->first()->series->first()->materials->first()->id);
    }

    public function test_session_snapshot_preserves_block_rounds_timing_zone_stroke_and_material(): void
    {
        $actor = User::factory()->create();
        $zone = TrainingZoneConfig::query()->firstOrCreate(['club_id' => 'bscn', 'codigo' => 'Z3'], ['nome' => 'Zona 3', 'ativo' => true, 'ordem' => 3]);
        $stroke = SportsStroke::query()->where('club_id', 'bscn')->where('code', 'LIVRE')->firstOrFail();
        $material = SportsTrainingMaterial::query()->where('club_id', 'bscn')->where('code', 'PULL_BUOY')->firstOrFail();
        $plan = app(TrainingPlanService::class)->create([
            'nome' => 'Snapshot Live',
            'blocks' => [[ 'nome' => 'Principal', 'rondas' => 2, 'series' => [[
                'repeticoes' => 8,'distancia_m' => 50,'exercicio' => 'Livre','sports_stroke_id' => $stroke->id,
                'training_zone_config_id' => $zone->id,'timing_mode' => 'each_rep','material_ids' => [$material->id],
            ]]]],
        ], $actor);
        $session = Training::query()->create(['numero_treino' => '#LIB01','data' => now()->addDay()->toDateString(),'tipo_treino' => 'Manual','club_id' => 'bscn','session_status' => 'draft','criado_por' => $actor->id]);
        app(TrainingSessionPlanService::class)->assign($session, $plan->currentVersion, $actor);
        $line = $session->fresh()->series()->firstOrFail();
        $this->assertSame(8, (int) $line->repeticoes);
        $this->assertSame(50, (int) $line->distancia_m);
        $this->assertSame(400, (int) $line->distancia_total_m);
        $this->assertSame(2, (int) $line->block_rounds);
        $this->assertSame('Principal', $line->block_name);
        $this->assertSame('each_rep', $line->timing_mode);
        $this->assertSame((string) $zone->id, (string) $line->training_zone_config_id);
        $this->assertSame((string) $stroke->id, (string) $line->sports_stroke_id);
        $this->assertSame('PULL_BUOY', $line->material[0]['code']);
    }

    public function test_legacy_series_payload_is_adapted_into_versioned_blocks(): void
    {
        $actor = User::factory()->create();
        $plan = app(TrainingPlanService::class)->create([
            'nome' => 'Legacy adaptado',
            'series_linhas' => [
                ['bloco' => 'Aquecimento','repeticoes' => 4,'metros' => 100,'exercicio' => 'Livre','zona' => 'Z1'],
                ['bloco' => 'Principal','repeticoes' => 8,'metros' => 50,'exercicio' => 'Técnica','zona' => 'Z2'],
            ],
        ], $actor);
        $version = $plan->currentVersion()->with('blocks.series')->firstOrFail();
        $this->assertCount(2, $version->blocks);
        $this->assertSame('Aquecimento', $version->blocks->first()->name);
        $this->assertSame(800, (int) $version->volume_planeado_m);
    }

    public function test_library_query_reads_training_plans_not_dateless_training_rows(): void
    {
        $actor = User::factory()->create();
        app(TrainingPlanService::class)->create(['nome' => 'Plano canónico','blocks' => [[ 'nome' => 'Principal','rondas' => 1,'series' => [['repeticoes' => 4,'distancia_m' => 100,'exercicio' => 'Livre']]]]], $actor);
        Training::query()->create(['numero_treino' => '#LEGACY-LIB','data' => null,'tipo_treino' => 'Legacy','club_id' => 'bscn','session_status' => 'draft','criado_por' => $actor->id]);
        $payload = app(SportsTrainingLibraryQueryService::class)->payload();
        $this->assertCount(1, $payload['libraryPlans']);
        $this->assertSame('Plano canónico', $payload['libraryPlans'][0]['nome']);
    }

    public function test_duplicate_creates_independent_draft_with_same_current_content(): void
    {
        $actor = User::factory()->create();
        $plan = app(TrainingPlanService::class)->create(['nome' => 'Original','publicar' => true,'blocks' => [[ 'nome' => 'Principal','rondas' => 2,'series' => [['repeticoes' => 4,'distancia_m' => 100,'exercicio' => 'Livre']]]]], $actor);
        $copy = app(TrainingPlanService::class)->duplicate($plan, $actor);
        $this->assertNotSame((string) $plan->id, (string) $copy->id);
        $this->assertSame('draft', $copy->estado);
        $this->assertSame(800, (int) $copy->currentVersion->volume_planeado_m);
        $this->assertSame(1, (int) $copy->currentVersion->version);
    }
}
