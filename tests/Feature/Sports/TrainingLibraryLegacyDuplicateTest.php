<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\TrainingPlan;
use App\Models\TrainingPlanSeries;
use App\Models\TrainingPlanVersion;
use App\Models\User;
use App\Services\Desportivo\TrainingPlanDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingLibraryLegacyDuplicateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_legacy_plan_without_structured_blocks_duplicates_from_exact_snapshots(): void
    {
        $actor = User::factory()->create();
        $plan = TrainingPlan::query()->create([
            'club_id' => 'bscn',
            'nome' => 'Plano legacy',
            'estado' => 'published',
            'criado_por' => $actor->id,
        ]);
        $version = TrainingPlanVersion::query()->create([
            'club_id' => 'bscn',
            'training_plan_id' => $plan->id,
            'version' => 1,
            'nome_snapshot' => 'Plano legacy',
            'tipo_treino' => 'Técnico',
            'volume_planeado_m' => 400,
            'criado_por' => $actor->id,
            'publicado_em' => now(),
        ]);
        TrainingPlanSeries::query()->create([
            'club_id' => 'bscn',
            'training_plan_version_id' => $version->id,
            'ordem' => 1,
            'bloco' => 'Principal',
            'repeticoes' => 8,
            'distancia_m' => 50,
            'distancia_total_m' => 400,
            'exercicio' => 'Técnica antiga',
            'estilo' => 'Crawl legacy',
            'zona_intensidade' => 'Z2-legacy',
            'intervalo' => '15"',
            'saida' => '0:50',
            'material' => [['name' => 'Material antigo']],
        ]);

        $copy = app(TrainingPlanDuplicateService::class)->duplicate($plan, $actor);
        $copyVersion = $copy->currentVersion()->with('blocks.series')->firstOrFail();
        $line = $copyVersion->blocks->first()->series->first();

        $this->assertSame('draft', $copy->estado);
        $this->assertSame('Principal', $copyVersion->blocks->first()->name);
        $this->assertSame(8, (int) $line->repeticoes);
        $this->assertSame(50, (int) $line->distancia_m);
        $this->assertSame('Crawl legacy', $line->estilo);
        $this->assertSame('Z2-legacy', $line->zona_intensidade);
        $this->assertSame('15"', $line->intervalo);
        $this->assertSame('0:50', $line->saida);
        $this->assertSame('Material antigo', $line->material[0]['name']);
    }
}
