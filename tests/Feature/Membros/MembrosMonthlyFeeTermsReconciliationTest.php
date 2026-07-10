<?php

namespace Tests\Feature\Membros;

use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MemberMonthlyFeeLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class MembrosMonthlyFeeTermsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUserTypeIfMissing('atleta', 'Atleta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_member_update_changing_monthly_fee_plan_reconciles_future_invoices(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $planA = $this->createMonthlyPlan(30.00, 'Plano A');
        $planB = $this->createMonthlyPlan(40.00, 'Plano B');
        $member = $this->createEligibleUser($planA, 'member-plan-change@example.test');
        $old = $this->createMonthlyInvoice($member, '2026-08', 30.00);

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $this->memberPayload($member, [
                'tipo_mensalidade' => $planB->id,
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $active = Invoice::query()
            ->with('items')
            ->where('user_id', $member->id)
            ->where('mes', '2026-08')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->sole();

        $this->assertSame('cancelado', $old->fresh()->estado_pagamento);
        $this->assertSame($planB->id, $member->fresh('dadosFinanceiros')->dadosFinanceiros->mensalidade_id);
        $this->assertSame('40.00', $active->valor_total);
        $this->assertSame('Plano B', $active->items->first()->descricao);
        $this->assertSame('monthly_fee', $active->origem_tipo);
        $this->assertSame($planB->id, $active->origem_id);
    }

    public function test_member_update_discount_reason_only_does_not_reconcile_future_invoices(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan(40.00, 'Plano Razao');
        $member = $this->createEligibleUser($plan, 'member-reason-change@example.test', [
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_reason' => 'Motivo inicial',
        ]);
        $invoice = $this->createMonthlyInvoice($member, '2026-08', 40.00);

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $this->memberPayload($member, [
                'tipo_mensalidade' => $plan->id,
                'discount_type' => 'percent',
                'discount_value' => 10,
                'discount_reason' => 'Apenas texto alterado',
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $this->assertSame('pendente', $invoice->fresh()->estado_pagamento);
        $this->assertSame(1, Invoice::query()->where('user_id', $member->id)->where('mes', '2026-08')->count());
    }

    public function test_member_update_rolls_back_monthly_terms_when_reconciliation_fails(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $planA = $this->createMonthlyPlan(30.00, 'Plano Rollback A');
        $planB = $this->createMonthlyPlan(40.00, 'Plano Rollback B');
        $member = $this->createEligibleUser($planA, 'member-rollback-terms@example.test');
        $invoice = $this->createMonthlyInvoice($member, '2026-08', 30.00);

        $lifecycle = \Mockery::mock(MemberMonthlyFeeLifecycleService::class);
        $lifecycle->shouldReceive('reconcileEligibilityTransition')->once()->andReturn([
            'cancelled_count' => 0,
            'cancelled_invoice_ids' => [],
            'effective_month' => '2026-07',
            'cutoff_month' => '2026-08',
        ]);
        $lifecycle->shouldReceive('reconcileFutureMonthlyTerms')
            ->once()
            ->andThrow(new RuntimeException('forced terms reconciliation failure'));
        $this->app->instance(MemberMonthlyFeeLifecycleService::class, $lifecycle);

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $this->memberPayload($member, [
                'tipo_mensalidade' => $planB->id,
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]))
            ->assertSessionHas('error');

        $member->refresh();
        $invoice->refresh();

        $this->assertSame($planA->id, $member->fresh('dadosFinanceiros')->dadosFinanceiros->mensalidade_id);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertSame('30.00', $invoice->valor_total);
    }

    private function memberPayload(User $member, array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => $member->nome_completo,
            'email_utilizador' => $member->email,
            'numero_socio' => $member->numero_socio,
            'sexo' => $member->sexo ?: 'masculino',
            'estado' => 'ativo',
            'perfil' => 'atleta',
            'tipo_membro' => ['atleta'],
            'user_types' => [(string) UserType::query()->where('codigo', 'atleta')->value('id')],
            'ativo_desportivo' => '1',
        ], $overrides);
    }

    private function createMonthlyPlan(float $amount, string $designation): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designation,
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, string $email, array $financeOverrides = []): User
    {
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Membro',
            'email' => $email,
            'email_utilizador' => $email,
            'numero_socio' => 'MEM-' . substr(md5($email), 0, 8),
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'data_inscricao' => '2026-07-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ] + $financeOverrides);

        $user->userTypes()->sync([(string) UserType::query()->where('codigo', 'atleta')->value('id')]);

        return $user->fresh(['dadosFinanceiros', 'userTypes']);
    }

    private function createMonthlyInvoice(User $user, string $month, float $amount): Invoice
    {
        return Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => $month . '-01',
            'mes' => $month,
            'data_emissao' => $month . '-01',
            'data_vencimento' => $month . '-01',
            'valor_total' => $amount,
            'valor_pago' => 0,
            'valor_em_aberto' => $amount,
            'oculta' => true,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
        ]);
    }

    private function createUserTypeIfMissing(string $codigo, string $nome): void
    {
        UserType::query()->firstOrCreate(
            ['codigo' => $codigo],
            ['nome' => $nome, 'descricao' => $nome, 'ativo' => true],
        );
    }
}
