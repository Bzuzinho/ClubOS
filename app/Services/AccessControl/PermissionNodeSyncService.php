<?php

namespace App\Services\AccessControl;

use App\Models\PermissionNode;
use App\Models\UserType;
use App\Models\UserTypeMenuModule;
use App\Models\UserTypePermission;
use App\Support\AccessControl\AccessControlCatalog;

class PermissionNodeSyncService
{
    private const OPERATIONAL_COMPATIBILITY_MODULE_KEYS = [
        'logistica',
        'loja',
        'patrocinios',
        'comunicacao',
        'marketing',
    ];

    public function sync(): int
    {
        $definitions = AccessControlCatalog::permissionTree();
        $sortOrder = 1;
        $syncedKeys = [];
        $newOperationalRootNodes = [];
        $userTypeIdsWithGranularPermissions = UserTypePermission::query()
            ->whereNotNull('permission_node_id')
            ->distinct()
            ->pluck('user_type_id')
            ->all();

        $syncNodes = function (array $nodes, ?PermissionNode $parent = null) use (&$syncNodes, &$sortOrder, &$syncedKeys, &$newOperationalRootNodes): void {
            foreach ($nodes as $nodeDefinition) {
                $node = PermissionNode::query()->updateOrCreate(
                    ['key' => $nodeDefinition['key']],
                    [
                        'label' => $nodeDefinition['label'],
                        'parent_id' => $parent?->id,
                        'module_key' => $nodeDefinition['module_key'],
                        'node_type' => $nodeDefinition['node_type'],
                        'sort_order' => $sortOrder++,
                        'active' => true,
                    ]
                );

                $syncedKeys[] = $node->key;

                if (
                    $node->wasRecentlyCreated
                    && $parent === null
                    && $node->node_type === 'module'
                    && in_array($node->module_key, self::OPERATIONAL_COMPATIBILITY_MODULE_KEYS, true)
                ) {
                    $newOperationalRootNodes[] = $node;
                }

                $syncNodes($nodeDefinition['children'] ?? [], $node);
            }
        };

        $syncNodes($definitions);

        PermissionNode::query()
            ->whereNotIn('key', $syncedKeys)
            ->update(['active' => false]);

        $this->backfillOperationalModuleAccess(
            $newOperationalRootNodes,
            $userTypeIdsWithGranularPermissions,
        );

        return count($syncedKeys);
    }

    /**
     * Preserve the pre-granularity runtime contract for operational modules.
     *
     * User types with zero granular rows deliberately remain untouched because
     * the access service treats an empty permission set as unrestricted inside
     * visible modules. For user types that already use granular permissions,
     * newly introduced operational module roots inherit the full CRUD access
     * they previously received from module.access alone.
     *
     * @param array<int, PermissionNode> $newOperationalRootNodes
     * @param array<int, string> $userTypeIdsWithGranularPermissions
     */
    private function backfillOperationalModuleAccess(
        array $newOperationalRootNodes,
        array $userTypeIdsWithGranularPermissions,
    ): void {
        if ($newOperationalRootNodes === [] || $userTypeIdsWithGranularPermissions === []) {
            return;
        }

        $userTypes = UserType::query()
            ->whereIn('id', $userTypeIdsWithGranularPermissions)
            ->get(['id', 'menu_visibility_configured']);

        foreach ($userTypes as $userType) {
            foreach ($newOperationalRootNodes as $permissionNode) {
                $moduleVisible = ! (bool) $userType->menu_visibility_configured
                    || UserTypeMenuModule::query()
                        ->where('user_type_id', $userType->id)
                        ->where('module_key', $permissionNode->module_key)
                        ->exists();

                if (! $moduleVisible) {
                    continue;
                }

                UserTypePermission::query()->firstOrCreate(
                    [
                        'user_type_id' => $userType->id,
                        'permission_node_id' => $permissionNode->id,
                    ],
                    [
                        'modulo' => $permissionNode->module_key,
                        'submodulo' => null,
                        'separador' => null,
                        'campo' => null,
                        'can_view' => true,
                        'can_edit' => true,
                        'can_delete' => true,
                        'pode_ver' => true,
                        'pode_criar' => true,
                        'pode_editar' => true,
                        'pode_eliminar' => true,
                    ]
                );
            }
        }
    }
}
