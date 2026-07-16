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

final class StaleFiscalRequestCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));

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

    public function test_default_dry_run_does_not_change_safe_request(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $before = $this->fiscalRequestSnapshot($request->id);

        $payload = $this->jsonPayload();

        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame(1, $payload['summary']['safe_to_archive_stale_request']);
        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame($before, $this->fiscalRequestSnapshot($request->id));
    }

    public function test_explicit_dry_run_does_not_change_metadata_notes_or_status(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $before = $this->fiscalRequestSnapshot($request->id);

        $payload = $this->jsonPayload(['--dry-run' => true]);

        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame($before, $this->fiscalRequestSnapshot($request->id));
    }

    public function test_apply_marks_safe_request_metadata_and_note(): void
    {
        $request = $this->stalePendingFiscalRequest();

        $payload = $this->jsonPayload(['--apply' => true]);
        $updated = FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();

        $this->assertSame('apply', $payload['mode']);
        $this->assertSame(1, $payload['summary']['applied_count']);
        $this->assertSame('already_archived_stale_request', $payload['items'][0]['classification']);
        $this->assertTrue((bool) data_get($updated->metadata, 'stale_cleanup'));
        $this->assertSame('a3-6', data_get($updated->metadata, 'stale_cleanup_version'));
        $this->assertSame('soft_deleted_pending_request_without_external_document_for_unpaid_invoice', data_get($updated->metadata, 'stale_cleanup_reason'));
        $this->assertNotNull(data_get($updated->metadata, 'stale_cleanup_at'));
        $this->assertStringContainsString('[A3.6] Pedido fiscal pendente stale arquivado logicamente', (string) $updated->notes);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $updated->status);
        $this->assertNotNull($updated->deleted_at);
    }

    public function test_apply_is_idempotent(): void
    {
        $request = $this->stalePendingFiscalRequest(['notes' => 'Nota inicial']);

        $this->jsonPayload(['--apply' => true]);
        $first = FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $payload = $this->jsonPayload(['--apply' => true]);
        $second = FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();

        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame('already_archived_stale_request', $payload['items'][0]['classification']);
        $this->assertSame(data_get($first->metadata, 'stale_cleanup_at'), data_get($second->metadata, 'stale_cleanup_at'));
        $this->assertSame($first->notes, $second->notes);
        $this->assertSame(1, substr_count((string) $second->notes, '[A3.6]'));
    }

    public function test_external_document_number_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest(['external_document_number' => 'REC 2026/1']);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_external_document_present', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_external_document_id_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest(['external_document_id' => 'ext-1']);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_external_document_present', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_paid_invoice_is_unsafe_and_not_changed(): void
    {
        $invoice = $this->invoice(['estado_pagamento' => 'pago', 'valor_pago' => 27, 'valor_em_aberto' => 0, 'data_pagamento' => '2026-06-12']);
        $request = $this->stalePendingFiscalRequest([], $invoice);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_invoice_paid_or_partially_paid', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_partially_paid_invoice_is_unsafe_and_not_changed(): void
    {
        $invoice = $this->invoice(['estado_pagamento' => 'parcial', 'valor_pago' => 10, 'valor_em_aberto' => 17]);
        $request = $this->stalePendingFiscalRequest([], $invoice);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_invoice_paid_or_partially_paid', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_confirmed_payment_allocation_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $this->paymentAllocation($request->invoice, PaymentAllocation::STATUS_CONFIRMED);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_confirmed_allocation_present', $payload['items'][0]['classification']);
    }

    public function test_reconciliation_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $entry = $this->financialEntryForReconciliation();
        $bank = $this->bankStatement();

        MapaConciliacao::query()->create([
            'extrato_id' => $bank->id,
            'lancamento_id' => $entry->id,
            'fatura_id' => $request->invoice_id,
            'valor_conciliado' => 27,
            'status' => 'confirmado',
        ]);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertContains('unsafe_reconciliation_present', $payload['items'][0]['risk_reasons']);
    }

    public function test_bank_transaction_allocation_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $bank = $this->bankStatement();

        BankTransactionAllocation::query()->create([
            'bank_statement_id' => $bank->id,
            'invoice_id' => $request->invoice_id,
            'user_id' => $request->user_id,
            'valor_alocado' => 27,
            'status' => BankTransactionAllocation::STATUS_CONFIRMED,
            'origem' => 'test',
        ]);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_bank_allocation_present', $payload['items'][0]['classification']);
    }

    public function test_financial_entry_is_unsafe_and_not_changed(): void
    {
        $request = $this->stalePendingFiscalRequest();
        FinancialEntry::query()->create([
            'data' => '2026-06-01',
            'tipo' => 'receita',
            'categoria' => 'Mensalidade',
            'descricao' => 'Rasto financeiro',
            'valor' => 27,
            'valor_pago' => 0,
            'valor_em_aberto' => 27,
            'estado' => 'pendente',
            'fatura_id' => $request->invoice_id,
        ]);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_financial_entry_present', $payload['items'][0]['classification']);
    }

    public function test_non_soft_deleted_request_is_unsafe_or_ignored_but_never_changed(): void
    {
        $request = $this->pendingFiscalRequest($this->invoice());
        $before = $this->fiscalRequestSnapshot($request->id);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame('unsafe_request_not_soft_deleted', $payload['items'][0]['classification']);
        $this->assertSame($before, $this->fiscalRequestSnapshot($request->id));
    }

    public function test_invoice_filter_limits_scope(): void
    {
        $first = $this->stalePendingFiscalRequest();
        $second = $this->stalePendingFiscalRequest([], $this->invoice(['mes' => '2026-07']));

        $payload = $this->jsonPayload(['--invoice' => $first->invoice_id]);

        $this->assertSame(1, $payload['summary']['total_candidates']);
        $this->assertSame($first->id, $payload['items'][0]['fiscal_request_id']);
        $this->assertNotSame($second->id, $payload['items'][0]['fiscal_request_id']);
    }

    public function test_json_output_is_valid(): void
    {
        $request = $this->stalePendingFiscalRequest();

        $payload = $this->jsonPayload();

        $this->assertSame($request->id, $payload['items'][0]['fiscal_request_id']);
        $this->assertArrayHasKey('summary', $payload);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->stalePendingFiscalRequest();
        $relativePath = 'storage/app/audits/stale-fiscal-request-cleanup-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:cleanup-stale-fiscal-requests', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame(1, json_decode((string) file_get_contents($absolutePath), true)['summary']['total_candidates']);
        @unlink($absolutePath);
    }

    public function test_fail_on_unsafe_returns_failure_when_unsafe_exists(): void
    {
        $this->stalePendingFiscalRequest(['external_document_number' => 'REC 2026/1']);

        $this->assertSame(1, Artisan::call('finance:cleanup-stale-fiscal-requests', [
            '--fail-on-unsafe' => true,
        ]));
    }

    public function test_invoice_obligation_audit_treats_archived_stale_request_as_info(): void
    {
        $request = $this->stalePendingFiscalRequest();

        $this->jsonPayload(['--apply' => true]);
        $audit = $this->invoiceAuditPayload($request->invoice_id);

        $this->assertNoAuditFinding($audit, 'fiscal_request_without_invoice_paid', $request->invoice_id);
        $this->assertNoAuditFinding($audit, 'fiscal_request_pending_for_unpaid_invoice', $request->invoice_id);
        $this->assertAuditFinding($audit, 'stale_fiscal_request_archived', $request->invoice_id, 'info');
    }

    public function test_dry_run_is_read_only(): void
    {
        $this->stalePendingFiscalRequest();
        $before = $this->fullSnapshot();

        $payload = $this->jsonPayload(['--dry-run' => true]);

        $this->assertSame(1, $payload['summary']['safe_to_archive_stale_request']);
        $this->assertSame($before, $this->fullSnapshot());
    }

    public function test_apply_only_changes_fiscal_request_metadata_and_notes(): void
    {
        $request = $this->stalePendingFiscalRequest();
        $this->paymentAllocation($request->invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);
        $before = $this->nonFiscalSnapshot();

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertSame(1, $payload['summary']['applied_count']);
        $this->assertSame($before, $this->nonFiscalSnapshot());
    }

    public function test_reversed_invoice_pending_request_is_detected_and_dry_run_is_read_only(): void
    {
        $invoice = $this->invoice();
        $request = $this->pendingFiscalRequest($invoice);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);
        $before = $this->fullSnapshot();

        $payload = $this->jsonPayload(['--invoice' => $invoice->id, '--dry-run' => true]);

        $this->assertSame(1, $payload['summary']['safe_to_archive_reversed_stale_request']);
        $this->assertSame('safe_to_archive_reversed_stale_request', $payload['items'][0]['classification']);
        $this->assertSame($request->id, $payload['items'][0]['fiscal_request_id']);
        $this->assertTrue($payload['items'][0]['can_archive_stale_request']);
        $this->assertSame($before, $this->fullSnapshot());
    }

    public function test_reversed_invoice_apply_marks_a4_6_metadata_and_is_idempotent(): void
    {
        $invoice = $this->invoice();
        $request = $this->pendingFiscalRequest($invoice, ['notes' => 'Nota inicial']);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id, '--apply' => true]);
        $first = FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));
        $secondPayload = $this->jsonPayload(['--invoice' => $invoice->id, '--apply' => true]);
        $second = FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();

        $this->assertSame(1, $payload['summary']['applied_count']);
        $this->assertSame('already_archived_stale_request', $payload['items'][0]['classification']);
        $this->assertTrue((bool) data_get($first->metadata, 'stale_cleanup'));
        $this->assertSame('a4-6', data_get($first->metadata, 'stale_cleanup_version'));
        $this->assertSame('pending_request_after_reversed_payment_allocation_without_external_document', data_get($first->metadata, 'stale_cleanup_reason'));
        $this->assertStringContainsString('[A4.6] Pedido fiscal pendente stale arquivado logicamente apos reversao de alocacao', (string) $first->notes);
        $this->assertNull($first->deleted_at);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $first->status);
        $this->assertSame(0, $secondPayload['summary']['applied_count']);
        $this->assertSame(data_get($first->metadata, 'stale_cleanup_at'), data_get($second->metadata, 'stale_cleanup_at'));
        $this->assertSame(1, substr_count((string) $second->notes, '[A4.6]'));
    }

    public function test_reversed_invoice_with_external_document_is_unsafe_and_not_changed(): void
    {
        $invoice = $this->invoice();
        $request = $this->pendingFiscalRequest($invoice, ['external_document_number' => 'FT 2026/9']);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id, '--apply' => true]);

        $this->assertSame('unsafe_external_document_present', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_reversed_invoice_with_active_confirmed_allocation_is_unsafe_and_not_changed(): void
    {
        $invoice = $this->invoice();
        $request = $this->pendingFiscalRequest($invoice);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CONFIRMED);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id, '--apply' => true]);

        $this->assertSame('unsafe_confirmed_allocation_present', $payload['items'][0]['classification']);
        $this->assertFalse((bool) data_get(FiscalDocumentRequest::withTrashed()->whereKey($request->id)->value('metadata'), 'stale_cleanup'));
    }

    public function test_reversed_invoice_archived_request_is_info_in_invoice_audit(): void
    {
        $invoice = $this->invoice();
        $this->pendingFiscalRequest($invoice);
        $this->paymentAllocation($invoice, PaymentAllocation::STATUS_CANCELLED, deleted: true);

        $this->jsonPayload(['--invoice' => $invoice->id, '--apply' => true]);
        $audit = $this->invoiceAuditPayload($invoice->id);

        $this->assertNoAuditFinding($audit, 'fiscal_request_without_invoice_paid', $invoice->id);
        $this->assertNoAuditFinding($audit, 'fiscal_request_pending_for_unpaid_invoice', $invoice->id);
        $this->assertAuditFinding($audit, 'stale_fiscal_request_archived', $invoice->id, 'info');
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:cleanup-stale-fiscal-requests', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function invoiceAuditPayload(string $invoiceId): array
    {
        $exitCode = Artisan::call('finance:audit-invoices', [
            '--invoice' => $invoiceId,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertAuditFinding(array $payload, string $code, string $invoiceId, string $severity): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['invoice_id'] === $invoiceId
                && $finding['severity'] === $severity,
        );

        $this->assertIsArray($finding, sprintf('Missing audit finding %s for invoice %s.', $code, $invoiceId));
    }

    private function assertNoAuditFinding(array $payload, string $code, string $invoiceId): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['invoice_id'] === $invoiceId,
        );

        $this->assertNull($finding, sprintf('Unexpected audit finding %s for invoice %s.', $code, $invoiceId));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-06-01',
            'data_emissao' => '2026-06-01',
            'data_vencimento' => '2026-06-15',
            'mes' => '2026-06',
            'valor_total' => 27,
            'valor_pago' => 0,
            'valor_em_aberto' => 27,
            'estado_pagamento' => 'vencido',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee_legacy',
            'origem_id' => null,
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade junho',
            'quantidade' => 1,
            'valor_unitario' => 30,
            'imposto_percentual' => 0,
            'total_linha' => 30,
        ]);
        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Desconto/Correcao financeira',
            'quantidade' => 1,
            'valor_unitario' => -3,
            'imposto_percentual' => 0,
            'total_linha' => -3,
        ]);

        return $invoice->fresh('items');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function stalePendingFiscalRequest(array $overrides = [], ?Invoice $invoice = null): FiscalDocumentRequest
    {
        $request = $this->pendingFiscalRequest($invoice ?? $this->invoice(), $overrides);
        $request->delete();

        return FiscalDocumentRequest::withTrashed()
            ->with('invoice')
            ->whereKey($request->id)
            ->firstOrFail();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function pendingFiscalRequest(Invoice $invoice, array $overrides = []): FiscalDocumentRequest
    {
        return FiscalDocumentRequest::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 27,
        ], $overrides));
    }

    private function paymentAllocation(Invoice $invoice, string $status, bool $deleted = false): PaymentAllocation
    {
        $payment = Payment::query()->create([
            'user_id' => $invoice->user_id,
            'amount' => 72,
            'allocated_amount' => $status === PaymentAllocation::STATUS_CONFIRMED ? 27 : 0,
            'unallocated_amount' => $status === PaymentAllocation::STATUS_CONFIRMED ? 45 : 72,
            'payment_date' => '2026-06-10',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 27,
            'status' => $status,
            'allocated_at' => now(),
        ]);

        if ($deleted) {
            $allocation->delete();

            return PaymentAllocation::withTrashed()->whereKey($allocation->id)->firstOrFail();
        }

        return $allocation;
    }

    private function financialEntryForReconciliation(): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-06-10',
            'tipo' => 'receita',
            'categoria' => 'Banco',
            'descricao' => 'Entrada bancaria',
            'valor' => 27,
            'valor_pago' => 27,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
        ]);
    }

    private function bankStatement(): BankStatement
    {
        return BankStatement::query()->create([
            'data_movimento' => '2026-06-10',
            'descricao' => 'Transferencia',
            'valor' => 27,
            'saldo' => 27,
            'conciliado' => true,
            'valor_conciliado' => 27,
            'valor_por_conciliar' => 0,
            'conciliacao_status' => 'confirmed',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fiscalRequestSnapshot(string $requestId): array
    {
        $request = FiscalDocumentRequest::withTrashed()
            ->whereKey($requestId)
            ->firstOrFail();

        return [
            'id' => (string) $request->id,
            'invoice_id' => $request->invoice_id ? (string) $request->invoice_id : null,
            'status' => $request->status,
            'notes' => $request->notes,
            'metadata' => $request->metadata,
            'external_document_number' => $request->external_document_number,
            'external_document_id' => $request->external_document_id,
            'issued_at' => $request->issued_at?->toIso8601String(),
            'deleted_at' => $request->deleted_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fullSnapshot(): array
    {
        return [
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'items' => InvoiceItem::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'fiscal_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
            'bank_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nonFiscalSnapshot(): array
    {
        return [
            'invoices' => Invoice::query()->orderBy('id')->get(['id', 'valor_total', 'valor_pago', 'valor_em_aberto', 'estado_pagamento', 'numero_recibo', 'recibo_emitido_em', 'recibo_pdf_path', 'receipt_import_item_id', 'updated_at'])->toArray(),
            'items' => InvoiceItem::query()->orderBy('id')->get(['id', 'fatura_id', 'descricao', 'valor_unitario', 'quantidade', 'imposto_percentual', 'total_linha', 'produto_id', 'updated_at'])->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get(['id', 'amount', 'allocated_amount', 'unallocated_amount', 'status', 'deleted_at', 'updated_at'])->toArray(),
            'allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get(['id', 'payment_id', 'invoice_id', 'amount', 'status', 'deleted_at', 'updated_at'])->toArray(),
            'bank_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
