<?php

namespace Tests\Feature\Sports;

use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use App\Services\Desportivo\SportsDashboardWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsDashboardWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_dashboard_uses_canonical_participation_and_real_assignments(): void
    {
        $athlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ]);
        $modality = SportsModality::query()->create([
            'club_id' => 'bscn',
            'code' => 'NAT',
            'name' => 'Natação',
            'active' => true,
        ]);
        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $athlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subMonth()->toDateString(),
            'source' => 'test',
        ]);

        $training = Training::query()->create([
            'club_id' => 'bscn',
            'data' => now()->toDateString(),
            'tipo_treino' => 'Técnico',
            'session_status' => 'published',
            'volume_planeado_m' => 2000,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => true,
            'estado' => 'presente',
            'volume_real_m' => 1800,
            'rpe' => 6,
        ]);

        Training::query()->create([
            'club_id' => 'bscn',
            'data' => now()->toDateString(),
            'tipo_treino' => 'Técnico',
            'session_status' => 'cancelled',
            'volume_planeado_m' => 9000,
        ]);

        $payload = app(SportsDashboardWorkspaceService::class)->workspace();

        $this->assertSame(1, $payload['stats']['active_athletes']);
        $this->assertSame(1, $payload['stats']['trainings_30d']);
        $this->assertSame(100.0, $payload['stats']['attendance_30d']);
        $this->assertSame(1800, $payload['stats']['executed_volume_30d_m']);
        $this->assertTrue($payload['principles']['canonical_athletes']);
        $this->assertTrue($payload['principles']['cancelled_trainings_excluded']);
        $this->assertFalse($payload['principles']['competition_title_date_matching']);
    }

    public function test_dashboard_routes_are_dedicated_and_root_is_cut_over(): void
    {
        $routes = file_get_contents(base_path('routes/desportivo_dashboard.php'));
        $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertStringContainsString("->get('/dashboard'", $routes);
        $this->assertStringContainsString("SportsDashboardWorkspaceController::class", $provider);
        $this->assertStringContainsString("->get('/desportivo', [SportsDashboardWorkspaceController::class, 'index'])", $provider);
    }
}
