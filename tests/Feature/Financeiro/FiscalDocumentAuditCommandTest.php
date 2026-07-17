<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FiscalDocumentAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

        foreach (['material', 'mensalidade', 'inscricao'] as $type) {
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

    public function test_clean_fiscal_chain_can_be_reported_as_info(): void
    {
        [$invoice, $payment, $allocation, $request] = $this->paidFiscalChain(requestOverrides: [
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'EXT-CLEAN',
            'issued_at' => '2026-07-17',
        ]);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id, '--include-clean' => true]);

        $this->assertSame(1, $payload['summary']['total_fiscal_requests_scanned']);
        $this->assertSame(1, $payload['summary']['clean_fiscal_chain_count']);
        $this->assertFinding($payload, 'clean_fiscal_chain', 'info', $request->id, invoiceId: $invoice->id, paymentId: $payment->id, allocationId: $allocation->id);
    }

    public function test_multi_allocation_payment_does_not_create_amount_mismatch_when_request_matches_invoice_or_allocation(): void
    {
        $user = User::factory()->create();
        $firstInvoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 27,
            'valor_pago' => 27,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-17',
        ]);
        $secondInvoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 45,
            'valor_pago' => 45,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-17',
        ]);
        $payment = $this->payment($firstInvoice, ['amount' => 72, 'allocated_amount' => 72]);
        $firstAllocation = $this->allocation($payment, $firstInvoice, ['amount' => 27]);
        $this->allocation($payment, $secondInvoice, ['amount' => 45]);
        $request = $this->fiscalRequest(['invoice_id' => $firstInvoice->id, 'user_id' => $user->id, 'amount' => 27]);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);

        $this->assertNoFinding($payload, 'fiscal_request_amount_mismatch', $request->id);
        $this->assertFinding($payload, 'pending_fiscal_request_ready_for_external_issue', 'warning', $request->id, invoiceId: $firstInvoice->id, paymentId: $payment->id, allocationId: $firstAllocation->id);
        $finding = collect($payload['findings'])->firstWhere('code', 'pending_fiscal_request_ready_for_external_issue');
        $this->assertSame('invoice', $finding['amount_basis_used']);
        $this->assertTrue($finding['payment_is_multi_allocation']);
        $this->assertSame(2, $finding['payment_allocation_count']);
        $this->assertSame(72.0, (float) $finding['payment_total_amount']);
        $this->assertSame(27.0, (float) $finding['allocation_amount']);
        $this->assertSame(27.0, (float) $finding['invoice_amount']);
        $this->assertSame(27.0, (float) $finding['fiscal_request_amount']);
        $this->assertSame(1, $payload['summary']['multi_allocation_amount_match_count']);
        $this->assertSame(0, $payload['summary']['amount_mismatch_count']);

        $partialInvoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 72,
            'valor_pago' => 72,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-17',
        ]);
        $partialPayment = $this->payment($partialInvoice, ['amount' => 72, 'allocated_amount' => 72]);
        $partialAllocation = $this->allocation($partialPayment, $partialInvoice, ['amount' => 27]);
        $this->allocation($partialPayment, $secondInvoice, ['amount' => 45]);
        $allocationBasedRequest = $this->fiscalRequest(['invoice_id' => $partialInvoice->id, 'user_id' => $user->id, 'amount' => 27]);

        $allocationPayload = $this->jsonPayload(['--fiscal-request' => $allocationBasedRequest->id]);

        $this->assertNoFinding($allocationPayload, 'fiscal_request_amount_mismatch', $allocationBasedRequest->id);
        $allocationFinding = collect($allocationPayload['findings'])->first(fn (array $finding): bool => $finding['fiscal_request_id'] === $allocationBasedRequest->id);
        $this->assertSame('allocation', $allocationFinding['amount_basis_used']);
        $this->assertTrue($allocationFinding['payment_is_multi_allocation']);
        $this->assertSame($partialAllocation->id, $allocationFinding['allocation_id']);
    }

    public function test_request_without_invoice_or_allocation_uses_payment_amount_when_financial_entry_links_payment(): void
    {
        $invoice = $this->invoice(['valor_total' => 72]);
        $payment = $this->payment($invoice, ['amount' => 72, 'allocated_amount' => 0, 'unallocated_amount' => 72]);
        $entry = FinancialEntry::query()->create([
            'data' => '2026-07-17',
            'tipo' => 'receita',
            'categoria' => 'Teste',
            'descricao' => 'Pagamento global',
            'valor' => 72,
            'valor_pago' => 72,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'payment_id' => $payment->id,
            'user_id' => $invoice->user_id,
        ]);
        $matching = $this->fiscalRequest([
            'invoice_id' => null,
            'financial_entry_id' => $entry->id,
            'user_id' => $invoice->user_id,
            'amount' => 72,
        ]);
        $mismatch = $this->fiscalRequest([
            'invoice_id' => null,
            'financial_entry_id' => $entry->id,
            'user_id' => $invoice->user_id,
            'amount' => 70,
        ]);

        $matchingPayload = $this->jsonPayload(['--fiscal-request' => $matching->id]);
        $mismatchPayload = $this->jsonPayload(['--fiscal-request' => $mismatch->id]);

        $this->assertNoFinding($matchingPayload, 'fiscal_request_amount_mismatch', $matching->id);
        $this->assertFinding($mismatchPayload, 'fiscal_request_amount_mismatch', 'warning', $mismatch->id, paymentId: $payment->id);
        $finding = collect($mismatchPayload['findings'])->firstWhere('code', 'fiscal_request_amount_mismatch');
        $this->assertSame('payment', $finding['amount_basis_used']);
        $this->assertSame(72.0, (float) $finding['payment_total_amount']);
    }

    public function test_detects_missing_invoice_and_missing_confirmed_payment(): void
    {
        $withoutInvoice = $this->fiscalRequest(['invoice_id' => null]);
        $unpaidInvoice = $this->invoice(['valor_total' => 30, 'valor_pago' => 0, 'valor_em_aberto' => 30]);
        $withoutPayment = $this->fiscalRequest(['invoice_id' => $unpaidInvoice->id, 'user_id' => $unpaidInvoice->user_id, 'amount' => 30]);

        $payload = $this->jsonPayload();

        $this->assertFinding($payload, 'fiscal_request_without_invoice', 'warning', $withoutInvoice->id);
        $this->assertFinding($payload, 'fiscal_request_without_confirmed_payment', 'warning', $withoutPayment->id, invoiceId: $unpaidInvoice->id);
    }

    public function test_detects_cancelled_invoice_amount_and_external_reference_state(): void
    {
        $cancelled = $this->invoice(['estado_pagamento' => 'cancelado', 'valor_total' => 20, 'valor_pago' => 0, 'valor_em_aberto' => 20]);
        $cancelledRequest = $this->fiscalRequest(['invoice_id' => $cancelled->id, 'user_id' => $cancelled->user_id, 'amount' => 20]);

        [$invoice] = $this->paidFiscalChain(['valor_total' => 40, 'valor_pago' => 40, 'valor_em_aberto' => 0], 40);
        $amountMismatch = $this->fiscalRequest(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'amount' => 35]);

        $issuedWithoutReference = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'issued_at' => '2026-07-17',
            'external_document_number' => null,
            'external_document_id' => null,
            'amount' => 40,
        ]);

        $referenceWithoutIssued = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'external_document_number' => 'EXT-PENDING',
            'amount' => 40,
        ]);
        $issuedAmountMismatch = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'EXT-MISMATCH',
            'issued_at' => '2026-07-17',
            'amount' => 35,
        ]);

        $payload = $this->jsonPayload();

        $this->assertFinding($payload, 'fiscal_request_for_cancelled_invoice', 'warning', $cancelledRequest->id, invoiceId: $cancelled->id);
        $this->assertFinding($payload, 'fiscal_request_amount_mismatch', 'warning', $amountMismatch->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'fiscal_request_amount_mismatch', 'critical', $issuedAmountMismatch->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'issued_document_without_external_reference', 'warning', $issuedWithoutReference->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'external_reference_without_issued_status', 'warning', $referenceWithoutIssued->id, invoiceId: $invoice->id);
    }

    public function test_detects_temporal_and_reversal_anomalies(): void
    {
        $invoice = $this->invoice([
            'valor_total' => 50,
            'valor_pago' => 50,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_emissao' => '2026-07-10',
            'data_pagamento' => '2026-07-12',
        ]);
        $payment = $this->payment($invoice, ['amount' => 50, 'payment_date' => '2026-07-12']);
        $this->allocation($payment, $invoice, ['amount' => 50, 'allocated_at' => '2026-07-12']);

        $issuedBeforeInvoice = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 50,
            'external_document_number' => 'EXT-BEFORE-INVOICE',
            'issued_at' => '2026-07-09',
        ]);
        $issuedBeforePayment = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 50,
            'external_document_number' => 'EXT-BEFORE-PAYMENT',
            'issued_at' => '2026-07-11',
        ]);

        $reversedInvoice = $this->invoice(['valor_total' => 25, 'valor_pago' => 0, 'valor_em_aberto' => 25]);
        $reversedPayment = $this->payment($reversedInvoice, [
            'amount' => 25,
            'status' => Payment::STATUS_CANCELLED,
            'cancelled_at' => '2026-07-12',
        ]);
        $this->allocation($reversedPayment, $reversedInvoice, [
            'amount' => 25,
            'status' => PaymentAllocation::STATUS_CANCELLED,
            'allocated_at' => '2026-07-10',
            'updated_at' => '2026-07-12',
        ]);
        $afterReversal = $this->fiscalRequest([
            'invoice_id' => $reversedInvoice->id,
            'user_id' => $reversedInvoice->user_id,
            'amount' => 25,
            'created_at' => '2026-07-13 10:00:00',
        ]);
        $issuedAfterReversal = $this->fiscalRequest([
            'invoice_id' => $reversedInvoice->id,
            'user_id' => $reversedInvoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 25,
            'external_document_number' => 'EXT-AFTER-REVERSAL',
            'issued_at' => '2026-07-14',
            'created_at' => '2026-07-13 10:00:00',
        ]);

        $payload = $this->jsonPayload();

        $this->assertFinding($payload, 'fiscal_issue_before_invoice', 'critical', $issuedBeforeInvoice->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'receipt_issued_before_payment', 'critical', $issuedBeforePayment->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'fiscal_request_after_reversal', 'warning', $afterReversal->id, invoiceId: $reversedInvoice->id);
        $this->assertFinding($payload, 'issued_document_after_reversal', 'critical', $issuedAfterReversal->id, invoiceId: $reversedInvoice->id);
    }

    public function test_detects_duplicates_stale_soft_deleted_and_unknown_provider(): void
    {
        [$invoice] = $this->paidFiscalChain([], 20);
        $firstPending = $this->fiscalRequest(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'amount' => 20]);
        $secondPending = $this->fiscalRequest(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'amount' => 20]);

        $duplicateIssuedA = $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 20,
            'external_document_number' => 'EXT-DUP-A',
            'issued_at' => '2026-07-17',
        ]);
        $this->fiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 20,
            'external_document_number' => 'EXT-DUP-B',
            'issued_at' => '2026-07-17',
        ]);

        [$staleInvoice] = $this->paidFiscalChain(['valor_total' => 18, 'valor_pago' => 18, 'valor_em_aberto' => 0], 18);
        $stale = $this->fiscalRequest([
            'invoice_id' => $staleInvoice->id,
            'user_id' => $staleInvoice->user_id,
            'amount' => 18,
            'created_at' => '2026-06-01 10:00:00',
        ]);
        $archived = $this->fiscalRequest([
            'created_at' => '2026-06-01 10:00:00',
            'metadata' => ['stale_cleanup' => true, 'stale_cleanup_version' => 'a4-6'],
        ]);
        $archived->delete();

        [$unknownProviderInvoice] = $this->paidFiscalChain(['valor_total' => 15, 'valor_pago' => 15, 'valor_em_aberto' => 0], 15);
        $unknownProvider = $this->fiscalRequest([
            'invoice_id' => $unknownProviderInvoice->id,
            'user_id' => $unknownProviderInvoice->user_id,
            'amount' => 15,
            'provider' => 'future_provider',
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'EXT-FUTURE',
            'issued_at' => '2026-07-17',
        ]);

        $payload = $this->jsonPayload(['--include-deleted' => true]);

        $duplicatePending = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === 'duplicate_pending_fiscal_request'
            && $finding['invoice_id'] === $invoice->id
            && in_array($firstPending->id, $finding['duplicate_fiscal_request_ids'], true)
            && in_array($secondPending->id, $finding['duplicate_fiscal_request_ids'], true));
        $this->assertNotNull($duplicatePending);
        $this->assertSame('warning', $duplicatePending['severity']);
        $this->assertFinding($payload, 'duplicate_issued_external_document', 'critical', $duplicateIssuedA->id, invoiceId: $invoice->id);
        $this->assertFinding($payload, 'stale_pending_fiscal_request_without_external_document', 'warning', $stale->id);
        $this->assertFinding($payload, 'historical_pending_fiscal_request_no_external_document', 'info', $archived->id);
        $this->assertFinding($payload, 'fiscal_request_provider_unknown', 'info', $unknownProvider->id, invoiceId: $unknownProviderInvoice->id);
    }

    public function test_filters_fail_flags_report_path_and_read_only_snapshot(): void
    {
        [$invoice, $payment, $allocation, $request] = $this->paidFiscalChain(requestOverrides: [
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'EXT-FILTER',
            'issued_at' => '2026-07-17',
        ]);
        $warningRequest = $this->fiscalRequest(['created_at' => '2026-06-01 10:00:00']);

        $before = $this->snapshot();
        $reportPath = 'storage/app/testing/fiscal-audit.json';
        @unlink(base_path($reportPath));

        $payload = $this->jsonPayload([
            '--invoice' => $invoice->id,
            '--payment' => $payment->id,
            '--allocation' => $allocation->id,
            '--fiscal-request' => $request->id,
            '--user' => $invoice->user_id,
            '--include-clean' => true,
        ]);
        Artisan::call('finance:audit-fiscal-documents', [
            '--fiscal-request' => $request->id,
            '--report-path' => $reportPath,
        ]);

        $this->assertSame(1, $payload['summary']['total_fiscal_requests_scanned']);
        $this->assertSame($before, $this->snapshot());
        $this->assertFileExists(base_path($reportPath));

        $onlyActionable = $this->jsonPayload(['--only-actionable' => true]);
        $this->assertNotContains('clean_fiscal_chain', collect($onlyActionable['findings'])->pluck('code')->all());
        $this->assertNotContains('historical_pending_fiscal_request_no_external_document', collect($onlyActionable['findings'])->pluck('code')->all());

        $this->assertSame(0, Artisan::call('finance:audit-fiscal-documents', [
            '--fiscal-request' => $request->id,
            '--include-clean' => true,
            '--fail-on-warning' => true,
        ]));
        $this->assertSame(1, Artisan::call('finance:audit-fiscal-documents', [
            '--fiscal-request' => $warningRequest->id,
            '--fail-on-warning' => true,
        ]));
        $this->assertSame(0, Artisan::call('finance:audit-fiscal-documents', [
            '--fiscal-request' => $warningRequest->id,
            '--fail-on-critical' => true,
        ]));
    }

    /**
     * @param array<string,mixed> $invoiceOverrides
     * @return array{0:Invoice,1:Payment,2:PaymentAllocation,3:FiscalDocumentRequest}
     */
    private function paidFiscalChain(array $invoiceOverrides = [], float $amount = 20, array $requestOverrides = []): array
    {
        $invoice = $this->invoice(array_merge([
            'valor_total' => $amount,
            'valor_pago' => $amount,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-17',
        ], $invoiceOverrides));
        $payment = $this->payment($invoice, ['amount' => $amount, 'payment_date' => '2026-07-17']);
        $allocation = $this->allocation($payment, $invoice, ['amount' => $amount, 'allocated_at' => '2026-07-17']);
        $request = $this->fiscalRequest(array_merge(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'amount' => $amount], $requestOverrides));

        return [$invoice, $payment, $allocation, $request];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $user = $overrides['user_id'] ?? User::factory()->create()->id;

        return Invoice::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $user,
            'data_fatura' => '2026-07-17',
            'data_emissao' => '2026-07-17',
            'data_vencimento' => '2026-07-31',
            'valor_total' => 20,
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'origem_tipo' => 'manual',
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function payment(Invoice $invoice, array $overrides = []): Payment
    {
        $amount = (float) ($overrides['amount'] ?? $invoice->valor_total);

        return Payment::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'user_id' => $invoice->user_id,
            'amount' => $amount,
            'allocated_amount' => $amount,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-17',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function allocation(Payment $payment, Invoice $invoice, array $overrides = []): PaymentAllocation
    {
        $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at', 'deleted_at']));
        $overrides = array_diff_key($overrides, $timestamps);
        $allocation = PaymentAllocation::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $payment->amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-07-17',
        ], $overrides));

        if ($timestamps !== []) {
            $allocation->forceFill($timestamps)->saveQuietly();
        }

        return $allocation;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function fiscalRequest(array $overrides = []): FiscalDocumentRequest
    {
        $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at', 'deleted_at']));
        $overrides = array_diff_key($overrides, $timestamps);
        $invoice = isset($overrides['invoice_id']) && $overrides['invoice_id']
            ? Invoice::query()->find($overrides['invoice_id'])
            : null;

        $request = FiscalDocumentRequest::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice?->id,
            'user_id' => $invoice?->user_id ?? User::factory()->create()->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'amount' => 20,
        ], $overrides));

        $request->forceFill(array_merge([
            'created_at' => '2026-07-17 10:00:00',
            'updated_at' => '2026-07-17 10:00:00',
        ], $timestamps))->saveQuietly();

        return $request;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        Artisan::call('finance:audit-fiscal-documents', array_merge(['--json' => true], $options));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'fiscal_document_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'invoices' => Invoice::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->map->getAttributes()->all(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    private function assertFinding(array $payload, string $code, string $severity, string $requestId, ?string $invoiceId = null, ?string $paymentId = null, ?string $allocationId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code && $finding['fiscal_request_id'] === $requestId);

        $this->assertNotNull($finding, sprintf('Missing finding %s for request %s. Existing codes: %s', $code, $requestId, collect($payload['findings'])->pluck('code')->implode(', ')));
        $this->assertSame($severity, $finding['severity']);

        if ($invoiceId !== null) {
            $this->assertSame($invoiceId, $finding['invoice_id']);
        }

        if ($paymentId !== null) {
            $this->assertSame($paymentId, $finding['payment_id']);
        }

        if ($allocationId !== null) {
            $this->assertSame($allocationId, $finding['allocation_id']);
        }
    }

    private function assertNoFinding(array $payload, string $code, string $requestId): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code && $finding['fiscal_request_id'] === $requestId);

        $this->assertNull($finding, sprintf('Unexpected finding %s for request %s.', $code, $requestId));
    }
}
