<?php

namespace Tests\Feature\AccessControl;

use App\Models\PermissionNode;
use App\Models\UserType;
use App\Models\UserTypeMenuModule;
use App\Models\UserTypePermission;
use App\Services\AccessControl\PermissionNodeSyncService;
use App\Support\AccessControl\AccessControlCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalPermissionGranularityTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_granular_nodes_for_all_operational_modules(): void
    {
        $tree = collect(AccessControlCatalog::permissionTree())->keyBy('key');

        $expectedChildren = [
            'logistica' => ['logistica.dashboard', 'logistica.requisicoes', 'logistica.stock', 'logistica.emprestimos', 'logistica.fornecedores'],
            'loja' => ['loja.dashboard', 'loja.produtos', 'loja.encomendas', 'loja.hero'],
            'patrocinios' => ['patrocinios.dashboard', 'patrocinios.integracoes'],
            'comunicacao' => ['comunicacao.dashboard', 'comunicacao.campanhas', 'comunicacao.entregas', 'comunicacao.modelos', 'comunicacao.segmentos', 'comunicacao.alertas'],
            'marketing' => ['marketing.campanhas'],
        ];

        foreach ($expectedChildren as $moduleKey => $childKeys) {
            $this->assertTrue($tree->has($moduleKey), "Missing operational permission module [{$moduleKey}].");
            $this->assertSame('module', $tree[$moduleKey]['node_type']);
            $this->assertSame(
                $childKeys,
                collect($tree[$moduleKey]['children'])->pluck('key')->all(),
            );
        }
    }

    public function test_first_sync_preserves_existing_granular_users_access_to_visible_operational_modules(): void
    {
        $configuredType = UserType::query()->create([
            'codigo' => 'configured-ops',
            'nome' => 'Configured Ops',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        UserTypeMenuModule::query()->create([
            'user_type_id' => $configuredType->id,
            'module_key' => 'logistica',
            'sort_order' => 1,
        ]);
        UserTypeMenuModule::query()->create([
            'user_type_id' => $configuredType->id,
            'module_key' => 'marketing',
            'sort_order' => 2,
        ]);

        $this->seedExistingGranularPermission($configuredType);

        $unrestrictedType = UserType::query()->create([
            'codigo' => 'unrestricted-ops',
            'nome' => 'Unrestricted Ops',
            'ativo' => true,
            'menu_visibility_configured' => false,
        ]);

        app(PermissionNodeSyncService::class)->sync();

        foreach (['logistica', 'marketing'] as $moduleKey) {
            $nodeId = PermissionNode::query()->where('key', $moduleKey)->value('id');

            $this->assertDatabaseHas('user_type_permissions', [
                'user_type_id' => $configuredType->id,
                'permission_node_id' => $nodeId,
                'modulo' => $moduleKey,
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'pode_ver' => true,
                'pode_criar' => true,
                'pode_editar' => true,
                'pode_eliminar' => true,
            ]);
        }

        foreach (['loja', 'patrocinios', 'comunicacao'] as $moduleKey) {
            $nodeId = PermissionNode::query()->where('key', $moduleKey)->value('id');

            $this->assertDatabaseMissing('user_type_permissions', [
                'user_type_id' => $configuredType->id,
                'permission_node_id' => $nodeId,
            ]);
        }

        $this->assertSame(
            0,
            UserTypePermission::query()->where('user_type_id', $unrestrictedType->id)->count(),
            'A user type with an empty granular permission set must remain empty/unrestricted.',
        );
    }

    public function test_subsequent_sync_does_not_restore_an_operational_grant_removed_by_an_administrator(): void
    {
        $userType = UserType::query()->create([
            'codigo' => 'configured-once',
            'nome' => 'Configured Once',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        UserTypeMenuModule::query()->create([
            'user_type_id' => $userType->id,
            'module_key' => 'logistica',
            'sort_order' => 1,
        ]);

        $this->seedExistingGranularPermission($userType);

        $service = app(PermissionNodeSyncService::class);
        $service->sync();

        $logisticsNodeId = PermissionNode::query()->where('key', 'logistica')->value('id');

        $this->assertDatabaseHas('user_type_permissions', [
            'user_type_id' => $userType->id,
            'permission_node_id' => $logisticsNodeId,
        ]);

        UserTypePermission::query()
            ->where('user_type_id', $userType->id)
            ->where('permission_node_id', $logisticsNodeId)
            ->delete();

        $service->sync();

        $this->assertDatabaseMissing('user_type_permissions', [
            'user_type_id' => $userType->id,
            'permission_node_id' => $logisticsNodeId,
        ]);
    }

    private function seedExistingGranularPermission(UserType $userType): void
    {
        $existingNode = PermissionNode::query()->create([
            'key' => 'membros',
            'label' => 'Membros',
            'parent_id' => null,
            'module_key' => 'membros',
            'node_type' => 'module',
            'sort_order' => 1,
            'active' => true,
        ]);

        UserTypePermission::query()->create([
            'user_type_id' => $userType->id,
            'permission_node_id' => $existingNode->id,
            'modulo' => 'membros',
            'submodulo' => null,
            'separador' => null,
            'campo' => null,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'pode_ver' => true,
            'pode_criar' => false,
            'pode_editar' => false,
            'pode_eliminar' => false,
        ]);
    }
}
