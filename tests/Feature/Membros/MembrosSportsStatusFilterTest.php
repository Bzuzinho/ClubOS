<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembrosSportsStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_index_filters_active_and_non_active_sports_status(): void
    {
        $admin = User::factory()->admin()->create();
        $activeSportsMember = User::factory()->create(['ativo_desportivo' => true]);
        $inactiveSportsMember = User::factory()->create(['ativo_desportivo' => false]);

        $activeResponse = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'sports_status' => 'ativo',
        ]));

        $activeResponse->assertOk();
        $activeResponse->assertJsonPath('props.filters.sports_status', 'ativo');
        $activeIds = collect($activeResponse->json('props.members'))->pluck('id');
        $this->assertTrue($activeIds->contains($activeSportsMember->id));
        $this->assertFalse($activeIds->contains($inactiveSportsMember->id));
        $this->assertFalse($activeIds->contains($admin->id));

        $inactiveResponse = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'sports_status' => 'inativo',
        ]));

        $inactiveResponse->assertOk();
        $inactiveResponse->assertJsonPath('props.filters.sports_status', 'inativo');
        $inactiveIds = collect($inactiveResponse->json('props.members'))->pluck('id');
        $this->assertTrue($inactiveIds->contains($inactiveSportsMember->id));
        $this->assertTrue($inactiveIds->contains($admin->id));
        $this->assertFalse($inactiveIds->contains($activeSportsMember->id));
    }

    public function test_members_list_exposes_the_sports_status_filter_options(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Membros/ListTab.tsx'));

        $this->assertStringContainsString('Todos os estados desportivos', $source);
        $this->assertStringContainsString('<SelectItem value="ativo">Ativo</SelectItem>', $source);
        $this->assertStringContainsString('<SelectItem value="inativo">Não ativo</SelectItem>', $source);
        $this->assertStringContainsString("updateServerFilters({ sports_status: value })", $source);
    }

    public function test_sports_status_filter_is_preserved_in_pagination_links(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(11)->create(['ativo_desportivo' => true]);

        $response = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'sports_status' => 'ativo',
            'per_page' => 10,
        ]));

        $response->assertOk();
        $this->assertSame(2, $response->json('props.membersPagination.last_page'));

        $paginationUrls = collect($response->json('props.membersPagination.links'))
            ->pluck('url')
            ->filter();

        $this->assertTrue($paginationUrls->contains(
            static fn (string $url): bool => str_contains($url, 'sports_status=ativo')
        ));
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }
}
