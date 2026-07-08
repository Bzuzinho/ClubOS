<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MemberMonthlyFeeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class MemberMonthlyFeeSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_dados_financeiros_if_missing(): void
    {
        $user = User::factory()->create(['tipo_mensalidade' => null]);
        $plan = $this->createPlan('Plano Sync A');

        app(MemberMonthlyFeeSyncService::class)->sync($user, $plan->id);

        $financeData = DadosFinanceiros::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($financeData);
        $this->assertSame($plan->id, $financeData?->mensalidade_id);
    }

    public function test_updates_existing_mensalidade_id(): void
    {
        $user = User::factory()->create(['tipo_mensalidade' => null]);
        $oldPlan = $this->createPlan('Plano Sync B1');
        $newPlan = $this->createPlan('Plano Sync B2');

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $oldPlan->id,
        ]);

        app(MemberMonthlyFeeSyncService::class)->sync($user, $newPlan->id);

        $this->assertSame($newPlan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
    }

    public function test_does_not_change_other_financial_fields(): void
    {
        $user = User::factory()->create(['tipo_mensalidade' => null]);
        $oldPlan = $this->createPlan('Plano Sync C1');
        $newPlan = $this->createPlan('Plano Sync C2');

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $oldPlan->id,
            'discount_type' => 'fixed',
            'discount_value' => 5.50,
            'discount_reason' => 'Manter',
            'conta_corrente_manual' => 10.00,
        ]);

        app(MemberMonthlyFeeSyncService::class)->sync($user, $newPlan->id);

        $financeData = DadosFinanceiros::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('fixed', (string) $financeData->discount_type);
        $this->assertSame('5.50', (string) $financeData->discount_value);
        $this->assertSame('Manter', (string) $financeData->discount_reason);
        $this->assertSame('10.00', (string) $financeData->conta_corrente_manual);
    }

    public function test_validates_monthly_fee_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $user = User::factory()->create(['tipo_mensalidade' => null]);

        app(MemberMonthlyFeeSyncService::class)->sync($user, 'missing-plan-id');
    }

    public function test_does_not_write_legacy_field_when_syncing_canonical_monthly_fee(): void
    {
        $user = User::factory()->create(['tipo_mensalidade' => 'legacy-marker']);
        $plan = $this->createPlan('Plano Sync D');

        app(MemberMonthlyFeeSyncService::class)->sync($user, $plan->id);

        $freshUser = $user->fresh();

        $this->assertSame('legacy-marker', $freshUser?->tipo_mensalidade);
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
    }

    public function test_is_idempotent_for_same_plan(): void
    {
        $user = User::factory()->create(['tipo_mensalidade' => null]);
        $plan = $this->createPlan('Plano Sync E');

        app(MemberMonthlyFeeSyncService::class)->sync($user, $plan->id);
        app(MemberMonthlyFeeSyncService::class)->sync($user, $plan->id);

        $this->assertSame(1, DadosFinanceiros::query()->where('user_id', $user->id)->count());
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
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
