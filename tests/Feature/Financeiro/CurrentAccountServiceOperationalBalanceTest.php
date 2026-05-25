<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Financeiro\CurrentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentAccountServiceOperationalBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_legacy_balance_alone_does_not_create_operational_current_account_debt(): void
    {
        $member = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 50,
        ]);

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $member->id]);

        $this->assertSame(0.0, (float) $summary['gross_debt']);
        $this->assertSame(0.0, (float) $summary['net_debt']);
        $this->assertSame(50.0, (float) $summary['manual_account_balance']);
    }

    public function test_open_manual_revenue_movement_counts_in_operational_current_account(): void
    {
        $member = User::factory()->create();

        $this->createRevenueMovement($member, 50);

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $member->id]);

        $this->assertSame(50.0, (float) $summary['gross_debt']);
        $this->assertSame(50.0, (float) $summary['net_debt']);
        $this->assertSame(50.0, (float) $summary['breakdown']['movements'][0]['open_amount']);
    }

    public function test_partial_invoice_uses_remaining_amount_in_operational_current_account(): void
    {
        $member = User::factory()->create();

        $invoice = Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        $payment = Payment::query()->create([
            'user_id' => $member->id,
            'amount' => 40,
            'allocated_amount' => 40,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $member->id]);

        $this->assertSame(60.0, (float) $summary['gross_debt']);
        $this->assertSame(60.0, (float) $summary['net_debt']);
        $this->assertSame(60.0, (float) $summary['breakdown']['invoices'][0]['valor_em_aberto']);
    }

    public function test_available_credit_reduces_operational_current_account_without_using_manual_legacy_balance(): void
    {
        $member = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 80,
        ]);

        Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Junho',
            'data_fatura' => now()->subDays(3)->toDateString(),
            'data_emissao' => now()->subDays(3)->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 60,
            'valor_pago' => 0,
            'valor_em_aberto' => 60,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        AccountCredit::query()->create([
            'user_id' => $member->id,
            'amount' => 20,
            'remaining_amount' => 20,
            'source' => 'current_account_service_test',
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $member->id]);

        $this->assertSame(60.0, (float) $summary['gross_debt']);
        $this->assertSame(20.0, (float) $summary['available_credit']);
        $this->assertSame(40.0, (float) $summary['net_debt']);
        $this->assertSame(80.0, (float) $summary['manual_account_balance']);
    }

    private function createRevenueMovement(User $member, float $amount): Movement
    {
        return Movement::query()->create([
            'user_id' => $member->id,
            'nome_manual' => 'Ajuste operacional',
            'classificacao' => 'receita',
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Movimento manual auditavel',
        ]);
    }
}