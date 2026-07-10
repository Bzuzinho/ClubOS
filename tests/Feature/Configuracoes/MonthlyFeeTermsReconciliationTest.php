<?php

namespace Tests\Feature\Configuracoes;

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

class MonthlyFeeTermsReconciliationTest extends TestCase
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

    public function test_monthly_fee_value_update_reconciles_future_invoices_for_eligible_members(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan(30.00, 'Plano A');
        $first = $this->createEligibleUser($plan, 'first-catalog@example.test');
        $second = $this->createEligibleUser($plan, 'second-catalog@example.test');
        $oldFirst = $this->createMonthlyInvoice($first, '2026-08', 30.00);
        $oldSecond = $this->createMonthlyInvoice($second, '2026-08', 30.00);

        $this->actingAs($admin)
            ->put(route('configuracoes.mensalidades.update', $plan), [
                'designacao' => 'Plano A',
                'valor' => 35,
                'age_group_id' => null,
                'ativo' => true,
            ])
            ->assertRedirect(route('configuracoes'));

        $this->assertSame('cancelado', $oldFirst->fresh()->estado_pagamento);
        $this->assertSame('cancelado', $oldSecond->fresh()->estado_pagamento);

        foreach ([$first, $second] as $member) {
            $active = Invoice::query()
                ->where('user_id', $member->id)
                ->where('mes', '2026-08')
                ->where('tipo', 'mensalidade')
                ->where('estado_pagamento', '!=', 'cancelado')
                ->sole();

            $this->assertSame('35.00', $active->valor_total);
            $this->assertSame('monthly_fee', $active->origem_tipo);
            $this->assertSame($plan->id, $active->origem_id);
        }
    }

    public function test_monthly_fee_designation_update_reconciles_future_invoice_items(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan(30.00, 'Plano Antigo');
        $member = $this->createEligibleUser($plan, 'designation-catalog@example.test');
        $old = $this->createMonthlyInvoice($member, '2026-08', 30.00);

        $this->actingAs($admin)
            ->put(route('configuracoes.mensalidades.update', $plan), [
                'designacao' => 'Plano Novo',
                'valor' => 30,
                'age_group_id' => null,
                'ativo' => true,
            ])
            ->assertRedirect(route('configuracoes'));

        $active = Invoice::query()
            ->with('items')
            ->where('user_id', $member->id)
            ->where('mes', '2026-08')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->sole();

        $this->assertSame('cancelado', $old->fresh()->estado_pagamento);
        $this->assertSame('Plano Novo', $active->items->first()->descricao);
    }

    public function test_monthly_fee_update_rolls_back_when_future_terms_reconciliation_fails(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan(30.00, 'Plano Rollback');
        $member = $this->createEligibleUser($plan, 'rollback-catalog@example.test');
        $invoice = $this->createMonthlyInvoice($member, '2026-08', 30.00);

        $lifecycle = \Mockery::mock(MemberMonthlyFeeLifecycleService::class);
        $lifecycle->shouldReceive('reconcileFutureMonthlyTerms')
            ->once()
            ->andThrow(new RuntimeException('forced terms reconciliation failure'));
        $this->app->instance(MemberMonthlyFeeLifecycleService::class, $lifecycle);

        $this->actingAs($admin)
            ->from(route('configuracoes'))
            ->put(route('configuracoes.mensalidades.update', $plan), [
                'designacao' => 'Plano Alterado',
                'valor' => 35,
                'age_group_id' => null,
                'ativo' => true,
            ])
            ->assertServerError();

        $plan->refresh();
        $invoice->refresh();

        $this->assertSame('Plano Rollback', $plan->designacao);
        $this->assertSame('30.00', $plan->valor);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertSame('30.00', $invoice->valor_total);
    }

    private function createMonthlyPlan(float $amount, string $designation): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designation,
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, string $email): User
    {
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Catalogo',
            'email' => $email,
            'estado' => 'ativo',
            'data_inscricao' => '2026-07-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

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
