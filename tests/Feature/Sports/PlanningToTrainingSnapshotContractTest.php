<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\Season;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\Desportivo\SportsPlanningWorkspaceService;
use App\Services\Desportivo\TrainingPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanningToTrainingSnapshotContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_planning_creates_training_with_immutable_plan_version_snapshot(): void
    {
        $actor = User::factory()->create();
        $planning = app(SportsPlanningWorkspaceService::class);
        $season = $this->season();

        $macro = $planning->createMacrocycle([
            'epoca_id' => $season->id,
            'nome' => 'Preparação',
            'tipo' => 'Preparação geral',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-31',
        ], $actor);

        $meso = $planning->createMesocycle([
            'macrociclo_id' => $macro->id,
            'nome' => 'Base',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-31',
            'objetivo_principal' => 'Base aeróbia',
        ], $actor);

        $micro = $planning->createMicrocycle([
            'mesociclo_id' => $meso->id,
            'semana' => 'Semana 1',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-07',
        ], $actor);

        $plan = app(TrainingPlanService::class)->create([
            'nome' => 'Plano H3b',
            'codigo' => 'H3B-' . substr((string) $actor->id, 0, 8),
            'tipo_treino' => 'Técnico',
            'descricao_treino' => 'Versão inicial',
            'series_linhas' => [
                [
                    'bloco' => 'Principal',
                    'repeticoes' => 8,
                    'metros' => 50,
                    'exercicio' => 'Crawl técnico',
                    'zona' => 'Z2',
                ],
            ],
        ], $actor);

        $v1 = $plan->versions()->with('series')->firstOrFail();

        $session = $planning->createSession([
            'season_id' => $season->id,
            'microciclo_id' => $micro->id,
            'data' => '2026-09-03',
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'training_plan_version_id' => $v1->id,
            'session_status' => 'published',
        ], $actor);

        $snapshot = $session->fresh(['series']);
        $this->assertSame((string) $season->id, (string) $snapshot->epoca_id);
        $this->assertSame((string) $macro->id, (string) $snapshot->macrocycle_id);
        $this->assertSame((string) $meso->id, (string) $snapshot->mesociclo_id);
        $this->assertSame((string) $micro->id, (string) $snapshot->microciclo_id);
        $this->assertSame((string) $v1->id, (string) $snapshot->training_plan_version_id);
        $this->assertNotNull($snapshot->plan_applied_at);
        $this->assertSame((string) $actor->id, (string) $snapshot->plan_applied_by);
        $this->assertSame('published', $snapshot->session_status);
        $this->assertCount(1, $snapshot->series);
        $this->assertSame('plan_version', $snapshot->series->first()->source);
        $this->assertSame((string) $v1->series->first()->id, (string) $snapshot->series->first()->training_plan_series_id);
        $this->assertSame('Crawl técnico', $snapshot->series->first()->descricao_texto);
        $this->assertSame(400, (int) $snapshot->series->first()->distancia_total_m);

        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Técnico',
            'descricao_treino' => 'Versão revista',
            'series_linhas' => [
                [
                    'bloco' => 'Principal',
                    'repeticoes' => 4,
                    'metros' => 100,
                    'exercicio' => 'Costas técnico',
                    'zona' => 'Z2',
                ],
            ],
        ], $actor, 'Revisão H3b');

        $unchanged = $session->fresh(['series']);
        $this->assertNotSame((string) $v1->id, (string) $v2->id);
        $this->assertSame((string) $v1->id, (string) $unchanged->training_plan_version_id);
        $this->assertSame((string) $v1->series->first()->id, (string) $unchanged->series->first()->training_plan_series_id);
        $this->assertSame('Crawl técnico', $unchanged->series->first()->descricao_texto);
        $this->assertSame(400, (int) $unchanged->series->first()->distancia_total_m);
    }

    private function season(): Season
    {
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        return Season::query()->create([
            'club_id' => 'bscn',
            'sports_modality_id' => $modality->id,
            'nome' => '2026/27',
            'ano_temporada' => '2026/27',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'status' => 'active',
        ]);
    }
}
