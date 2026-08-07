<?php

namespace Tests\Concerns;

use App\Models\PermissionNode;
use App\Models\User;
use App\Models\UserType;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Support\Str;

trait GrantsDesportivoAccess
{
    /**
     * @param list<string> $permissionKeys
     */
    protected function grantDesportivoAccess(
        User $user,
        array $permissionKeys = [
            'desportivo.dashboard',
            'desportivo.treinos',
            'desportivo.presencas',
            'desportivo.competicoes',
            'desportivo.resultados',
        ],
        bool $canEdit = true,
        bool $canDelete = true,
    ): UserType {
        $userType = UserType::query()->create([
            'codigo' => 'sports_test_' . Str::lower(Str::random(10)),
            'nome' => 'Sports Test ' . Str::random(8),
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user->userTypes()->syncWithoutDetaching([$userType->id]);

        $accessControl = app(UserTypeAccessControlService::class);
        $accessControl->syncMenuModules($userType, ['desportivo']);

        $permissions = collect($permissionKeys)
            ->map(function (string $permissionKey, int $index) use ($canEdit, $canDelete): array {
                $node = PermissionNode::query()->firstOrCreate(
                    ['key' => $permissionKey],
                    [
                        'label' => Str::headline(str_replace('desportivo.', '', $permissionKey)),
                        'module_key' => 'desportivo',
                        'node_type' => 'submodule',
                        'sort_order' => $index + 1,
                        'active' => true,
                    ],
                );

                return [
                    'permission_node_id' => $node->id,
                    'can_view' => true,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                ];
            })
            ->values()
            ->all();

        $accessControl->syncPermissions($userType, $permissions);

        $user->unsetRelation('userTypes');

        return $userType;
    }
}
