<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\AccountCreditUsage;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\User;
use App\Services\Financeiro\AccountCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AccountCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $this->service = app(AccountCreditService::class);

        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'material'],
            ['nome' => 'Material', 'ativo' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creates_credit_from_confirmed_payment_unallocated_amount(): void
    {
        $payment = $this->payment(['amount' => 30, 'allocated_amount' => 0, 'unallocated_amount' => 30]);

        $credit = $this->service->createFromPaymentOverpayment($payment);

        $this->assertSame($payment->id, $credit->payment_id);
        $this->assertSame('30.00', $credit->amount);
        $this->assertSame('30.00', $credit->remaining_amount);
        $this->assertSame(AccountCredit::SOURCE_PAYMENT_OVERALLOCATION, $credit->source);
        $this->assertSame(AccountCredit::STATUS_AVAILABLE, $credit->status);
        $this->assertSame('0.00', $payment->fresh()->unallocated_amount);
        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'account_credit',
            'origem_id' => $credit->id,
            'valor' => 30,
        ]);
    }

    public function test_does_not_create_credit_from_unconfirmed_payment(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_DRAFT]);

        $this->expectException(ValidationException::class);

        $this->service->createFromPaymentOverpayment($payment);
    }

    public function test_does_not_create_credit_with_invalid_or_excess_amount(): void
    {
        $payment = $this->payment(['unallocated_amount' => 10]);

        try {
            $this->service->createFromPaymentOverpayment($payment, 0);
            $this->fail('Expected invalid amount validation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('account_credits', 0);
        }

        $this->expectException(ValidationException::class);
        $this->service->createFromPaymentOverpayment($payment, 15);
    }

    public function test_create_from_payment_is_idempotent_for_same_payment_and_amount(): void
    {
        $payment = $this->payment(['amount' => 25, 'allocated_amount' => 5, 'unallocated_amount' => 20]);

        $first = $this->service->createFromPaymentOverpayment($payment, 20);
        $second = $this->service->createFromPaymentOverpayment($payment->fresh(), 20);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('account_credits', 1);
    }

    public function test_cancels_unused_credit_and_reopens_payment_unallocated_amount(): void
    {
        $payment = $this->payment(['amount' => 30, 'allocated_amount' => 0, 'unallocated_amount' => 30]);
        $credit = $this->service->createFromPaymentOverpayment($payment);

        $cancelled = $this->service->cancel($credit, 'Erro operacional');

        $this->assertSame(AccountCredit::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('0.00', $cancelled->remaining_amount);
        $this->assertSame('30.00', $payment->fresh()->unallocated_amount);
    }

    public function test_does_not_cancel_partially_or_fully_used_credit(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 30, 'unallocated_amount' => 30]);
        $credit = $this->service->createFromPaymentOverpayment($payment);
        $invoice = $this->invoice(['user_id' => $user->id, 'valor_total' => 10, 'valor_em_aberto' => 10]);

        $this->service->applyToInvoice($credit, $invoice, 10);

        $this->expectException(ValidationException::class);
        $this->service->cancel($credit->fresh(), 'Nao permitido');
    }

    public function test_applies_credit_to_open_invoice_and_updates_ledger_credit_invoice_and_trace(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 40, 'unallocated_amount' => 40]);
        $credit = $this->service->createFromPaymentOverpayment($payment);
        $invoice = $this->invoice(['user_id' => $user->id, 'valor_total' => 25, 'valor_em_aberto' => 25]);

        $result = $this->service->applyToInvoice($credit, $invoice, 25);

        $this->assertInstanceOf(AccountCreditUsage::class, $result['usage']);
        $this->assertSame('15.00', $result['account_credit']->remaining_amount);
        $this->assertSame(AccountCredit::STATUS_PARTIALLY_USED, $result['account_credit']->status);
        $this->assertSame('25.00', $result['invoice']->valor_pago);
        $this->assertSame('0.00', $result['invoice']->valor_em_aberto);
        $this->assertSame('pago', $result['invoice']->estado_pagamento);
        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'account_credit_usage',
            'origem_id' => $result['usage']->id,
            'fatura_id' => $invoice->id,
            'valor' => 25,
        ]);
    }

    public function test_apply_guards_amount_state_and_ownership(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 20, 'unallocated_amount' => 20]);
        $credit = $this->service->createFromPaymentOverpayment($payment);

        foreach ([
            [$credit, $this->invoice(['user_id' => $user->id, 'estado_pagamento' => 'cancelado']), 5],
            [$credit, $this->invoice(['user_id' => $user->id, 'estado_pagamento' => 'pago', 'valor_pago' => 20, 'valor_em_aberto' => 0]), 5],
            [$credit, $this->invoice(['user_id' => User::factory()->create()->id]), 5],
            [$credit, $this->invoice(['user_id' => $user->id, 'valor_total' => 10, 'valor_em_aberto' => 10]), 25],
        ] as [$guardedCredit, $invoice, $amount]) {
            try {
                $this->service->applyToInvoice($guardedCredit, $invoice, $amount);
                $this->fail('Expected validation exception.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('account_credit_usages', 0);
            }
        }
    }

    public function test_soft_deleted_usage_does_not_count_as_active_usage(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 20, 'unallocated_amount' => 20]);
        $credit = $this->service->createFromPaymentOverpayment($payment);
        $invoice = $this->invoice(['user_id' => $user->id, 'valor_total' => 10, 'valor_em_aberto' => 10]);
        $result = $this->service->applyToInvoice($credit, $invoice, 10);

        $result['usage']->delete();
        $credit->refresh()->forceFill([
            'remaining_amount' => 20,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ])->save();
        $this->service->cancel($credit->fresh(), 'Usage revertido fora de escopo');

        $this->assertSame(AccountCredit::STATUS_CANCELLED, $credit->fresh()->status);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function payment(array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'payment_date' => '2026-07-10',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-07-01',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-15',
            'mes' => '2026-07',
            'valor_total' => 20,
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'origem_tipo' => 'manual',
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha credito',
            'quantidade' => 1,
            'valor_unitario' => $invoice->valor_total,
            'imposto_percentual' => 0,
            'total_linha' => $invoice->valor_total,
        ]);

        return $invoice->fresh('items');
    }
}
