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

    public function test_desportivo_and_member_permissions_can_read_event_projections_but_cannot_mutate_them(): void
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

        foreach ([
            'club-events',
            'club-convocatorias-grupo',
            'club-convocatorias-atleta',
            'club-presencas',
        ] as $key) {
            $this->actingAs($user)
                ->getJson("/api/kv/{$key}")
                ->assertOk()
                ->assertJsonPath('value', []);

            $this->actingAs($user)
                ->putJson("/api/kv/{$key}", ['value' => []])
                ->assertForbidden();

            $this->actingAs($user)
                ->deleteJson("/api/kv/{$key}")
                ->assertForbidden();
        }
    }

    public function test_event_permissions_remain_the_mutation_owners_for_event_projections(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $capability === 'edit'
                && in_array($permission, ['eventos.convocatorias', 'eventos.resultados'], true)
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $eventos = Mockery::mock(EventosKeyValueService::class);
        $eventos->shouldReceive('supports')->andReturnTrue();
        $eventos->shouldReceive('set')->once()->with('club-convocatorias-grupo', [], null);
        $eventos->shouldReceive('set')->once()->with('club-presencas', [], null);
        $this->app->instance(EventosKeyValueService::class, $eventos);

        $this->actingAs($user)
            ->putJson('/api/kv/club-convocatorias-grupo', ['value' => []])
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/api/kv/club-presencas', ['value' => []])
            ->assertOk();
    }

    public function test_member_event_projection_surfaces_are_read_only_consumers(): void
    {
        $convocations = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/ConvocatoriasTab.tsx'));
        $attendances = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/RegistoPresencasTab.tsx'));

        $this->assertIsString($convocations);
        $this->assertIsString($attendances);

        $this->assertStringContainsString("const [convocatoriasAtleta] = useKV<ConvocatoriaAtleta[]>('club-convocatorias-atleta', [])", $convocations);
        $this->assertStringContainsString("const [convocatoriasGrupo] = useKV<ConvocatoriaGrupo[]>('club-convocatorias-grupo', [])", $convocations);
        $this->assertStringContainsString("const [events] = useKV<Event[]>('club-events', [])", $convocations);
        $this->assertStringNotContainsString('setConvocatoriasAtleta', $convocations);
        $this->assertStringNotContainsString('setConvocatoriasGrupo', $convocations);

        $this->assertStringContainsString("const [presencas] = useKV<EventoPresenca[]>('club-presencas', [])", $attendances);
        $this->assertStringContainsString("const [events] = useKV<Event[]>('club-events', [])", $attendances);
        $this->assertStringNotContainsString('setPresencas', $attendances);
        $this->assertStringNotContainsString('setEvents', $attendances);
    }
}
