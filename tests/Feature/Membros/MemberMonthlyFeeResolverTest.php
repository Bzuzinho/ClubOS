<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberMonthlyFeeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_canonical_monthly_fee_when_present(): void
    {
        $canonicalPlan = $this->createPlan('Plano Canonico Resolver');
        $legacyPlan = $this->createPlan('Plano Legacy Resolver');

        $user = User::factory()->create([
            'tipo_mensalidade' => $legacyPlan->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $canonicalPlan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);

        $this->assertSame($canonicalPlan->id, $resolver->resolveForUser($user->fresh('dadosFinanceiros')));
    }

    public function test_returns_null_when_canonical_is_empty_even_if_legacy_exists(): void
    {
        $legacyPlan = $this->createPlan('Plano Legacy Only');

        $user = User::factory()->create([
            'tipo_mensalidade' => $legacyPlan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);

        $this->assertNull($resolver->resolveForUser($user));
    }

    public function test_returns_null_when_both_sources_are_empty(): void
    {
        $user = User::factory()->create([
            'tipo_mensalidade' => null,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => null,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);

        $this->assertNull($resolver->resolveForUser($user->fresh('dadosFinanceiros')));
    }

    public function test_detects_divergence_between_canonical_and_legacy(): void
    {
        $canonicalPlan = $this->createPlan('Plano Canonico Divergente');
        $legacyPlan = $this->createPlan('Plano Legacy Divergente');

        $user = User::factory()->create([
            'tipo_mensalidade' => $legacyPlan->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $canonicalPlan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);
        $divergence = $resolver->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertTrue($divergence['has_divergence']);
        $this->assertSame($canonicalPlan->id, $divergence['canonical_monthly_fee_id']);
        $this->assertSame($legacyPlan->id, $divergence['legacy_monthly_fee_id']);
    }

    public function test_equal_values_are_not_divergent(): void
    {
        $plan = $this->createPlan('Plano Igual');

        $user = User::factory()->create([
            'tipo_mensalidade' => $plan->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);
        $divergence = $resolver->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertFalse($divergence['has_divergence']);
    }

    public function test_normalizes_ids_before_comparing(): void
    {
        $plan = $this->createPlan('Plano Trim');

        $user = User::factory()->create([
            'tipo_mensalidade' => '  ' . $plan->id . '  ',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);
        $divergence = $resolver->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertFalse($divergence['has_divergence']);
        $this->assertSame($plan->id, $resolver->resolveForUser($user->fresh('dadosFinanceiros')));
    }

    private function createPlan(string $designacao): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designacao,
            'valor' => 30,
            'ativo' => true,
        ]);
    }
}
