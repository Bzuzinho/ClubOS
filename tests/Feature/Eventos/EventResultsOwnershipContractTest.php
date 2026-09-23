<?php

namespace Tests\Feature\Eventos;

use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\KeyValue\EventosKeyValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EventResultsOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_result_permission_can_read_event_results_but_cannot_mutate_them(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $capability === 'view'
                && $permission === 'membros.ficha.desportivo.resultados'
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->with('club-resultados-provas')->andReturnTrue();
        $eventos->shouldReceive('get')->once()->with('club-resultados-provas', null)->andReturn([]);
        $eventos->shouldReceive('set')->never();
        $this->app->instance(EventosKeyValueService::class, $eventos);

        $this->actingAs($user)
            ->getJson('/api/kv/club-resultados-provas')
            ->assertOk()
            ->assertJsonPath('value', []);

        $this->actingAs($user)
            ->putJson('/api/kv/club-resultados-provas', ['value' => []])
            ->assertForbidden();
    }

    public function test_event_results_permission_remains_the_mutation_owner(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'eventos.resultados'
                && $capability === 'edit'
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->with('club-resultados-provas')->andReturnTrue();
        $eventos->shouldReceive('set')->once()->with('club-resultados-provas', [], null);
        $this->app->instance(EventosKeyValueService::class, $eventos);

        $this->actingAs($user)
            ->putJson('/api/kv/club-resultados-provas', ['value' => []])
            ->assertOk()
            ->assertJsonPath('key', 'club-resultados-provas');
    }

    public function test_member_results_ui_has_no_event_result_mutation_controls(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/ResultadosTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("useKV<ResultadoProva[]>('club-resultados-provas', [])", $source);
        $this->assertStringContainsString('Eventos > Resultados', $source);
        $this->assertStringNotContainsString('setResultadosProvas', $source);
        $this->assertStringNotContainsString('Adicionar Resultado', $source);
        $this->assertStringNotContainsString('Editar Resultado', $source);
    }
}
