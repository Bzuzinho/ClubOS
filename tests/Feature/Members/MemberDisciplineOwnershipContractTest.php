<?php

namespace Tests\Feature\Members;

use App\Models\KeyValueStore;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MemberDisciplineOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_discipline_keys_require_discipline_view_permission(): void
    {
        $user = User::factory()->create();
        KeyValueStore::setValue('club-discipline-status', [['user_id' => (string) $user->id, 'estado' => 'normal']]);
        KeyValueStore::setValue('club-discipline-records', [['id' => 'record-1', 'user_id' => (string) $user->id]]);

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'membros.ficha.desportivo.disciplina'
                && $capability === 'view'
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        foreach (['club-discipline-status', 'club-discipline-records'] as $key) {
            $this->actingAs($user)->getJson("/api/kv/{$key}")->assertOk();
            $this->actingAs($user)->putJson("/api/kv/{$key}", ['value' => []])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/kv/{$key}")->assertForbidden();
        }
    }

    public function test_member_discipline_keys_reject_users_without_discipline_permission(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')->andReturnFalse();
        $this->app->instance(UserTypeAccessControlService::class, $access);

        foreach (['club-discipline-status', 'club-discipline-records'] as $key) {
            $this->actingAs($user)->getJson("/api/kv/{$key}")->assertForbidden();
            $this->actingAs($user)->putJson("/api/kv/{$key}", ['value' => []])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/kv/{$key}")->assertForbidden();
        }
    }

    public function test_member_discipline_edit_and_delete_follow_matching_capabilities(): void
    {
        $user = User::factory()->create();

        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessPermission')
            ->andReturnUsing(fn ($actualUser, string $permission, string $capability): bool =>
                $actualUser->is($user)
                && $permission === 'membros.ficha.desportivo.disciplina'
                && in_array($capability, ['edit', 'delete'], true)
            );
        $this->app->instance(UserTypeAccessControlService::class, $access);

        $payload = [[
            'id' => 'record-1',
            'user_id' => (string) $user->id,
            'data' => '2026-09-25',
            'descricao_comportamento' => 'Registo disciplinar',
            'classificacao' => 3,
        ]];

        $this->actingAs($user)
            ->putJson('/api/kv/club-discipline-records', ['value' => $payload])
            ->assertOk();

        $this->assertSame($payload, KeyValueStore::getValue('club-discipline-records'));

        $this->actingAs($user)
            ->deleteJson('/api/kv/club-discipline-records')
            ->assertOk();

        $this->assertNull(KeyValueStore::getValue('club-discipline-records'));
    }

    public function test_discipline_ui_uses_only_the_owned_kv_keys(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/Sports/PlaneamentoTab.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("useKV<DisciplinaryStatusRow[]>('club-discipline-status', [])", $source);
        $this->assertStringContainsString("useKV<ParticipationRecord[]>('club-discipline-records', [])", $source);
    }
}
