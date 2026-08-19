<?php

namespace Tests\Feature\Sports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyCallUpsResourceRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_call_ups_get_routes_redirect_without_touching_removed_table(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/convocatorias')
            ->assertRedirect(route('desportivo.convocatorias.index'));

        $this->actingAs($user)
            ->get('/convocatorias/create')
            ->assertRedirect(route('desportivo.convocatorias.index'));

        $this->actingAs($user)
            ->get('/convocatorias/legacy-call-up')
            ->assertRedirect(route('desportivo.convocatorias.index'));

        $this->actingAs($user)
            ->get('/convocatorias/legacy-call-up/edit')
            ->assertRedirect(route('desportivo.convocatorias.index'));
    }

    public function test_legacy_call_ups_writes_are_gone(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post('/convocatorias', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->put('/convocatorias/legacy-call-up', [])
            ->assertStatus(410);

        $this->actingAs($user)
            ->delete('/convocatorias/legacy-call-up')
            ->assertStatus(410);
    }

    public function test_legacy_controller_has_no_call_up_runtime_dependency(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/ConvocatoriasController.php'));

        $this->assertIsString($controller);
        $this->assertStringNotContainsString('App\\Models\\CallUp', $controller);
        $this->assertStringNotContainsString('CallUp::', $controller);
        $this->assertStringNotContainsString('StoreCallUpRequest', $controller);
        $this->assertStringNotContainsString('UpdateCallUpRequest', $controller);
        $this->assertStringContainsString("route('desportivo.convocatorias.index')", $controller);
        $this->assertStringContainsString('410', $controller);
    }
}
