<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PaymentAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

        foreach (['material', 'mensalidade'] as $type) {
            InvoiceType::query()->firstOrCreate(
                ['codigo' => $type],
                ['nome' => ucfirst($type), 'ativo' => true],
            );
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_clean_payment_has_no_findings(): void
    {
        $invoice = $this->invoice([
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-10',
        ]);
        $payment = $this->payment([
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
        ]);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);

        $payload = $this->jsonPayload();

        $this->assertSame(1, $payload['summary']['total_payments_scanned']);
        $this->assertSame(0, $payload['summary']['total_findings']);
        $this->assertSame([], $payload['findings']);
    }

    public function test_payment_allocated_amount_above_amount_is_critical(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 25, 'unallocated_amount' => 0]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_allocated_exceeds_amount', 'critical', paymentId: $payment->id);
    }

    public function test_payment_unallocated_amount_inconsistent_is_warning(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 5]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_unallocated_inconsistent', 'warning', paymentId: $payment->id);
    }

    public function test_confirmed_allocation_sum_differs_from_invoice_paid_amount(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 10, 'valor_em_aberto' => 10, 'estado_pagamento' => 'parcial']);
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_paid_amount_differs_from_confirmed_allocations', 'warning', invoiceId: $invoice->id);
    }

    public function test_invoice_paid_amount_above_total_is_critical(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 30, 'valor_em_aberto' => -10, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['amount' => 30, 'allocated_amount' => 30, 'unallocated_amount' => 0]);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 30]);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_paid_amount_exceeds_total', 'critical', invoiceId: $invoice->id);
    }

    public function test_confirmed_allocation_to_cancelled_invoice_is_critical(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 0, 'valor_em_aberto' => 20, 'estado_pagamento' => 'cancelado']);
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'confirmed_allocation_to_cancelled_invoice', 'critical', allocationId: $allocation->id);
    }

    public function test_confirmed_soft_deleted_allocation_is_critical(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 0, 'valor_em_aberto' => 20]);
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 20]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);
        $allocation->delete();

        $payload = $this->jsonPayload(['--payment' => $payment->id, '--include-deleted' => true]);

        $this->assertFinding($payload, 'payment_allocation_confirmed_soft_deleted', 'critical', allocationId: $allocation->id);
    }

    public function test_reconciliation_payment_without_bank_statement_is_warning(): void
    {
        $payment = $this->payment([
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => null,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_reconciliation_without_bank_statement', 'warning', paymentId: $payment->id);
        $this->assertSame(1, $this->countFindings($payload, 'payment_reconciliation_without_bank_statement', $payment->id));
        $this->assertSame(1, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['info_count']);
    }

    public function test_cancelled_reconciliation_payment_without_bank_statement_is_info_without_active_invoice_impact(): void
    {
        $payment = $this->payment([
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'source' => Payment::SOURCE_RECONCILIATION,
            'status' => Payment::STATUS_CANCELLED,
            'bank_statement_id' => null,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'cancelled_payment_without_bank_trace', 'info', paymentId: $payment->id);
        $this->assertSame(0, $this->countFindings($payload, 'payment_reconciliation_without_bank_statement', $payment->id));
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
        $this->assertSame(0, Artisan::call('finance:audit-payments', [
            '--payment' => $payment->id,
            '--fail-on-warning' => true,
        ]));
    }

    public function test_cancelled_reconciliation_payment_with_active_confirmed_allocation_remains_warning(): void
    {
        $invoice = $this->invoice([
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-10',
        ]);
        $payment = $this->payment([
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'source' => Payment::SOURCE_RECONCILIATION,
            'status' => Payment::STATUS_CANCELLED,
            'bank_statement_id' => null,
        ]);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_reconciliation_without_bank_statement', 'warning', paymentId: $payment->id);
        $this->assertSame(1, $this->countFindings($payload, 'payment_reconciliation_without_bank_statement', $payment->id));
        $this->assertSame(1, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['info_count']);
        $this->assertSame(1, Artisan::call('finance:audit-payments', [
            '--payment' => $payment->id,
            '--fail-on-warning' => true,
        ]));
    }

    public function test_payment_with_missing_bank_statement_is_critical_when_modelled(): void
    {
        try {
            $payment = $this->payment([
                'amount' => 20,
                'allocated_amount' => 0,
                'unallocated_amount' => 20,
                'bank_statement_id' => (string) \Illuminate\Support\Str::uuid(),
            ]);
        } catch (QueryException) {
            $this->markTestSkipped('O schema atual impede modelar payment.bank_statement_id inexistente com FKs ativas.');
        }

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_bank_statement_missing', 'critical', paymentId: $payment->id);
    }

    public function test_allocation_with_missing_financial_entry_is_critical_when_modelled(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        try {
            $allocation = $this->allocation($payment, [
                'financial_entry_id' => (string) \Illuminate\Support\Str::uuid(),
                'amount' => 20,
            ]);
        } catch (QueryException) {
            $this->markTestSkipped('O schema atual impede modelar allocation.financial_entry_id inexistente com FKs ativas.');
        }

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'allocation_financial_entry_missing', 'critical', allocationId: $allocation->id);
    }

    public function test_cancelled_soft_deleted_allocation_is_info_and_ignored_by_active_totals(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 0, 'valor_em_aberto' => 20]);
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 20]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);
        $allocation->delete();

        $payload = $this->jsonPayload(['--payment' => $payment->id, '--include-deleted' => true]);

        $this->assertFinding($payload, 'soft_deleted_allocation_ignored', 'info', allocationId: $allocation->id);
        $this->assertNoFinding($payload, 'invoice_paid_amount_differs_from_confirmed_allocations', $invoice->id);
    }

    public function test_json_output_is_valid_and_detects_credit_model(): void
    {
        $payment = $this->payment();

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertSame($payment->id, $payload['findings'][0]['payment_id'] ?? $payment->id);
        $this->assertContains('AccountCredit', $payload['detected_models']['credit_refund_reversal_models_detected']);
        $this->assertContains('PaymentReversal', $payload['detected_models']['credit_refund_reversal_models_detected']);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->payment();
        $relativePath = 'storage/app/audits/payment-audit-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-payments', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_filters_payment_invoice_user_from_to_and_only_open_work(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $invoiceA = $this->invoice(['user_id' => $userA->id, 'valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $invoiceB = $this->invoice(['user_id' => $userB->id, 'mes' => '2026-08', 'valor_total' => 30, 'valor_pago' => 0, 'valor_em_aberto' => 30]);
        $paymentA = $this->payment(['user_id' => $userA->id, 'amount' => 20, 'allocated_amount' => 20, 'unallocated_amount' => 0, 'payment_date' => '2026-07-10']);
        $paymentB = $this->payment(['user_id' => $userB->id, 'amount' => 30, 'allocated_amount' => 0, 'unallocated_amount' => 30, 'payment_date' => '2026-08-10']);
        $this->allocation($paymentA, ['invoice_id' => $invoiceA->id, 'amount' => 20]);
        $this->allocation($paymentB, ['invoice_id' => $invoiceB->id, 'amount' => 0, 'status' => PaymentAllocation::STATUS_CANCELLED]);

        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--invoice' => $invoiceA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $userA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31'])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--only-open' => true])['summary']['total_payments_scanned']);
    }

    public function test_fail_on_critical_returns_exit_one(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 25, 'unallocated_amount' => 0]);

        $exitCode = Artisan::call('finance:audit-payments', [
            '--payment' => $payment->id,
            '--fail-on-critical' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_fail_on_warning_returns_exit_one(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 5]);

        $exitCode = Artisan::call('finance:audit-payments', [
            '--payment' => $payment->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_audit_is_read_only(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);
        $entry = $this->financialEntry($invoice, $payment, $allocation);
        $bank = $this->bankStatement();
        MapaConciliacao::query()->create([
            'extrato_id' => $bank->id,
            'lancamento_id' => $entry->id,
            'fatura_id' => $invoice->id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_conciliado' => 20,
            'status' => 'confirmado',
        ]);
        BankTransactionAllocation::query()->create([
            'bank_statement_id' => $bank->id,
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_alocado' => 20,
            'status' => BankTransactionAllocation::STATUS_CONFIRMED,
            'origem' => 'test',
        ]);

        $before = $this->snapshot();

        $this->jsonPayload(['--payment' => $payment->id, '--include-deleted' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-payments', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $paymentId = null, ?string $allocationId = null, ?string $invoiceId = null): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['severity'] === $severity
                && ($paymentId === null || $finding['payment_id'] === $paymentId)
                && ($allocationId === null || $finding['allocation_id'] === $allocationId)
                && ($invoiceId === null || $finding['invoice_id'] === $invoiceId),
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s/%s.', $severity, $code));
    }

    private function assertNoFinding(array $payload, string $code, string $invoiceId): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['invoice_id'] === $invoiceId,
        );

        $this->assertNull($finding, sprintf('Unexpected finding %s for invoice %s.', $code, $invoiceId));
    }

    private function countFindings(array $payload, string $code, string $paymentId): int
    {
        return collect($payload['findings'])
            ->filter(
                fn (array $finding): bool => $finding['code'] === $code
                    && $finding['payment_id'] === $paymentId,
            )
            ->count();
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
            'descricao' => 'Linha pagamento',
            'quantidade' => 1,
            'valor_unitario' => $invoice->valor_total,
            'imposto_percentual' => 0,
            'total_linha' => $invoice->valor_total,
        ]);

        return $invoice->fresh('items');
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
    private function allocation(Payment $payment, array $overrides = []): PaymentAllocation
    {
        return PaymentAllocation::query()->create(array_merge([
            'payment_id' => $payment->id,
            'invoice_id' => null,
            'financial_entry_id' => null,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-07-10 12:00:00',
        ], $overrides));
    }

    private function financialEntry(Invoice $invoice, Payment $payment, PaymentAllocation $allocation): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Pagamento de Fatura',
            'descricao' => 'Pagamento auditado',
            'valor' => $allocation->amount,
            'valor_pago' => $allocation->amount,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'fatura_id' => $invoice->id,
            'payment_id' => $payment->id,
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
        ]);
    }

    private function bankStatement(): BankStatement
    {
        return BankStatement::query()->create([
            'data_movimento' => '2026-07-10',
            'descricao' => 'Transferencia',
            'valor' => 20,
            'saldo' => 20,
            'conciliado' => true,
            'valor_conciliado' => 20,
            'valor_por_conciliar' => 0,
            'conciliacao_status' => 'reconciled',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
            'mapa_conciliacao' => MapaConciliacao::query()->orderBy('id')->get()->toArray(),
            'bank_transaction_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
