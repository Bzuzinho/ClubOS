<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeLegacyBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberMonthlyFeeLegacyBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_reports_cleanup_completed_when_legacy_column_is_missing(): void
    {
        $plan = $this->createPlan('Plano Canonico');
        $user = User::factory()->create(['estado' => 'ativo']);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);

        $this->assertSame(1, (int) ($payload['summary']['total'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['counts']['cleanup_completed'] ?? 0));
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));

        $case = $this->findCase($payload, (string) $user->id);
        $this->assertSame('cleanup_completed', $case['classification'] ?? null);
        $this->assertSame($plan->id, $case['canonical_monthly_fee_id'] ?? null);
    }

    public function test_apply_is_no_op_after_cleanup(): void
    {
        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze();
        $applied = app(MemberMonthlyFeeLegacyBackfillService::class)->apply($payload);

        $this->assertSame(0, (int) ($applied['migration']['migrated_count'] ?? -1));
        $this->assertFalse((bool) ($applied['preflight']['can_apply'] ?? true));
    }

    private function createPlan(string $designacao): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designacao,
            'valor' => 30,
            'ativo' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function findCase(array $payload, string $userId): array
    {
        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            if ((string) ($case['user_id'] ?? '') === $userId) {
                return $case;
            }
        }

        $this->fail('Expected case not found in payload.');
    }
}
