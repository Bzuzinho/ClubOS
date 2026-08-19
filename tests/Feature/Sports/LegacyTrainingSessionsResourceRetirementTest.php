<?php

namespace Tests\Feature\Sports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyTrainingSessionsResourceRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_training_sessions_get_routes_redirect_without_touching_removed_table(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/sessoes-formacao')
            ->assertRedirect(route('desportivo.treinos'));

        $this->actingAs($user)
            ->get('/sessoes-formacao/criar')
            ->assertRedirect(route('desportivo.treinos'));

        $this->actingAs($user)
            ->get('/sessoes-formacao/legacy-session')
            ->assertRedirect(route('desportivo.treinos'));

        $this->actingAs($user)
            ->get('/sessoes-formacao/legacy-session/edit')
            ->assertRedirect(route('desportivo.treinos'));
    }

    public function test_legacy_training_sessions_writes_are_gone(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post('/sessoes-formacao', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->put('/sessoes-formacao/legacy-session', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->delete('/sessoes-formacao/legacy-session')
            ->assertStatus(410);
    }

    public function test_legacy_controller_has_no_training_session_runtime_dependency(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/SessoesFormacaoController.php'));

        $this->assertIsString($controller);
        $this->assertStringNotContainsString('App\\Models\\TrainingSession', $controller);
        $this->assertStringNotContainsString('TrainingSession::', $controller);
        $this->assertStringNotContainsString('StoreTrainingSessionRequest', $controller);
        $this->assertStringNotContainsString('UpdateTrainingSessionRequest', $controller);
        $this->assertStringContainsString("route('desportivo.treinos')", $controller);
        $this->assertStringContainsString('410', $controller);
    }
}
