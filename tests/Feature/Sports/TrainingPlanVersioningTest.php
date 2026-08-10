<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\Training;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanVersion;
use App\Models\User;
use App\Services\Desportivo\CreateTrainingAction;
use App\Services\Desportivo\TrainingPlanService;
use App\Services\Desportivo\TrainingSessionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrainingPlanVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_pr4_schema_separates_reusable_plan_from_scheduled_session(): void
    {
        $this->assertTrue(Schema::hasTable('training_plans'));
        $this->assertTrue(Schema::hasTable('training_plan_versions'));
        $this->assertTrue(Schema::hasTable('training_plan_series'));
        $this->assertTrue(Schema::hasColumns('trainings', [
            'club_id',
            'training_plan_version_id',
            'session_status',
            'plan_applied_at',
            'completed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('training_series', [
            'training_plan_version_id',
            'training_plan_series_id',
            'source',
        ]));
    }

    public function test_plan_revision_creates_new_immutable_version_and_keeps_old_series(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $v1 = $plan->versions()->with('series')->firstOrFail();
        $v1LineId = (string) $v1->series->first()->id;

        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Aeróbio',
            'descricao_treino' => 'Versão revista',
            'series_linhas' => [
                ['bloco' => 'Principal', 'repeticoes' => 10, 'metros' => 100, 'exercicio' => 'Crawl', 'zona' => 'Z2'],
            ],
        ], $actor, 'Aumentar volume');

        $this->assertSame(1, (int) $v1->version);
        $this->assertSame(2, (int) $v2->version);
        $this->assertSame('Aumentar volume', $v2->motivo_revisao);

        $old = TrainingPlanVersion::query()->with('series')->findOrFail($v1->id);
        $this->assertSame('Técnico inicial', $old->descricao_treino);
        $this->assertSame($v1LineId, (string) $old->series->first()->id);
        $this->assertSame('Técnica de crawl', $old->series->first()->exercicio);
        $this->assertSame(800, (int) $old->volume_planeado_m);
        $this->assertSame(1000, (int) $v2->volume_planeado_m);
    }

    public function test_session_keeps_snapshot_until_selected_future_update_is_requested(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $v1 = $plan->versions()->with('series')->firstOrFail();
        $session = $this->createSession($actor, now()->addDays(5)->toDateString());

        $sessionService = app(TrainingSessionPlanService::class);
        $sessionService->assign($session, $v1, $actor);

        $this->assertSame((string) $v1->id, (string) $session->fresh()->training_plan_version_id);
        $this->assertSame('plan_version', $session->fresh()->series()->firstOrFail()->source);
        $this->assertSame('Técnica de crawl', $session->fresh()->series()->firstOrFail()->descricao_texto);

        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Técnico',
            'series_linhas' => [
                ['repeticoes' => 4, 'metros' => 200, 'exercicio' => 'Pernas', 'zona' => 'Z2'],
            ],
        ], $actor);

        // Criar v2 não altera a sessão já agendada.
        $this->assertSame((string) $v1->id, (string) $session->fresh()->training_plan_version_id);
        $this->assertSame('Técnica de crawl', $session->fresh()->series()->firstOrFail()->descricao_texto);

        $updated = $sessionService->updateSelectedFutureSessions($v1, $v2, [$session->id], $actor);

        $this->assertCount(1, $updated);
        $this->assertSame((string) $v2->id, (string) $session->fresh()->training_plan_version_id);
        $this->assertSame('Pernas', $session->fresh()->series()->firstOrFail()->descricao_texto);
    }

    public function test_future_update_changes_only_explicitly_selected_sessions(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $v1 = $plan->versions()->firstOrFail();
        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Técnico',
            'series_linhas' => [
                ['repeticoes' => 12, 'metros' => 50, 'exercicio' => 'Costas'],
            ],
        ], $actor);

        $first = $this->createSession($actor, now()->addDays(3)->toDateString(), '#F001');
        $second = $this->createSession($actor, now()->addDays(4)->toDateString(), '#F002');
        $service = app(TrainingSessionPlanService::class);
        $service->assign($first, $v1, $actor);
        $service->assign($second, $v1, $actor);

        $service->updateSelectedFutureSessions($v1, $v2, [$first->id], $actor);

        $this->assertSame((string) $v2->id, (string) $first->fresh()->training_plan_version_id);
        $this->assertSame((string) $v1->id, (string) $second->fresh()->training_plan_version_id);
    }

    public function test_completed_session_cannot_be_changed_by_new_plan_version(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $v1 = $plan->versions()->firstOrFail();
        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Técnico',
            'series_linhas' => [['repeticoes' => 2, 'metros' => 400, 'exercicio' => 'Contínuo']],
        ], $actor);

        $session = $this->createSession($actor, now()->addDay()->toDateString());
        $service = app(TrainingSessionPlanService::class);
        $service->assign($session, $v1, $actor);
        $service->complete($session);

        $this->expectException(ValidationException::class);
        $service->assign($session->fresh(), $v2, $actor);
    }

    public function test_past_session_is_rejected_from_bulk_future_version_update(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $v1 = $plan->versions()->firstOrFail();
        $v2 = app(TrainingPlanService::class)->revise($plan, [
            'tipo_treino' => 'Técnico',
            'series_linhas' => [['repeticoes' => 8, 'metros' => 50, 'exercicio' => 'Técnica']],
        ], $actor);

        $past = $this->createSession($actor, now()->subDay()->toDateString());
        $service = app(TrainingSessionPlanService::class);
        $service->assign($past, $v1, $actor);

        $this->expectException(ValidationException::class);
        $service->updateSelectedFutureSessions($v1, $v2, [$past->id], $actor);
    }

    public function test_create_training_action_can_schedule_session_from_plan_without_duplicate_builder_payload(): void
    {
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $version = $plan->versions()->firstOrFail();

        $session = app(CreateTrainingAction::class)->execute([
            'data' => now()->addWeek()->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'local' => 'Piscina Municipal',
            'training_plan_version_id' => $version->id,
            'responsavel_id' => $actor->id,
        ], $actor);

        $this->assertSame('bscn', $session->club_id);
        $this->assertSame('draft', $session->session_status);
        $this->assertSame((string) $version->id, (string) $session->training_plan_version_id);
        $this->assertSame('Técnico', $session->tipo_treino);
        $this->assertSame(2, $session->series()->count());
        $this->assertSame(800, (int) $session->volume_planeado_m);
        $this->assertTrue($session->series()->get()->every(fn ($line) => $line->source === 'plan_version'));
    }

    public function test_plan_and_session_writes_use_configured_sports_tenant(): void
    {
        config()->set('sports.club_id', 'club-test');
        $actor = User::factory()->create();
        $plan = $this->createPlan($actor);
        $version = $plan->versions()->firstOrFail();
        $session = $this->createSession($actor, now()->addDay()->toDateString());

        app(TrainingSessionPlanService::class)->assign($session, $version, $actor);

        $this->assertSame('club-test', $plan->club_id);
        $this->assertSame('club-test', $version->club_id);
        $this->assertSame('club-test', $session->fresh()->club_id);
    }

    private function createPlan(User $actor): TrainingPlan
    {
        return app(TrainingPlanService::class)->create([
            'nome' => 'Plano técnico base',
            'codigo' => 'TEC-BASE-' . substr((string) $actor->id, 0, 8),
            'tipo_treino' => 'Técnico',
            'descricao_treino' => 'Técnico inicial',
            'series_linhas' => [
                ['bloco' => 'Aquecimento', 'repeticoes' => 4, 'metros' => 100, 'exercicio' => 'Técnica de crawl', 'zona' => 'Z1'],
                ['bloco' => 'Principal', 'repeticoes' => 8, 'metros' => 50, 'exercicio' => 'Educativos', 'zona' => 'Z2'],
            ],
        ], $actor);
    }

    private function createSession(User $actor, string $date, string $number = '#S001'): Training
    {
        return Training::query()->create([
            'numero_treino' => $number,
            'data' => $date,
            'hora_inicio' => '18:00',
            'hora_fim' => '19:00',
            'local' => 'Piscina',
            'tipo_treino' => 'Manual',
            'club_id' => (string) config('sports.club_id'),
            'session_status' => 'draft',
            'criado_por' => $actor->id,
        ]);
    }
}
