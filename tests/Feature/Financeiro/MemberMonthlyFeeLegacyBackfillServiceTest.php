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

    public function test_legacy_only_valid_member_is_ready_for_backfill(): void
    {
        $plan = $this->createPlan('Plano Legacy Ready');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('ready_for_backfill', $case['classification'] ?? null);
        $this->assertFalse((bool) ($case['uses_legacy_fallback'] ?? true));
        $this->assertSame($plan->id, $case['canonical_payload_candidate']['mensalidade_id'] ?? null);
    }

    public function test_canonical_present_is_already_canonical(): void
    {
        $plan = $this->createPlan('Plano Canonico');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('already_canonical', $case['classification'] ?? null);
    }

    public function test_matching_is_already_canonical(): void
    {
        $plan = $this->createPlan('Plano Matching');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('already_canonical', $case['classification'] ?? null);
    }

    public function test_divergent_case_is_classified_as_divergent(): void
    {
        $canonical = $this->createPlan('Plano Divergente Canonico');
        $legacy = $this->createPlan('Plano Divergente Legacy');

        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $legacy->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $canonical->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('divergent', $case['classification'] ?? null);
    }

    public function test_missing_legacy_reference_is_invalid_legacy_reference(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => 'missing-plan-id',
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('invalid_legacy_reference', $case['classification'] ?? null);
        $this->assertFalse((bool) ($case['reference_valid'] ?? true));
    }

    public function test_missing_required_case_is_classified_as_missing_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('missing_required', $case['classification'] ?? null);
    }

    public function test_member_without_requirement_is_not_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'inativo',
            'tipo_mensalidade' => null,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('not_required', $case['classification'] ?? null);
    }

    public function test_ready_for_backfill_requires_legacy_only_source(): void
    {
        $plan = $this->createPlan('Plano Sem Fallback');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $payload = app(MemberMonthlyFeeLegacyBackfillService::class)->analyze((string) $user->id);
        $case = $this->findCase($payload, (string) $user->id);

        $this->assertSame('already_canonical', $case['classification'] ?? null);
        $this->assertFalse((bool) ($case['uses_legacy_fallback'] ?? true));
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
