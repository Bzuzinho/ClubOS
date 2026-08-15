<?php

namespace Tests\Feature\Desportivo;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesportivoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_desportivo_index_returns_inertia_component_for_authenticated_user(): void
    {
        $admin = User::factory()->create();
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        $response = $this->actingAs($admin)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get(route('desportivo.index'));

        $response->assertOk();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('component', 'Desportivo/DashboardWorkspace');
        $response->assertJsonStructure([
            'component',
            'props' => [
                'stats',
                'today',
                'upcoming_trainings',
                'upcoming_competitions',
                'alerts',
                'top_athletes',
                'quick_links',
                'principles',
            ],
        ]);
    }

    public function test_desportivo_presencas_route_sets_presencas_tab_and_selected_training(): void
    {
        $admin = User::factory()->create();
        $athlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ]);

        $event = Event::create([
            'titulo' => 'Treino de presenças V2',
            'descricao' => 'Evento treino',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'treino',
            'criado_por' => $admin->id,
        ]);

        $training = Training::create([
            'numero_treino' => 'T-100',
            'data' => now()->toDateString(),
            'tipo_treino' => 'tecnico',
            'evento_id' => $event->id,
            'criado_por' => $admin->id,
        ]);

        TrainingAthlete::create([
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
            'presente' => true,
            'estado' => 'presente',
            'registado_por' => $admin->id,
            'registado_em' => now(),
        ]);

        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        $response = $this->actingAs($admin)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get(route('desportivo.presencas', ['training_id' => $training->id]));

        $response->assertOk();
        $response->assertHeader('X-Inertia', 'true');
        $response->assertJsonPath('component', 'Desportivo/Index');
        $response->assertJsonPath('props.tab', 'presencas');
        $response->assertJsonStructure([
            'props' => [
                'trainings',
                'presences',
                'trainingOptions',
                'selectedTraining',
                'statusOptions',
            ],
        ]);

        $response->assertJsonPath('props.trainingOptions.0.id', $training->id);
    }
}
