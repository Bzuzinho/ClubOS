<?php

namespace Tests\Feature\Sports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SportsFinalIntegrationCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_training_presence_and_cais_writes_are_closed(): void
    {
        $admin = User::factory()->create();
        $trainingId = '00000000-0000-4000-8000-000000000001';
        $athleteId = '00000000-0000-4000-8000-000000000002';

        $this->actingAs($admin)->post('/desportivo/treinos')->assertStatus(410);
        $this->actingAs($admin)->put('/desportivo/treinos/'.$trainingId)->assertStatus(410);
        $this->actingAs($admin)->delete('/desportivo/treinos/'.$trainingId)->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/treinos/'.$trainingId.'/agendar')->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/treinos/'.$trainingId.'/duplicar')->assertStatus(410);
        $this->actingAs($admin)->put('/desportivo/treinos/'.$trainingId.'/presencas')->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/treinos/'.$trainingId.'/atletas')->assertStatus(410);
        $this->actingAs($admin)->delete('/desportivo/treinos/'.$trainingId.'/atletas/'.$athleteId)->assertStatus(410);
        $this->actingAs($admin)->put('/desportivo/presencas')->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/presencas/marcar-presentes')->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/presencas/limpar')->assertStatus(410);
        $this->actingAs($admin)->get('/desportivo/cais/metricas')->assertStatus(410);
        $this->actingAs($admin)->post('/desportivo/cais/metricas')->assertStatus(410);
    }

    public function test_canonical_training_and_cais_routes_are_not_blocked_by_cutover(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/desportivo/treinos')->assertStatus(200);
        $this->actingAs($admin)->get('/desportivo/cais')->assertStatus(200);
    }

    public function test_cutover_middleware_declares_all_retired_runtime_boundaries(): void
    {
        $source = file_get_contents(base_path('app/Http/Middleware/EnforceSportsLegacyCutover.php'));

        $this->assertStringContainsString("segment(2) === 'presencas'", $source);
        $this->assertStringContainsString('isLegacyTrainingMutation', $source);
        $this->assertStringContainsString('isLegacyCaisMetricEndpoint', $source);
        $this->assertStringContainsString("['epocas', 'macrociclos', 'mesociclos']", $source);
    }
}
