<?php

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyFeeGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_monthly_fees_for_all_eligible_users_via_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan();

        $this->createEligibleUser($plan, [
            'nome_completo' => 'Atleta A',
            'email' => 'atleta-a@example.com',
            'data_inscricao' => '2026-05-10',
        ]);
        $this->createEligibleUser($plan, [
            'nome_completo' => 'Atleta B',
            'email' => 'atleta-b@example.com',
            'data_inscricao' => '2026-05-03',
        ]);
        User::factory()->create([
            'nome_completo' => 'Sem Plano',
            'email' => 'sem-plano@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.monthly-fees.generate'), [
            'generate_for_all' => true,
            'current_season' => false,
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.created_count', 4)
            ->assertJsonPath('summary.users_with_new_fees', 2);

        $this->assertSame(4, Invoice::query()->where('tipo', 'mensalidade')->count());
    }

    public function test_it_does_not_duplicate_monthly_fees_for_the_same_period(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $firstRun = $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'));
        $secondRun = $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'));

        $this->assertCount(2, $firstRun);
        $this->assertCount(0, $secondRun);
        $this->assertSame(2, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_future_monthly_fees_stay_hidden_and_current_month_is_visible(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-07-01'), [
            'today' => Carbon::parse('2026-05-01'),
        ]);

        $mayInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-05')->firstOrFail();
        $juneInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-06')->firstOrFail();
        $julyInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-07')->firstOrFail();

        $this->assertFalse((bool) $mayInvoice->oculta);
        $this->assertSame('pendente', $mayInvoice->estado_pagamento);
        $this->assertTrue((bool) $juneInvoice->oculta);
        $this->assertTrue((bool) $julyInvoice->oculta);
        $this->assertSame(1, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->where('oculta', false)->count());
    }

    public function test_due_future_fee_becomes_visible_when_period_arrives(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'), [
            'today' => Carbon::parse('2026-05-01'),
        ]);

        $juneInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-06')->firstOrFail();
        $this->assertTrue((bool) $juneInvoice->oculta);

        $service->activateDueInvoices(Carbon::parse('2026-06-01'));

        $this->assertFalse((bool) $juneInvoice->fresh()->oculta);
    }

    public function test_user_without_discount_generates_invoice_with_base_amount_only(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('30.00', $invoice->valor_total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Mensalidade Base', $invoice->items[0]->descricao);
        $this->assertSame('30.00', $invoice->items[0]->total_linha);
    }

    public function test_percentage_discount_generates_base_and_discount_lines(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'percent',
            'discount_value' => 10,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('27.00', $invoice->valor_total);
        $this->assertSame('27.00', $invoice->valor_em_aberto);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('Mensalidade Base', $invoice->items[0]->descricao);
        $this->assertSame('30.00', $invoice->items[0]->total_linha);
        $this->assertSame('Desconto/Correcao 10%', $invoice->items[1]->descricao);
        $this->assertSame('-3.00', $invoice->items[1]->valor_unitario);
        $this->assertSame('-3.00', $invoice->items[1]->total_linha);
    }

    public function test_fixed_discount_generates_negative_line_with_fixed_amount(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'discount_reason' => 'Ajuste manual',
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('25.00', $invoice->valor_total);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('Desconto/Correcao financeira', $invoice->items[1]->descricao);
        $this->assertSame('-5.00', $invoice->items[1]->total_linha);
    }

    public function test_discount_cannot_make_invoice_total_negative(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'fixed',
            'discount_value' => 50,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('0.00', $invoice->valor_total);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('-30.00', $invoice->items[1]->total_linha);
        $this->assertStringContainsString('Desconto/correcao limitada ao valor base da mensalidade', (string) $invoice->observacoes);
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
            'nome_completo' => 'Utilizador Elegivel',
            'email' => 'eligible-' . uniqid() . '@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
        ], $overrides));

        DadosFinanceiros::create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ] + $financeOverrides);

        return $user->fresh('dadosFinanceiros');
    }
}