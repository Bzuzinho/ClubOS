<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Controllers\RelacoesMembroController;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LegacyMemberRelationshipRuntimeRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_relationship_controller_is_a_read_write_tombstone(): void
    {
        $member = User::factory()->create();
        $related = User::factory()->create();
        $relationship = new UserRelationship([
            'user_id' => $member->id,
            'related_user_id' => $related->id,
            'type' => 'familiar',
        ]);
        $controller = app(RelacoesMembroController::class);

        $index = $controller->index($member);
        $store = $controller->store(Request::create('/', 'POST'), $member);
        $destroy = $controller->destroy($member, $relationship);

        foreach ([$index, $store, $destroy] as $response) {
            $this->assertSame(410, $response->getStatusCode());
            $payload = json_decode((string) $response->getContent(), true);
            $this->assertSame('membros.familia.*', $payload['replacement'] ?? null);
        }

        $this->assertDatabaseCount('user_relationships', 0);
    }

    public function test_canonical_family_routes_remain_the_supported_runtime_surface(): void
    {
        $this->assertTrue(Route::has('membros.familia.encarregados.store'));
        $this->assertTrue(Route::has('membros.familia.encarregados.destroy'));
        $this->assertTrue(Route::has('membros.familia.membros.store'));
        $this->assertTrue(Route::has('membros.familia.membros.update'));
        $this->assertTrue(Route::has('membros.familia.membros.destroy'));
    }

    public function test_legacy_controller_source_contains_no_relationship_persistence(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/RelacoesMembroController.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('relationships()->create', $source);
        $this->assertStringNotContainsString('UserRelationship::firstOrCreate', $source);
        $this->assertStringNotContainsString('->delete()', $source);
        $this->assertStringContainsString('410', $source);
    }
}
