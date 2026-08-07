<?php

namespace Tests\Concerns;

use App\Models\PermissionNode;
use App\Models\User;
use App\Models\UserType;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

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
            'desportivo.treinos.cais',
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
                $node = $this->desportivoPermissionNode($permissionKey, $index);

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

    protected function desportivoPermissionNode(string $permissionKey, int $index): PermissionNode
    {
        $existing = PermissionNode::query()->where('key', $permissionKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $node = new PermissionNode([
            'label' => Str::headline(str_replace('desportivo.', '', $permissionKey)),
            'module_key' => 'desportivo',
            'node_type' => 'submodule',
            'sort_order' => $index + 1,
            'active' => true,
        ]);
        $node->id = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'clubos.tests.permission.' . $permissionKey)->toString();
        $node->key = $permissionKey;
        $node->save();

        return $node;
    }
}
