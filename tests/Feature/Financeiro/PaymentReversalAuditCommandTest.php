<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PaymentReversalAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));

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

    public function test_cancelled_payment_clean_generates_info_not_warning(): void
    {
        $payment = $this->payment([
            'status' => Payment::STATUS_CANCELLED,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id, '--include-clean' => true]);

        $this->assertFinding($payload, 'clean_cancelled_payment', 'info', paymentId: $payment->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
    }

    public function test_cancelled_allocation_clean_generates_info_not_warning(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment(['allocated_amount' => 0, 'unallocated_amount' => 20]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--include-clean' => true]);

        $this->assertFinding($payload, 'clean_cancelled_allocation', 'info', allocationId: $allocation->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_cancelled_payment_with_confirmed_active_allocation_is_critical(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['status' => Payment::STATUS_CANCELLED, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'cancelled_payment_with_active_allocation', 'critical', paymentId: $payment->id, allocationId: $allocation->id);
    }

    public function test_cancelled_allocation_still_counted_on_invoice_is_critical(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['allocated_amount' => 0, 'unallocated_amount' => 20]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'cancelled_allocation_still_counted_on_invoice', 'critical', allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_cancelled_allocation_still_counted_on_payment_is_critical(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment(['allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'cancelled_allocation_still_counted_on_payment', 'critical', allocationId: $allocation->id);
    }

    public function test_cancelled_payment_with_active_financial_entry_is_warning(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_CANCELLED]);
        $entry = $this->financialEntry(null, $payment, null);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'cancelled_payment_with_financial_entry', 'warning', paymentId: $payment->id);
        $this->assertSame([$entry->id], $payload['findings'][0]['context']['financial_entry_ids']);
    }

    public function test_cancelled_allocation_with_active_financial_entry_is_warning(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment();
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);
        $this->financialEntry($invoice, $payment, $allocation);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'cancelled_allocation_with_financial_entry', 'warning', allocationId: $allocation->id);
    }

    public function test_cancelled_payment_with_active_bank_reconciliation_is_warning(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_CANCELLED]);
        $entry = $this->financialEntry(null, $payment, null);
        $bank = $this->bankStatement();
        MapaConciliacao::query()->create([
            'extrato_id' => $bank->id,
            'lancamento_id' => $entry->id,
            'payment_id' => $payment->id,
            'valor_conciliado' => 20,
            'status' => 'confirmado',
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'cancelled_payment_with_bank_reconciliation', 'warning', paymentId: $payment->id);
    }

    public function test_reversed_invoice_with_external_fiscal_document_is_critical(): void
    {
        $invoice = $this->invoice(['numero_recibo' => 'FT 2026/1']);
        $payment = $this->payment();
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'reversed_invoice_with_fiscal_document', 'critical', allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_reversed_invoice_with_pending_fiscal_request_is_warning(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment();
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);
        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 20,
        ]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'reversed_invoice_with_pending_fiscal_request', 'warning', allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_soft_deleted_allocation_ignored_correctly_is_info(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment(['allocated_amount' => 0, 'unallocated_amount' => 20]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);
        $allocation->delete();

        $payload = $this->jsonPayload([
            '--allocation' => $allocation->id,
            '--include-clean' => true,
            '--include-deleted' => true,
        ]);

        $this->assertFinding($payload, 'soft_deleted_allocation_ignored', 'info', allocationId: $allocation->id);
    }

    public function test_findings_are_not_duplicated_for_same_payment_allocation_and_code(): void
    {
        $invoice = $this->invoice(['valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);

        $payload = $this->jsonPayload(['--payment' => $payment->id, '--allocation' => $allocation->id]);

        $this->assertSame(1, $this->countFindings($payload, 'cancelled_allocation_still_counted_on_invoice', $allocation->id));
    }

    public function test_filters_payment_allocation_invoice_user_and_dates_work(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $invoiceA = $this->invoice(['user_id' => $userA->id]);
        $invoiceB = $this->invoice(['user_id' => $userB->id, 'data_emissao' => '2026-08-01']);
        $paymentA = $this->payment(['user_id' => $userA->id, 'payment_date' => '2026-07-10']);
        $paymentB = $this->payment(['user_id' => $userB->id, 'payment_date' => '2026-08-10']);
        $allocationA = $this->allocation($paymentA, ['invoice_id' => $invoiceA->id, 'status' => PaymentAllocation::STATUS_CANCELLED, 'allocated_at' => '2026-07-10 12:00:00']);
        $allocationB = $this->allocation($paymentB, ['invoice_id' => $invoiceB->id, 'status' => PaymentAllocation::STATUS_CANCELLED, 'allocated_at' => '2026-08-10 12:00:00']);

        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--allocation' => $allocationA->id])['summary']['total_allocations_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--invoice' => $invoiceA->id])['summary']['total_invoices_touched']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $userA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31'])['summary']['total_payments_scanned']);
        $this->assertSame($allocationB->id, $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31', '--include-clean' => true])['findings'][0]['allocation_id']);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_CANCELLED]);
        $relativePath = 'storage/app/audits/payment-reversal-audit-test.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload([
            '--payment' => $payment->id,
            '--include-clean' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('a4-5-payment-reversal-audit-v1', $payload['version']);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_fail_flags_return_exit_one_for_critical_or_warning(): void
    {
        $invoice = $this->invoice(['valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);

        $this->assertSame(1, Artisan::call('finance:audit-payment-reversals', [
            '--allocation' => $allocation->id,
            '--fail-on-critical' => true,
        ]));

        $cleanPayment = $this->payment(['status' => Payment::STATUS_CANCELLED]);
        $this->financialEntry(null, $cleanPayment, null);
        $this->assertSame(1, Artisan::call('finance:audit-payment-reversals', [
            '--payment' => $cleanPayment->id,
            '--fail-on-warning' => true,
        ]));
    }

    public function test_audit_is_read_only(): void
    {
        $invoice = $this->invoice(['valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment(['status' => Payment::STATUS_CANCELLED, 'allocated_amount' => 20, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'status' => PaymentAllocation::STATUS_CANCELLED]);
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
        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 20,
        ]);

        $before = $this->snapshot();

        $this->jsonPayload(['--payment' => $payment->id, '--include-clean' => true, '--include-deleted' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-payment-reversals', array_merge([
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

    private function countFindings(array $payload, string $code, string $allocationId): int
    {
        return collect($payload['findings'])
            ->filter(
                fn (array $finding): bool => $finding['code'] === $code
                    && $finding['allocation_id'] === $allocationId,
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
            'descricao' => 'Linha reversao',
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

    private function financialEntry(?Invoice $invoice, Payment $payment, ?PaymentAllocation $allocation): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Pagamento de Fatura',
            'descricao' => 'Pagamento revertido auditado',
            'valor' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'fatura_id' => $invoice?->id,
            'payment_id' => $payment->id,
            'origem_tipo' => $allocation ? 'payment_allocation' : 'payment',
            'origem_id' => $allocation?->id,
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
            'fiscal_document_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
        ];
    }
}
