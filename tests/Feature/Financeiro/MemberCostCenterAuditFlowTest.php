<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberCostCenterAuditFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_fee_generation_uses_canonical_cost_centers_when_pivot_exists(): void
    {
        $plan = $this->createMonthlyPlan(40.00);
        $user = $this->createEligibleUser($plan, ['data_inscricao' => '2026-05-01']);

        $canonicalCenter = $this->createCostCenter('CC-MONTHLY-CANONICAL', 'Centro Mensal Canonico');
        $legacyCenter = $this->createCostCenter('CC-MONTHLY-LEGACY', 'Centro Mensal Legacy');

        $this->attachCostCenter($user, $canonicalCenter->id, 3);
        $user->forceFill([
            'centro_custo' => [
                ['id' => $legacyCenter->id, 'peso' => 1],
            ],
        ])->save();

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user->fresh(['dadosFinanceiros', 'centrosCusto']), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame($canonicalCenter->id, $invoice->centro_custo_id);
        $this->assertSame($canonicalCenter->id, $invoice->items->first()->centro_custo_id);
        $this->assertCount(1, $invoice->items);
    }

    public function test_monthly_fee_generation_uses_legacy_fallback_and_logs_warning_when_pivot_is_empty(): void
    {
        Log::spy();

        $plan = $this->createMonthlyPlan(40.00);
        $user = $this->createEligibleUser($plan, ['data_inscricao' => '2026-05-01']);
        $legacyCenter = $this->createCostCenter('CC-MONTHLY-FALLBACK', 'Centro Mensal Fallback');

        $user->forceFill([
            'centro_custo' => [
                ['id' => $legacyCenter->id, 'peso' => 2],
            ],
        ])->save();

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user->fresh(['dadosFinanceiros']), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertNull($invoice->centro_custo_id);
        $this->assertNull($invoice->items->first()->centro_custo_id);
        $this->assertCount(1, $invoice->items);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_audit_command_returns_valid_json_and_classifies_cases(): void
    {
        $legacyFallbackUser = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);
        $legacyFallbackUser->forceFill([
            'centro_custo' => [
                ['id' => 'legacy-only-center', 'peso' => 1],
            ],
        ])->save();

        $divergentUser = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);
        $divergentCenter = $this->createCostCenter('CC-DIV-01', 'Centro Divergente');
        $this->attachCostCenter($divergentUser, $divergentCenter->id, 2);
        $divergentUser->forceFill([
            'centro_custo' => [
                ['id' => 'legacy-divergent-center', 'peso' => 1],
            ],
        ])->save();

        $invalidWeightUser = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);
        $invalidWeightUser->forceFill([
            'centro_custo' => [
                ['id' => 'legacy-invalid-center', 'peso' => 'abc'],
            ],
        ])->save();

        $missingRequiredUser = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);

        $exitCode = Artisan::call('finance:audit-member-cost-centers', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(4, (int) ($payload['summary']['total_users_analyzed'] ?? 0));
        $this->assertSame(2, (int) ($payload['summary']['legacy_fallback_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['divergent_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['invalid_weight_count'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($payload['summary']['missing_required_count'] ?? 0));

        $this->assertContains((string) $legacyFallbackUser->id, array_column($payload['legacy_fallback'] ?? [], 'id'));
        $this->assertContains((string) $invalidWeightUser->id, array_column($payload['legacy_fallback'] ?? [], 'id'));
        $this->assertContains((string) $divergentUser->id, array_column($payload['divergent'] ?? [], 'id'));
        $this->assertContains((string) $invalidWeightUser->id, array_column($payload['invalid_weights'] ?? [], 'id'));
        $this->assertContains((string) $missingRequiredUser->id, array_column($payload['missing_required'] ?? [], 'id'));
    }

    public function test_audit_command_fails_when_divergence_exists(): void
    {
        $user = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);
        $center = $this->createCostCenter('CC-DIV-FAIL', 'Centro Divergente Failure');

        $this->attachCostCenter($user, $center->id, 2);
        $user->forceFill([
            'centro_custo' => [
                ['id' => 'legacy-divergent-failure', 'peso' => 1],
            ],
        ])->save();

        $this->assertSame(1, Artisan::call('finance:audit-member-cost-centers', [
            '--fail-on-divergence' => true,
        ]));
    }

    public function test_audit_command_fails_when_legacy_fallback_exists(): void
    {
        $user = $this->createEligibleUser($this->createMonthlyPlan(), ['data_inscricao' => '2026-05-01']);
        $user->forceFill([
            'centro_custo' => [
                ['id' => 'legacy-fallback-guard', 'peso' => 1],
            ],
        ])->save();

        $this->assertSame(1, Artisan::call('finance:audit-member-cost-centers', [
            '--fail-on-fallback' => true,
        ]));
    }

    private function createMonthlyPlan(float $amount = 40.00): MonthlyFee
    {
        return MonthlyFee::create([
            'designacao' => 'Mensalidade Base',
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, array $overrides = [], array $financeOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Utilizador Elegivel Centro Custo',
            'email' => 'eligible-' . uniqid() . '@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ], $overrides));

        DadosFinanceiros::create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ] + $financeOverrides);

        if (in_array('atleta', (array) ($user->tipo_membro ?? []), true)) {
            $this->createUserTypeIfMissing('atleta', 'Atleta');
            $user->userTypes()->sync([$this->findUserTypeId('atleta')]);
        }

        return $user->fresh(['dadosFinanceiros']);
    }

    private function createCostCenter(string $codigo, string $nome): CostCenter
    {
        return CostCenter::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
    }

    private function attachCostCenter(User $user, string $centerId, float|int $peso): void
    {
        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'centro_custo_id' => $centerId,
            'peso' => $peso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserTypeIfMissing(string $codigo, string $nome): void
    {
        if (UserType::query()->where('codigo', $codigo)->exists()) {
            return;
        }

        UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);
    }

    private function findUserTypeId(string $codigo): string
    {
        return (string) UserType::query()->where('codigo', $codigo)->value('id');
    }
}