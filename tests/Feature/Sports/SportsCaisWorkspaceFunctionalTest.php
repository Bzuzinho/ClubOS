<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Models\User;
use App\Services\Desportivo\PrepareTrainingAthletesAction;
use App\Services\Desportivo\SportsCaisWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SportsCaisWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_newly_prepared_athletes_are_present_by_default(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $training = $this->training($actor);

        app(PrepareTrainingAthletesAction::class)->executeForUsers($training, [(string) $athlete->id]);

        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => true,
            'estado' => 'presente',
        ]);
    }

    public function test_quick_and_full_register_share_the_same_metric_state(): void
    {
        [$actor, $athlete, $training] = $this->preparedTraining();
        $service = app(SportsCaisWorkspaceService::class);

        $quick = $service->saveQuick($training, $athlete, 'behavior', 'Atenção', $actor);
        $this->assertSame('Atenção', data_get($quick, 'register.behavior'));

        $full = $service->saveRegister($training, $athlete, [
            'status' => 'presente',
            'behavior' => 'Atenção',
            'material' => 'Falta: palas',
            'technical_note' => 'Cotovelo alto.',
            'advice' => 'Controlar respiração.',
            'metrics' => [['code' => 'heart_rate', 'value' => '164']],
        ], $actor);

        $this->assertSame('Atenção', data_get($full, 'register.behavior'));
        $this->assertSame('Falta: palas', data_get($full, 'register.material'));
        $this->assertSame('164', collect(data_get($full, 'register.metrics'))->firstWhere('code', 'heart_rate')['value']);

        $materialQuick = $service->saveQuick($training, $athlete, 'material', 'Completo', $actor);
        $this->assertSame('Atenção', data_get($materialQuick, 'register.behavior'));
        $this->assertSame('Completo', data_get($materialQuick, 'register.material'));
        $this->assertSame('Cotovelo alto.', data_get($materialQuick, 'register.technical_note'));
        $this->assertSame('164', collect(data_get($materialQuick, 'register.metrics'))->firstWhere('code', 'heart_rate')['value']);

        $this->assertSame(5, TrainingMetric::query()->where('treino_id', $training->id)->where('user_id', $athlete->id)->count());
    }

    public function test_late_status_still_counts_as_present(): void
    {
        [$actor, $athlete, $training] = $this->preparedTraining();

        $record = app(SportsCaisWorkspaceService::class)->updatePresence($training, $athlete, 'atrasado', $actor);

        $this->assertSame('atrasado', $record->estado);
        $this->assertTrue((bool) $record->presente);
    }

    public function test_cancelled_and_completed_sessions_are_not_executable_in_cais(): void
    {
        $actor = User::factory()->create();
        $active = $this->training($actor, 'published');
        $cancelled = $this->training($actor, 'cancelled');
        $completed = $this->training($actor, 'completed');
        $request = Request::create('/desportivo/cais', 'GET', ['date' => $active->data->toDateString()]);

        $payload = app(SportsCaisWorkspaceService::class)->payload($request);
        $ids = collect($payload['sessions'])->pluck('id');

        $this->assertTrue($ids->contains((string) $active->id));
        $this->assertFalse($ids->contains((string) $cancelled->id));
        $this->assertFalse($ids->contains((string) $completed->id));
    }

    private function preparedTraining(): array
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['estado' => 'ativo', 'tipo_membro' => ['atleta'], 'ativo_desportivo' => true]);
        $training = $this->training($actor);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => true,
            'estado' => 'presente',
            'registado_por' => $actor->id,
            'registado_em' => now(),
        ]);
        return [$actor, $athlete, $training];
    }

    private function training(User $actor, string $status = 'published'): Training
    {
        return Training::query()->create([
            'numero_treino' => '#CAIS',
            'data' => now()->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'tipo_treino' => 'Técnico',
            'club_id' => 'bscn',
            'session_status' => $status,
            'criado_por' => $actor->id,
        ]);
    }
}
