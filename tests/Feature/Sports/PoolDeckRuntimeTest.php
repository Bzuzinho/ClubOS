<?php

namespace Tests\Feature\Sports;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingPoolDeckSyncConflict;
use App\Models\TrainingPoolDeckTimer;
use App\Models\TrainingPoolDeckTimerEvent;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Desportivo\PoolDeckMetricService;
use App\Services\Desportivo\PoolDeckSessionService;
use App\Services\Desportivo\PoolDeckTimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PoolDeckRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sports.club_id' => 'bscn']);
    }

    public function test_pr6_schema_uses_canonical_training_metrics_and_runtime_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('trainings', [
            'pool_deck_status', 'pool_deck_version', 'pool_deck_opened_at', 'pool_deck_closed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('training_athletes', [
            'cais_version', 'cais_status_source', 'cais_last_modified_at',
        ]));
        $this->assertTrue(Schema::hasColumns('training_metrics', [
            'club_id', 'training_series_id', 'total_distance_m', 'duration_ms', 'splits_json', 'client_event_id',
        ]));
        $this->assertTrue(Schema::hasTable('training_pool_deck_timers'));
        $this->assertTrue(Schema::hasTable('training_pool_deck_timer_events'));
        $this->assertTrue(Schema::hasTable('training_pool_deck_sync_conflicts'));
    }

    public function test_opening_pool_deck_assumes_assigned_athletes_present_but_preserves_advance_response(): void
    {
        [$training, $actor] = $this->trainingWithSeries();
        $default = $this->attachAthlete($training, User::factory()->athlete()->create());
        $advance = $this->attachAthlete($training, User::factory()->athlete()->create());
        $advance->forceFill([
            'estado' => 'justificado',
            'presente' => false,
            'atualizado_por_utilizador_em' => now()->subHour(),
        ])->save();

        app(PoolDeckSessionService::class)->open($training, $actor);

        $this->assertSame('presente', $default->fresh()->estado);
        $this->assertTrue($default->fresh()->presente);
        $this->assertSame('pool_deck_default', $default->fresh()->cais_status_source);
        $this->assertSame('justificado', $advance->fresh()->estado);
        $this->assertFalse($advance->fresh()->presente);
        $this->assertSame('open', $training->fresh()->pool_deck_status);
    }

    public function test_stale_offline_simple_write_is_preserved_as_conflict_instead_of_overwriting_newer_server_state(): void
    {
        [$training, $actor] = $this->trainingWithSeries();
        $record = $this->attachAthlete($training, User::factory()->athlete()->create());
        $service = app(PoolDeckSessionService::class);
        $service->open($training, $actor);

        $service->updateAthlete($record->fresh(), [
            'estado' => 'limitado',
            'observacoes_tecnicas' => 'Carga reduzida',
            'client_version' => 1,
            'client_modified_at' => now()->toIso8601String(),
        ], $actor);

        $server = $record->fresh();
        $service->updateAthlete($server, [
            'estado' => 'ausente',
            'client_version' => 0,
            'client_modified_at' => now()->subHours(2)->toIso8601String(),
            'client_event_id' => 'offline-old-1',
        ], $actor);

        $this->assertSame('limitado', $record->fresh()->estado);
        $this->assertTrue(TrainingPoolDeckSyncConflict::query()
            ->where('training_id', $training->id)
            ->where('field', 'estado')
            ->exists());
    }

    public function test_metric_requires_exercise_and_distance_and_preserves_splits_per_athlete(): void
    {
        [$training, $actor, $series] = $this->trainingWithSeries();
        $record = $this->attachAthlete($training, User::factory()->athlete()->create());
        app(PoolDeckSessionService::class)->open($training, $actor);

        $metric = app(PoolDeckMetricService::class)->record($training->fresh(), [
            'training_athlete_id' => $record->id,
            'training_series_id' => $series->id,
            'measurement_type' => 'time',
            'total_distance_m' => 100,
            'duration_ms' => 61234,
            'repetition_mode' => 'repetition',
            'repetition_number' => 2,
            'splits' => [
                ['distance_m' => 50, 'duration_ms' => 30100],
                ['distance_m' => 50, 'duration_ms' => 31134],
            ],
            'client_event_id' => 'metric-a-1',
        ], $actor);

        $this->assertSame((string) $series->id, (string) $metric->training_series_id);
        $this->assertSame(100, $metric->total_distance_m);
        $this->assertSame(61234, $metric->duration_ms);
        $this->assertSame(2, $metric->repetition_number);
        $this->assertCount(2, $metric->splits_json);
        $this->assertSame(50, $metric->splits_json[0]['distance_m']);
        $this->assertSame('01:01.234', $metric->tempo);

        $retry = app(PoolDeckMetricService::class)->record($training->fresh(), [
            'training_athlete_id' => $record->id,
            'training_series_id' => $series->id,
            'measurement_type' => 'time',
            'total_distance_m' => 100,
            'duration_ms' => 61234,
            'client_event_id' => 'metric-a-1',
        ], $actor);

        $this->assertSame($metric->id, $retry->id);
    }

    public function test_multiple_independent_timers_can_run_and_events_are_audited(): void
    {
        [$training, $actor, $series] = $this->trainingWithSeries();
        $service = app(PoolDeckSessionService::class);
        $timerService = app(PoolDeckTimerService::class);
        $records = collect(range(1, 12))->map(fn () => $this->attachAthlete($training, User::factory()->athlete()->create()));
        $service->open($training, $actor);

        foreach ($records as $index => $record) {
            $timerService->start($training->fresh(), [
                'subject_type' => 'athlete',
                'training_athlete_id' => $record->id,
                'training_series_id' => $series->id,
                'exercise_label' => '8x50 crawl',
                'client_timer_id' => 'timer-'.$index,
                'client_event_id' => 'timer-start-'.$index,
            ], $actor);
        }

        $this->assertSame(12, TrainingPoolDeckTimer::query()->where('training_id', $training->id)->count());
        $this->assertSame(12, TrainingPoolDeckTimerEvent::query()->where('event_type', 'start')->count());

        $first = TrainingPoolDeckTimer::query()->where('client_timer_id', 'timer-0')->firstOrFail();
        $timerService->event($first, 'pause', ['client_event_id' => 'pause-0'], $actor);
        $timerService->event($first->fresh(), 'resume', ['client_event_id' => 'resume-0'], $actor);
        $timerService->event($first->fresh(), 'stop', ['client_event_id' => 'stop-0'], $actor);

        $this->assertSame('stopped', $first->fresh()->timer_state);
        $this->assertSame(4, $first->events()->count());
    }

    public function test_session_cannot_close_with_active_timers_and_becomes_completed_after_timers_stop(): void
    {
        [$training, $actor, $series] = $this->trainingWithSeries();
        $record = $this->attachAthlete($training, User::factory()->athlete()->create());
        $sessions = app(PoolDeckSessionService::class);
        $timers = app(PoolDeckTimerService::class);
        $sessions->open($training, $actor);

        $this->assertSame('open', $training->fresh()->pool_deck_status, 'A sessão deve estar aberta antes de iniciar timers.');

        $timer = $timers->start($training->fresh(), [
            'subject_type' => 'athlete',
            'training_athlete_id' => $record->id,
            'training_series_id' => $series->id,
            'exercise_label' => '100 crawl',
        ], $actor);

        $this->assertSame('running', $timer->fresh()->timer_state, 'O cronómetro deve ficar running após start.');
        $this->assertSame(
            1,
            TrainingPoolDeckTimer::query()
                ->where('club_id', 'bscn')
                ->where('training_id', $training->id)
                ->whereIn('timer_state', ['running', 'paused'])
                ->count(),
            'Deve existir exatamente um cronómetro ativo antes da primeira tentativa de fecho.',
        );

        try {
            $sessions->close($training->fresh(), $actor);
            $this->fail('Closing with an active timer should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'timers',
                $exception->errors(),
                'O bloqueio de fecho deve ser causado por timers ativos. Erros: '.json_encode($exception->errors(), JSON_UNESCAPED_UNICODE),
            );
        }

        $this->assertSame('open', $training->fresh()->pool_deck_status, 'Uma tentativa de fecho bloqueada não pode alterar o estado da sessão.');

        $timers->event($timer->fresh(), 'stop', [], $actor);

        $this->assertSame('stopped', $timer->fresh()->timer_state, 'O evento stop deve persistir o estado stopped.');
        $this->assertSame(
            0,
            TrainingPoolDeckTimer::query()
                ->where('club_id', 'bscn')
                ->where('training_id', $training->id)
                ->whereIn('timer_state', ['running', 'paused'])
                ->count(),
            'Depois do stop não podem permanecer cronómetros ativos.',
        );
        $this->assertSame('open', $training->fresh()->pool_deck_status, 'Parar o cronómetro não deve fechar a sessão.');

        $closed = $sessions->close($training->fresh(), $actor);

        $this->assertSame('closed', $closed->pool_deck_status);
        $this->assertSame('completed', $closed->session_status);
        $this->assertNotNull($closed->completed_at);
    }

    public function test_pool_deck_runtime_is_tenant_scoped(): void
    {
        [$training, $actor] = $this->trainingWithSeries();
        config(['sports.club_id' => 'other-club']);

        $this->expectException(ValidationException::class);
        app(PoolDeckSessionService::class)->open($training, $actor);
    }

    /** @return array{0:Training,1:User,2:TrainingSeries} */
    private function trainingWithSeries(): array
    {
        $actor = User::factory()->create();
        $training = Training::query()->create([
            'numero_treino' => '#CAIS-'.substr((string) $actor->id, 0, 6),
            'data' => now()->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'local' => 'Piscina',
            'tipo_treino' => 'Técnico',
            'club_id' => 'bscn',
            'session_status' => 'published',
            'pool_deck_status' => 'planned',
            'criado_por' => $actor->id,
        ]);
        $series = TrainingSeries::query()->create([
            'treino_id' => $training->id,
            'ordem' => 1,
            'descricao_texto' => '8x50 crawl',
            'repeticoes' => 8,
            'distancia_m' => 50,
            'distancia_total_m' => 400,
            'source' => 'manual',
        ]);

        return [$training, $actor, $series];
    }

    private function attachAthlete(Training $training, User $athlete): TrainingAthlete
    {
        return TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => false,
            'estado' => 'ausente',
            'registado_em' => now(),
        ]);
    }
}
