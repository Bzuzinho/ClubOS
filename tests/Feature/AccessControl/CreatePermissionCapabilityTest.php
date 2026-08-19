<?php

namespace Tests\Feature\AccessControl;

use App\Models\PermissionNode;
use App\Models\User;
use App\Models\UserType;
use App\Services\AccessControl\PermissionNodeSyncService;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CreatePermissionCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_capability_uses_existing_edit_permission_contract(): void
    {
        Route::post('/_test/access-control/create-capability', static fn () => response()->json(['ok' => true]))
            ->middleware('permission.access:website.paginas,create');

        $user = User::factory()->create();
        $userType = UserType::query()->create([
            'codigo' => 'editor_website',
            'nome' => 'Editor Website',
            'ativo' => true,
        ]);
        $user->userTypes()->attach($userType);

        app(PermissionNodeSyncService::class)->sync();
        $nodeId = PermissionNode::query()->where('key', 'website.paginas')->value('id');
        $this->assertNotNull($nodeId);

        app(UserTypeAccessControlService::class)->syncPermissions($userType, [[
            'permission_node_id' => $nodeId,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => false,
        ]]);

        $this->actingAs($user)
            ->post('/_test/access-control/create-capability')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_create_capability_is_denied_without_edit_permission(): void
    {
        Route::post('/_test/access-control/create-capability-denied', static fn () => response()->json(['ok' => true]))
            ->middleware('permission.access:website.paginas,create');

        $user = User::factory()->create();
        $userType = UserType::query()->create([
            'codigo' => 'website_read_only',
            'nome' => 'Website Read Only',
            'ativo' => true,
        ]);
        $user->userTypes()->attach($userType);

        app(PermissionNodeSyncService::class)->sync();
        $nodeId = PermissionNode::query()->where('key', 'website.paginas')->value('id');
        $this->assertNotNull($nodeId);

        app(UserTypeAccessControlService::class)->syncPermissions($userType, [[
            'permission_node_id' => $nodeId,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
        ]]);

        $this->actingAs($user)
            ->post('/_test/access-control/create-capability-denied')
            ->assertForbidden();
    }
}
