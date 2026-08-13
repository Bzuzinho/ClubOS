<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\Training;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Desportivo\TrainingSessionOperationService;
use App\Services\Desportivo\TrainingSessionPlanService;
use App\Services\Desportivo\TrainingSessionReadinessService;
use App\Services\Desportivo\UpdateTrainingScheduleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrainingWorkspaceOperationalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_cancellation_preserves_session_and_blocks_future_mutations(): void
    {
        $actor = User::factory()->create(); $training = $this->training($actor, 'published');
        $cancelled = app(TrainingSessionOperationService::class)->cancel($training, 'Piscina encerrada por avaria.', $actor);
        $this->assertDatabaseHas('trainings', ['id' => $training->id,'session_status' => 'cancelled','cancelled_by' => $actor->id,'cancellation_reason' => 'Piscina encerrada por avaria.']);
        $this->assertNotNull($cancelled->cancelled_at); $this->assertTrue($cancelled->isCancelled()); $this->assertTrue($cancelled->isOperationallyClosed());
        try {
            app(UpdateTrainingScheduleAction::class)->execute($cancelled, ['hora_inicio' => '19:00'], $actor);
            $this->fail('Uma sessão cancelada não pode voltar a ser alterada pelo Planeamento.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('training', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        app(TrainingSessionPlanService::class)->complete($cancelled->fresh());
    }

    public function test_session_snapshot_override_recalculates_volume_and_writes_audit_revision(): void
    {
        $actor = User::factory()->create(); $training = $this->training($actor, 'published');
        TrainingSeries::query()->create(['treino_id' => $training->id,'ordem' => 1,'descricao_texto' => 'Original','repeticoes' => 8,'distancia_m' => 50,'distancia_total_m' => 400,'source' => 'manual','block_name' => 'Principal','block_order' => 1,'block_rounds' => 1,'timing_mode' => 'each_rep']);
        $updated = app(TrainingSessionOperationService::class)->overrideSnapshot($training, [[
            'name' => 'Principal','rounds' => 2,'series' => [[
                'repeticoes' => 4,'distancia_m' => 50,'exercicio' => 'Livre forte','timing_mode' => 'each_rep','intervalo' => '15"','saida' => null,'material_ids' => [],
            ]],
        ]], 'Redução de volume por limitação de tempo.', $actor);
        $this->assertSame(400, (int) $updated->volume_planeado_m); $this->assertNotNull($updated->content_override_at);
        $this->assertSame((string) $actor->id, (string) $updated->content_override_by);
        $line = $updated->series()->firstOrFail();
        $this->assertSame(4, (int) $line->repeticoes); $this->assertSame(50, (int) $line->distancia_m); $this->assertSame(2, (int) $line->block_rounds); $this->assertSame('session_override', $line->source);
        $revision = $updated->contentRevisions()->firstOrFail();
        $this->assertSame('snapshot_override', $revision->revision_type);
        $this->assertSame(8, (int) data_get($revision->before_snapshot, 'series.0.repeticoes'));
        $this->assertSame(4, (int) data_get($revision->after_snapshot, 'series.0.repeticoes'));
    }

    public function test_readiness_distinguishes_attention_decision_and_closed_sessions(): void
    {
        $actor = User::factory()->create(); $training = $this->training($actor, 'draft');
        $attention = app(TrainingSessionReadinessService::class)->evaluate($training->fresh(['series','sessionGroups.lanes','athleteRecords','planVersion']));
        $this->assertSame('attention', $attention['status']);
        $training->forceFill(['session_status' => 'published','schedule_conflicts_snapshot' => [['type' => 'closure','severity' => 'decision_required','message' => 'Pista encerrada.']]])->save();
        $decision = app(TrainingSessionReadinessService::class)->evaluate($training->fresh(['series','sessionGroups.lanes','athleteRecords','planVersion']));
        $this->assertSame('decision', $decision['status']);
        $cancelled = app(TrainingSessionOperationService::class)->cancel($training->fresh(), 'Encerramento confirmado.', $actor);
        $closed = app(TrainingSessionReadinessService::class)->evaluate($cancelled->fresh(['series','sessionGroups.lanes','athleteRecords','planVersion']));
        $this->assertSame('closed', $closed['status']); $this->assertSame('cancelled', $closed['closed_reason']);
    }

    private function training(User $actor, string $status): Training
    {
        return Training::query()->create(['numero_treino' => '#TW01','data' => now()->addDay()->toDateString(),'hora_inicio' => '18:00','hora_fim' => '19:30','tipo_treino' => 'Técnico','club_id' => 'bscn','session_status' => $status,'criado_por' => $actor->id]);
    }
}
