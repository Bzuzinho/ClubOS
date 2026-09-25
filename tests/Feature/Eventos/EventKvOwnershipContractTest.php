<?php

namespace Tests\Feature\Eventos;

use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\KeyValue\EventosKeyValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EventKvOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_transversal_consumers_can_read_event_projections_without_gaining_write_access(): void
    {
        $user = User::factory()->create();

        $readPermissions = [
            'desportivo.competicoes',
            'desportivo.presencas',
            'membros.ficha.desportivo.resultados',
            'membros.ficha.desportivo.convocatorias',
            'membros.ficha.desportivo.presencas',
        ];

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $capability === 'view'
                && in_array($permission, $readPermissions, true)
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->andReturnTrue();
        $eventos->shouldReceive('get')->andReturn([]);
        $eventos->shouldReceive('set')->never();
        $eventos->shouldReceive('delete')->never();
        $this->app->instance(EventosKeyValueService::class, $eventos);

        foreach (['club-events', 'club-presencas'] as $key) {
            $this->actingAs($user)->getJson("/api/kv/{$key}")->assertOk();
            $this->actingAs($user)->putJson("/api/kv/{$key}", ['value' => []])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/kv/{$key}")->assertForbidden();
        }

        foreach (['club-convocatorias-grupo', 'club-convocatorias-atleta'] as $key) {
            $this->actingAs($user)->getJson("/api/kv/{$key}")->assertOk();
            $this->actingAs($user)->putJson("/api/kv/{$key}", ['value' => []])->assertStatus(410);
            $this->actingAs($user)->deleteJson("/api/kv/{$key}")->assertStatus(410);
        }
    }

    public function test_member_dashboard_can_read_required_event_projections_without_gaining_write_access(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'membros.ficha.dashboard'
                && $capability === 'view'
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->andReturnTrue();
        $eventos->shouldReceive('get')->times(3)->andReturn([]);
        $eventos->shouldReceive('set')->never();
        $eventos->shouldReceive('delete')->never();
        $this->app->instance(EventosKeyValueService::class, $eventos);

        foreach (['club-events', 'club-presencas', 'club-resultados-provas'] as $key) {
            $this->actingAs($user)->getJson("/api/kv/{$key}")->assertOk();
            $this->actingAs($user)->putJson("/api/kv/{$key}", ['value' => []])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/kv/{$key}")->assertForbidden();
        }

        $this->actingAs($user)
            ->getJson('/api/kv/club-resultados')
            ->assertForbidden();
    }


    public function test_event_permissions_keep_event_and_presence_writers_but_not_convocation_kv_writers(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $capability === 'edit'
                && in_array($permission, ['eventos.calendario', 'eventos.resultados'], true)
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->andReturnTrue();
        $eventos->shouldReceive('set')->once()->with('club-presencas', [], null);
        $this->app->instance(EventosKeyValueService::class, $eventos);

        $this->actingAs($user)
            ->putJson('/api/kv/club-presencas', ['value' => []])
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', ['value' => []])
            ->assertStatus(410);
    }

    public function test_member_event_projection_surfaces_are_read_only_consumers(): void
    {
        $convocations = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/ConvocatoriasTab.tsx'));
        $attendances = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/RegistoPresencasTab.tsx'));

        $this->assertIsString($convocations);
        $this->assertIsString($attendances);

        $this->assertStringContainsString("const [convocatoriasAtleta] = useKV<ConvocatoriaAtleta[]>('club-convocatorias-atleta', [])", $convocations);
        $this->assertStringContainsString("const [convocatoriasGrupo] = useKV<ConvocatoriaGrupo[]>('club-convocatorias-grupo', [])", $convocations);
        $this->assertStringNotContainsString('setConvocatoriasAtleta', $convocations);
        $this->assertStringNotContainsString('setConvocatoriasGrupo', $convocations);

        $this->assertStringContainsString("const [presencas] = useKV<EventoPresenca[]>('club-presencas', [])", $attendances);
        $this->assertStringNotContainsString('setPresencas', $attendances);
    }

    public function test_retired_legacy_convocation_components_are_not_present(): void
    {
        foreach ([
            'js/Components/Eventos/ConvocatoriasList.tsx',
            'js/Components/Eventos/CreateConvocatoriaDialog.tsx',
            'js/Components/Eventos/EditConvocatoriaDialog.tsx',
        ] as $path) {
            $this->assertFileDoesNotExist(resource_path($path));
        }
    }
}
