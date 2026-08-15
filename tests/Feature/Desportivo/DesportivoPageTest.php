<?php

namespace Tests\Feature\Desportivo;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Training;
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

    public function test_legacy_presencas_route_redirects_to_cais_with_selected_training(): void
    {
        $admin = User::factory()->create();
        $training = Training::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'numero_treino' => 'T-100',
            'data' => now()->toDateString(),
            'tipo_treino' => 'tecnico',
            'session_status' => 'published',
            'criado_por' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('desportivo.presencas', ['training_id' => $training->id]));

        $response->assertRedirect('/desportivo/cais?training_id='.rawurlencode((string) $training->id));
        $response->assertSessionHas('warning');
    }
}
