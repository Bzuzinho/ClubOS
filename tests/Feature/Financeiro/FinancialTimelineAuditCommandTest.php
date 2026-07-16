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
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class FinancialTimelineAuditCommandTest extends TestCase
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

    public function test_clean_timeline_appears_only_with_include_clean(): void
    {
        [$bank, $payment, $invoice, $allocation] = $this->cleanTimeline();

        $withoutClean = $this->jsonPayload(['--allocation' => $allocation->id]);
        $this->assertNoFinding($withoutClean, 'clean_financial_timeline', allocationId: $allocation->id);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--include-clean' => true]);

        $this->assertFinding($payload, 'clean_financial_timeline', 'info', bankTransactionId: $bank->id, paymentId: $payment->id, allocationId: $allocation->id, invoiceId: $invoice->id);
        $this->assertSame(1, $payload['summary']['clean_timeline_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_payment_before_bank_transaction_more_than_one_day_generates_warning(): void
    {
        [$bank, $payment] = $this->cleanTimeline(
            bankOverrides: ['data_movimento' => '2026-07-10'],
            paymentOverrides: ['payment_date' => '2026-07-01'],
        );

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_before_bank_transaction', 'warning', bankTransactionId: $bank->id, paymentId: $payment->id);
    }

    public function test_payment_before_bank_transaction_small_difference_is_not_warning(): void
    {
        [$bank, $payment] = $this->cleanTimeline(
            bankOverrides: ['data_movimento' => '2026-07-10'],
            paymentOverrides: ['payment_date' => '2026-07-09'],
        );

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'payment_before_bank_transaction', 'info', bankTransactionId: $bank->id, paymentId: $payment->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_allocation_before_payment_generates_warning(): void
    {
        [, $payment,, $allocation] = $this->cleanTimeline(
            paymentOverrides: ['payment_date' => '2026-07-10'],
            allocationOverrides: ['allocated_at' => '2026-07-01 09:00:00'],
        );

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'allocation_before_payment', 'warning', paymentId: $payment->id, allocationId: $allocation->id);
    }

    public function test_allocation_before_invoice_issue_in_legacy_case_generates_info(): void
    {
        [, $payment, $invoice, $allocation] = $this->cleanTimeline(
            invoiceOverrides: ['data_emissao' => '2026-07-15', 'origem_tipo' => 'monthly_fee_legacy'],
            allocationOverrides: ['allocated_at' => '2026-07-10 09:00:00'],
        );

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'allocation_before_invoice_issue', 'info', paymentId: $payment->id, allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_invoice_due_before_issue_generates_warning(): void
    {
        [, , $invoice] = $this->cleanTimeline(
            invoiceOverrides: ['data_emissao' => '2026-07-15', 'data_vencimento' => '2026-07-01'],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_due_before_issue', 'warning', invoiceId: $invoice->id);
    }

    public function test_fiscal_request_before_payment_or_allocation_generates_warning(): void
    {
        [, , $invoice, $allocation] = $this->cleanTimeline(
            paymentOverrides: ['payment_date' => '2026-07-10'],
            fiscalOverrides: ['created_at' => '2026-07-01 09:00:00'],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'fiscal_request_before_payment_or_allocation', 'warning', allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_receipt_or_external_document_before_payment_generates_warning(): void
    {
        [, , $invoice] = $this->cleanTimeline(
            paymentOverrides: ['payment_date' => '2026-07-10'],
            invoiceOverrides: ['numero_recibo' => 'RC 2026/1', 'recibo_emitido_em' => '2026-07-01'],
            fiscalOverrides: ['issued_at' => '2026-07-01 10:00:00', 'external_document_number' => 'RC 2026/1'],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'receipt_before_payment', 'warning', invoiceId: $invoice->id);
    }

    public function test_fiscal_issue_before_invoice_generates_critical(): void
    {
        [, , $invoice] = $this->cleanTimeline(
            invoiceOverrides: ['data_emissao' => '2026-07-15'],
            fiscalOverrides: ['issued_at' => '2026-07-01 10:00:00', 'external_document_number' => 'RC 2026/2'],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'fiscal_issue_before_invoice', 'critical', invoiceId: $invoice->id);
    }

    public function test_cancelled_allocation_after_fiscal_issue_generates_critical(): void
    {
        [, , $invoice, $allocation] = $this->cleanTimeline(
            fiscalOverrides: ['issued_at' => '2026-07-10 10:00:00', 'external_document_number' => 'RC 2026/3'],
        );
        $allocation->forceFill([
            'status' => PaymentAllocation::STATUS_CANCELLED,
            'deleted_at' => '2026-07-12 10:00:00',
        ])->save();

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--include-deleted' => true]);

        $this->assertFinding($payload, 'cancelled_allocation_after_fiscal_issue', 'critical', allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_reversal_before_original_action_generates_warning(): void
    {
        [, , , $allocation] = $this->cleanTimeline(
            allocationOverrides: ['allocated_at' => '2026-07-10 10:00:00'],
        );
        $allocation->forceFill([
            'status' => PaymentAllocation::STATUS_CANCELLED,
            'deleted_at' => '2026-07-01 10:00:00',
        ])->save();

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--include-deleted' => true]);

        $this->assertFinding($payload, 'reversal_before_original_action', 'warning', allocationId: $allocation->id);
    }

    public function test_financial_entry_before_source_generates_warning(): void
    {
        [, , , $allocation, $entry] = $this->cleanTimeline(
            allocationOverrides: ['allocated_at' => '2026-07-10 10:00:00'],
            financialEntryOverrides: ['data' => '2026-07-01'],
        );

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertFinding($payload, 'financial_entry_before_source', 'warning', allocationId: $allocation->id, financialEntryId: $entry->id);
    }

    public function test_stale_fiscal_request_archived_generates_info(): void
    {
        [, , $invoice] = $this->cleanTimeline(
            fiscalOverrides: [
                'status' => FiscalDocumentRequest::STATUS_PENDING,
                'issued_at' => null,
                'external_document_number' => null,
                'external_document_id' => null,
                'metadata' => ['stale_cleanup' => true, 'stale_cleanup_version' => 'a4-6'],
            ],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'stale_pending_fiscal_request_timeline_info', 'info', invoiceId: $invoice->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_historical_timeline_incomplete_without_active_impact_generates_info(): void
    {
        [, , $invoice, $allocation] = $this->cleanTimeline(
            invoiceOverrides: [
                'data_emissao' => '2026-01-01',
                'data_vencimento' => '2026-01-15',
                'estado_pagamento' => 'cancelado',
                'origem_tipo' => 'monthly_fee_legacy',
            ],
            allocationOverrides: ['allocated_at' => null],
        );

        $payload = $this->jsonPayload(['--invoice' => $invoice->id, '--include-deleted' => true]);

        $this->assertFinding($payload, 'historical_timeline_incomplete', 'info', allocationId: null, invoiceId: $invoice->id);
        $this->assertSame(1, $payload['summary']['historical_incomplete_count']);
        $this->assertNotNull($allocation->id);
    }

    public function test_filters_payment_allocation_invoice_bank_transaction_and_user_work(): void
    {
        [$bankA, $paymentA, $invoiceA, $allocationA] = $this->cleanTimeline();
        [$bankB, $paymentB] = $this->cleanTimeline(bankOverrides: ['data_movimento' => '2026-08-10'], paymentOverrides: ['payment_date' => '2026-08-10']);

        $this->assertSame(1, $this->jsonPayload(['--bank-transaction' => $bankA->id])['summary']['total_bank_timelines']);
        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_payment_timelines']);
        $this->assertSame(1, $this->jsonPayload(['--allocation' => $allocationA->id])['summary']['total_allocation_timelines']);
        $this->assertSame(1, $this->jsonPayload(['--invoice' => $invoiceA->id])['summary']['total_invoice_timelines']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $paymentB->user_id])['summary']['total_payment_timelines']);
    }

    public function test_only_actionable_removes_infos(): void
    {
        [, , , $allocation] = $this->cleanTimeline(
            paymentOverrides: ['payment_date' => '2026-07-09'],
        );

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--only-actionable' => true]);

        $this->assertSame([], $payload['findings']);
    }

    public function test_json_output_includes_schema_detected(): void
    {
        $this->cleanTimeline();

        $payload = $this->jsonPayload();

        $this->assertContains('data_movimento', $payload['schema_detected']['bank_date_columns']);
        $this->assertContains('payment_date', $payload['schema_detected']['payment_date_columns']);
        $this->assertContains('allocated_at', $payload['schema_detected']['allocation_date_columns']);
        $this->assertContains('issued_at', $payload['schema_detected']['fiscal_date_columns']);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->cleanTimeline();
        $relativePath = 'storage/app/audits/financial-timeline-audit-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-financial-timeline', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_fail_on_critical_returns_exit_one_when_critical_exists(): void
    {
        [, , $invoice] = $this->cleanTimeline(
            invoiceOverrides: ['data_emissao' => '2026-07-15'],
            fiscalOverrides: ['issued_at' => '2026-07-01 10:00:00', 'external_document_number' => 'RC 2026/4'],
        );

        $exitCode = Artisan::call('finance:audit-financial-timeline', [
            '--invoice' => $invoice->id,
            '--fail-on-critical' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_fail_on_warning_returns_exit_one_when_warning_exists(): void
    {
        [, $payment] = $this->cleanTimeline(
            bankOverrides: ['data_movimento' => '2026-07-10'],
            paymentOverrides: ['payment_date' => '2026-07-01'],
        );

        $exitCode = Artisan::call('finance:audit-financial-timeline', [
            '--payment' => $payment->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_read_only_snapshot_stays_equal(): void
    {
        [, , , $allocation] = $this->cleanTimeline();
        $before = $this->snapshot();

        $this->jsonPayload(['--allocation' => $allocation->id, '--include-clean' => true, '--include-deleted' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $bankOverrides
     * @param array<string,mixed> $paymentOverrides
     * @param array<string,mixed> $invoiceOverrides
     * @param array<string,mixed> $allocationOverrides
     * @param array<string,mixed> $fiscalOverrides
     * @param array<string,mixed> $financialEntryOverrides
     * @return array{0:BankStatement,1:Payment,2:Invoice,3:PaymentAllocation,4:FinancialEntry,5:FiscalDocumentRequest}
     */
    private function cleanTimeline(
        array $bankOverrides = [],
        array $paymentOverrides = [],
        array $invoiceOverrides = [],
        array $allocationOverrides = [],
        array $fiscalOverrides = [],
        array $financialEntryOverrides = [],
    ): array {
        $user = User::factory()->create();
        $invoice = $this->invoice(array_merge([
            'user_id' => $user->id,
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-10',
        ], $invoiceOverrides));
        $bank = $this->bankStatement(array_merge([
            'valor' => 20,
            'data_movimento' => '2026-07-10',
            'conciliado' => true,
            'valor_conciliado' => 20,
            'valor_por_conciliar' => 0,
            'conciliacao_status' => 'reconciled',
        ], $bankOverrides));
        $payment = $this->payment(array_merge([
            'user_id' => $user->id,
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-10',
            'source' => Payment::SOURCE_BANK_STATEMENT,
            'method' => 'transferencia',
        ], $paymentOverrides));
        $allocation = $this->allocation($payment, array_merge([
            'invoice_id' => $invoice->id,
            'amount' => 20,
            'allocated_at' => '2026-07-10 12:00:00',
        ], $allocationOverrides));
        $entry = $this->financialEntry($invoice, $payment, $allocation, $bank, $financialEntryOverrides);
        $allocation->forceFill(['financial_entry_id' => $entry->id])->save();
        $this->reconciliation($bank, $payment, $allocation, [
            'lancamento_id' => $entry->id,
            'fatura_id' => $invoice->id,
            'valor_conciliado' => 20,
        ]);
        BankTransactionAllocation::query()->create([
            'bank_statement_id' => $bank->id,
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_alocado' => 20,
            'status' => BankTransactionAllocation::STATUS_CONFIRMED,
            'origem' => 'test',
            'committed_at' => '2026-07-10 12:00:00',
        ]);
        $fiscalRequest = $this->fiscalRequest($invoice, array_merge([
            'user_id' => $user->id,
            'bank_statement_id' => $bank->id,
            'mapa_conciliacao_id' => MapaConciliacao::query()->where('payment_allocation_id', $allocation->id)->value('id'),
            'financial_entry_id' => $entry->id,
            'amount' => 20,
            'paid_at' => '2026-07-10',
            'due_at' => '2026-07-15',
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'RC 2026/10',
            'issued_at' => '2026-07-11 10:00:00',
            'created_at' => '2026-07-10 13:00:00',
        ], $fiscalOverrides));

        return [$bank, $payment, $invoice, $allocation, $entry, $fiscalRequest];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $outputBuffer = new BufferedOutput();
        $exitCode = Artisan::call('finance:audit-financial-timeline', array_merge([
            '--json' => true,
        ], $options), $outputBuffer);

        $output = trim($outputBuffer->fetch());
        $this->assertSame(0, $exitCode, $output);
        $jsonStart = strpos($output, '{');
        $this->assertNotFalse($jsonStart, $output);
        $payload = json_decode(substr($output, $jsonStart), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(
        array $payload,
        string $code,
        string $severity,
        ?string $bankTransactionId = null,
        ?string $paymentId = null,
        ?string $allocationId = null,
        ?string $invoiceId = null,
        ?string $fiscalRequestId = null,
        ?string $financialEntryId = null,
    ): void {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['severity'] === $severity
                && ($bankTransactionId === null || $finding['bank_transaction_id'] === $bankTransactionId)
                && ($paymentId === null || $finding['payment_id'] === $paymentId)
                && ($allocationId === null || $finding['allocation_id'] === $allocationId)
                && ($invoiceId === null || $finding['invoice_id'] === $invoiceId)
                && ($fiscalRequestId === null || $finding['fiscal_request_id'] === $fiscalRequestId)
                && ($financialEntryId === null || $finding['financial_entry_id'] === $financialEntryId),
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s/%s. Payload: %s', $severity, $code, json_encode($payload['findings'])));
    }

    private function assertNoFinding(array $payload, string $code, ?string $allocationId = null): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && ($allocationId === null || $finding['allocation_id'] === $allocationId),
        );

        $this->assertNull($finding, sprintf('Unexpected finding %s.', $code));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function bankStatement(array $overrides = []): BankStatement
    {
        return BankStatement::query()->create(array_merge([
            'data_movimento' => '2026-07-10',
            'descricao' => 'Transferencia bancaria',
            'referencia' => 'TRF-1',
            'valor' => 20,
            'saldo' => 20,
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 20,
            'conciliacao_status' => 'unreconciled',
        ], $overrides));
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
            'descricao' => 'Linha auditada',
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

    /**
     * @param array<string,mixed> $overrides
     */
    private function reconciliation(BankStatement $bank, Payment $payment, PaymentAllocation $allocation, array $overrides = []): MapaConciliacao
    {
        return MapaConciliacao::query()->create(array_merge([
            'extrato_id' => $bank->id,
            'lancamento_id' => $overrides['lancamento_id'] ?? $this->bareFinancialEntry()->id,
            'fatura_id' => $allocation->invoice_id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_conciliado' => 20,
            'status' => 'confirmado',
            'regra_usada' => 'test',
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function financialEntry(Invoice $invoice, Payment $payment, PaymentAllocation $allocation, BankStatement $bank, array $overrides = []): FinancialEntry
    {
        return FinancialEntry::query()->create(array_merge([
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
            'bank_statement_id' => $bank->id,
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function fiscalRequest(Invoice $invoice, array $overrides = []): FiscalDocumentRequest
    {
        $timestampOverrides = array_intersect_key($overrides, array_flip(['created_at', 'updated_at', 'deleted_at']));
        $request = FiscalDocumentRequest::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => $invoice->valor_total,
            'customer_name' => 'Cliente teste',
            'description' => 'Pedido fiscal auditado',
        ], array_diff_key($overrides, $timestampOverrides)));

        if ($timestampOverrides !== []) {
            $request->forceFill($timestampOverrides)->save();
        }

        return $request->fresh();
    }

    private function bareFinancialEntry(): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Teste',
            'descricao' => 'Entrada auditada',
            'valor' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'bank_statements' => BankStatement::query()->orderBy('id')->get()->toArray(),
            'mapa_conciliacao' => MapaConciliacao::query()->orderBy('id')->get()->toArray(),
            'bank_transaction_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
            'fiscal_document_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
        ];
    }
}
