<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembrosMonthlyFeeStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_index_filters_members_with_and_without_a_defined_monthly_fee(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade de teste',
            'valor' => 30.00,
            'ativo' => true,
        ]);
        $configuredMember = User::factory()->create();
        DadosFinanceiros::query()->create([
            'user_id' => $configuredMember->id,
            'mensalidade_id' => $plan->id,
        ]);
        $memberWithEmptyFinancialData = User::factory()->create();
        DadosFinanceiros::query()->create([
            'user_id' => $memberWithEmptyFinancialData->id,
            'mensalidade_id' => null,
        ]);
        $memberWithoutFinancialData = User::factory()->create();

        $definedResponse = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'monthly_fee_status' => 'defined',
        ]));

        $definedResponse->assertOk();
        $definedResponse->assertJsonPath('props.filters.monthly_fee_status', 'defined');
        $definedIds = collect($definedResponse->json('props.members'))->pluck('id');
        $this->assertEqualsCanonicalizing([$configuredMember->id], $definedIds->all());

        $undefinedResponse = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'monthly_fee_status' => 'undefined',
        ]));

        $undefinedResponse->assertOk();
        $undefinedResponse->assertJsonPath('props.filters.monthly_fee_status', 'undefined');
        $undefinedIds = collect($undefinedResponse->json('props.members'))->pluck('id');
        $this->assertTrue($undefinedIds->contains($admin->id));
        $this->assertTrue($undefinedIds->contains($memberWithEmptyFinancialData->id));
        $this->assertTrue($undefinedIds->contains($memberWithoutFinancialData->id));
        $this->assertFalse($undefinedIds->contains($configuredMember->id));
    }

    public function test_monthly_fee_filter_is_exposed_in_the_list_and_preserved_in_pagination(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Membros/ListTab.tsx'));

        $this->assertStringContainsString('Todas as mensalidades', $source);
        $this->assertStringContainsString('Com mensalidade definida', $source);
        $this->assertStringContainsString('Sem mensalidade definida', $source);
        $this->assertStringContainsString("updateServerFilters({ monthly_fee_status: value })", $source);

        $admin = User::factory()->admin()->create();
        User::factory()->count(11)->create();

        $response = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'monthly_fee_status' => 'undefined',
            'per_page' => 10,
        ]));

        $response->assertOk();
        $this->assertSame(2, $response->json('props.membersPagination.last_page'));

        $paginationUrls = collect($response->json('props.membersPagination.links'))
            ->pluck('url')
            ->filter();

        $this->assertTrue($paginationUrls->contains(
            static fn (string $url): bool => str_contains($url, 'monthly_fee_status=undefined')
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
