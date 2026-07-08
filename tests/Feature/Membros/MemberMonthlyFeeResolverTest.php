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

        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $canonicalPlan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);

        $this->assertSame($canonicalPlan->id, $resolver->resolveForUser($user->fresh('dadosFinanceiros')));
    }

    public function test_returns_null_when_canonical_is_empty(): void
    {
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => null,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);

        $this->assertNull($resolver->resolveForUser($user->fresh('dadosFinanceiros')));
    }

    public function test_detects_no_divergence_when_only_canonical_source_exists(): void
    {
        $plan = $this->createPlan('Plano Canonico Sem Divergencia');
        $user = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $resolver = app(MemberMonthlyFeeResolver::class);
        $divergence = $resolver->detectDivergence($user->fresh('dadosFinanceiros'));

        $this->assertFalse($divergence['has_divergence']);
        $this->assertFalse($divergence['has_legacy_fallback']);
        $this->assertFalse($divergence['uses_legacy_fallback']);
        $this->assertSame($plan->id, $divergence['canonical_monthly_fee_id']);
        $this->assertNull($divergence['legacy_monthly_fee_id']);
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
