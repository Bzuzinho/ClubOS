<?php

declare(strict_types=1);

namespace Tests\Feature\AccessControl;

use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministratorAuthorityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_administrator_cannot_mutate_access_control_even_when_default_permissions_are_open(): void
    {
        $actor = User::factory()->create(['perfil' => 'tesouraria']);
        $targetType = UserType::query()->create([
            'codigo' => 'treinador-governance',
            'nome' => 'Treinador Governance',
            'ativo' => true,
        ]);

        $this->actingAs($actor)
            ->putJson("/api/access-control/user-types/{$targetType->id}/menu-modules", [
                'module_keys' => ['dashboard'],
            ])
            ->assertForbidden();

        $this->assertFalse((bool) $targetType->fresh()->menu_visibility_configured);
    }

    public function test_administrator_can_mutate_access_control_without_being_blocked_by_matrix_configuration(): void
    {
        $actor = User::factory()->create(['perfil' => 'admin']);
        $administratorType = UserType::query()->create([
            'codigo' => 'administrador',
            'nome' => 'Administrador',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);
        $actor->userTypes()->attach($administratorType->id);

        $targetType = UserType::query()->create([
            'codigo' => 'direcao-governance',
            'nome' => 'Direção Governance',
            'ativo' => true,
        ]);

        $this->actingAs($actor)
            ->putJson("/api/access-control/user-types/{$targetType->id}/menu-modules", [
                'module_keys' => ['dashboard'],
            ])
            ->assertOk()
            ->assertJsonPath('menuModuleKeys.0', 'dashboard');

        $this->assertTrue((bool) $targetType->fresh()->menu_visibility_configured);
    }

    public function test_non_administrator_may_read_access_control_when_normal_view_policy_allows_it(): void
    {
        $actor = User::factory()->create(['perfil' => 'direcao']);
        $targetType = UserType::query()->create([
            'codigo' => 'socio-governance',
            'nome' => 'Sócio Governance',
            'ativo' => true,
        ]);

        $this->actingAs($actor)
            ->getJson("/api/access-control/user-types/{$targetType->id}")
            ->assertOk();
    }

    public function test_canonical_administrator_type_cannot_be_deleted_or_deactivated(): void
    {
        $actor = User::factory()->create(['perfil' => 'admin']);
        $administratorType = UserType::query()->create([
            'codigo' => 'administrador',
            'nome' => 'Administrador',
            'descricao' => 'Autoridade máxima do ClubOS',
            'ativo' => true,
        ]);

        $this->actingAs($actor)
            ->delete(route('configuracoes.tipos-utilizador.destroy', $administratorType))
            ->assertStatus(422);

        $this->assertDatabaseHas('user_types', [
            'id' => $administratorType->id,
            'ativo' => true,
        ]);

        $this->actingAs($actor)
            ->put(route('configuracoes.tipos-utilizador.update', $administratorType), [
                'nome' => 'Administrador',
                'descricao' => 'Autoridade máxima do ClubOS',
                'ativo' => false,
            ])
            ->assertStatus(422);

        $this->assertTrue((bool) $administratorType->fresh()->ativo);
    }
}
