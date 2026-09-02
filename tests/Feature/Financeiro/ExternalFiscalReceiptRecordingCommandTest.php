<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosPessoais;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ExternalFiscalReceiptRecordingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_with_receipt_number_and_issued_at_shows_preview_and_does_not_change_database(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $payload = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-001',
            '--issued-at' => '2026-06-11',
        ]);

        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['ready_to_record']);
        $this->assertSame('dry_run_ready', $payload['action']);
        $this->assertSame('REC-EXT-001', data_get($payload, 'changes_preview.fiscal_document_request.external_document_number.to'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_without_confirm_manual_receipt_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-002',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_confirm_manual_receipt', $payload['blocked_reasons']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_without_receipt_number_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_receipt_number', $payload['blocked_reasons']);
    }

    public function test_manual_mode_rejects_a_provider_different_from_the_productive_provider(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--provider' => 'unexpected_provider',
            '--receipt-number' => 'REC-EXT-UNEXPECTED',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('provider_not_allowed_in_manual_operation_mode', $payload['blocked_reasons']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_without_issued_at_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-003',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_issued_at', $payload['blocked_reasons']);
    }

    public function test_missing_fiscal_request_blocks(): void
    {
        [$exitCode, $payload] = $this->callJson('missing-request', [
            '--receipt-number' => 'REC-EXT-004',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('fiscal_request_not_found', $payload['blocked_reasons']);
    }

    public function test_already_issued_request_is_skipped_when_same_data_and_blocks_when_different(): void
    {
        [$invoice, , , $request] = $this->paidPendingChain();
        $request->forceFill([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'REC-EXT-005',
            'issued_at' => '2026-06-11 10:00:00',
            'handled_at' => '2026-06-11 10:00:00',
        ])->save();
        $invoice->forceFill([
            'numero_recibo' => 'REC-EXT-005',
            'recibo_emitido_em' => '2026-06-11',
        ])->save();

        $same = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-005',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertTrue($same['skipped']);
        $this->assertSame('already_recorded', $same['action']);

        [$exitCode, $different] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-DIFFERENT',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('already_has_fiscal_document_signal', $different['blocked_reasons']);
    }

    public function test_invoice_with_different_receipt_blocks(): void
    {
        [$invoice, , , $request] = $this->paidPendingChain();
        $invoice->forceFill(['numero_recibo' => 'REC-OLD'])->save();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-006',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('invoice_already_has_receipt_signal', $payload['blocked_reasons']);
    }

    public function test_amount_mismatch_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['amount' => 99]);

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-007',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('amount_mismatch', $payload['blocked_reasons']);
    }

    public function test_unconfirmed_payment_or_allocation_blocks(): void
    {
        [, $payment, $allocation, $request] = $this->paidPendingChain();
        $allocation->forceFill(['status' => PaymentAllocation::STATUS_CANCELLED])->save();
        $payment->forceFill(['status' => Payment::STATUS_CANCELLED])->save();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-008',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_confirmed_payment_allocation', $payload['blocked_reasons']);
    }

    public function test_duplicate_receipt_number_same_provider_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain();
        [, , , $other] = $this->paidPendingChain();
        $other->forceFill([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'REC-DUP',
            'issued_at' => '2026-06-12',
        ])->save();

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-DUP',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('duplicate_receipt_number_for_provider', $payload['blocked_reasons']);
    }

    public function test_issued_at_before_invoice_date_blocks(): void
    {
        [, , , $request] = $this->paidPendingChain(invoiceOverrides: ['data_emissao' => '2026-06-15']);

        [$exitCode, $payload] = $this->callJson($request->id, [
            '--receipt-number' => 'REC-EXT-009',
            '--issued-at' => '2026-06-14',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertContains('issued_at_before_invoice_date', $payload['blocked_reasons']);
    }

    public function test_valid_apply_updates_fiscal_request_and_invoice(): void
    {
        [$invoice, , , $request] = $this->paidPendingChain();

        $payload = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-010',
            '--issued-at' => '2026-06-11 15:30:00',
            '--external-document-id' => 'external-10',
            '--receipt-pdf-path' => 'receipts/REC-EXT-010.pdf',
            '--notes' => 'Emitido manualmente no software certificado externo.',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertTrue($payload['applied']);
        $this->assertSame('recorded', $payload['action']);

        $request->refresh();
        $invoice->refresh();

        $this->assertSame(FiscalDocumentRequest::STATUS_ISSUED, $request->status);
        $this->assertSame('REC-EXT-010', $request->external_document_number);
        $this->assertSame('external-10', $request->external_document_id);
        $this->assertSame('wintouch', $request->provider);
        $this->assertSame('2026-06-11', $request->issued_at?->toDateString());
        $this->assertSame('manual_external_receipt', data_get($request->metadata, 'manual_receipt_recording.source'));
        $this->assertTrue((bool) data_get($request->metadata, 'manual_receipt_recording.recorded_manually'));
        $this->assertSame('REC-EXT-010', $invoice->numero_recibo);
        $this->assertSame('2026-06-11', $invoice->recibo_emitido_em?->toDateString());
        $this->assertSame('receipts/REC-EXT-010.pdf', $invoice->recibo_pdf_path);
    }

    public function test_valid_apply_does_not_change_payments_allocations_or_financial_entries(): void
    {
        [, , , $request] = $this->paidPendingChain(withFinancialEntry: true);
        $before = $this->financialSnapshot();

        $payload = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-011',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertTrue($payload['applied']);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_second_equal_execution_is_idempotent(): void
    {
        [, , , $request] = $this->paidPendingChain();

        $first = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-012',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);
        $second = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-012',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $this->assertTrue($first['applied']);
        $this->assertTrue($second['skipped']);
        $this->assertSame('already_recorded', $second['action']);
    }

    public function test_fiscal_audit_no_longer_marks_request_as_pending_after_recording(): void
    {
        [, , , $request] = $this->paidPendingChain();

        $before = $this->auditPayload($request->id, ['--only-actionable' => true]);
        $this->assertContains('pending_fiscal_request_ready_for_external_issue', collect($before['findings'])->pluck('code')->all());

        $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-013',
            '--issued-at' => '2026-06-11',
            '--apply' => true,
            '--confirm-manual-receipt' => true,
        ]);

        $afterActionable = $this->auditPayload($request->id, ['--only-actionable' => true]);
        $this->assertSame([], $afterActionable['findings']);

        $afterClean = $this->auditPayload($request->id, ['--include-clean' => true]);
        $this->assertContains('clean_fiscal_chain', collect($afterClean['findings'])->pluck('code')->all());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $relativePath = 'storage/app/testing/external-receipt-recording-report.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload($request->id, [
            '--receipt-number' => 'REC-EXT-014',
            '--issued-at' => '2026-06-11',
        ]);

        $this->assertSame('a6-5-external-fiscal-receipt-recording-v1', $payload['version']);

        $exitCode = Artisan::call('finance:record-external-fiscal-receipt', [
            'fiscal_request' => $request->id,
            '--receipt-number' => 'REC-EXT-014',
            '--issued-at' => '2026-06-11',
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame($request->id, json_decode((string) file_get_contents($absolutePath), true)['fiscal_request_id']);
        @unlink($absolutePath);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(string $requestId, array $options = []): array
    {
        [$exitCode, $payload] = $this->callJson($requestId, $options);
        $this->assertSame(0, $exitCode);

        return $payload;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callJson(string $requestId, array $options = []): array
    {
        $exitCode = Artisan::call('finance:record-external-fiscal-receipt', array_merge([
            'fiscal_request' => $requestId,
            '--json' => true,
        ], $options));

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return [$exitCode, $payload];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function auditPayload(string $requestId, array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-fiscal-documents', array_merge([
            '--fiscal-request' => $requestId,
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'name' => 'External Receipt Member',
            'email' => 'external-receipt-' . (string) str()->uuid() . '@example.test',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'External Receipt Member',
            'nif' => '123456789',
            'morada' => 'Rua Fiscal 2',
            'codigo_postal' => '1000-200',
            'localidade' => 'Lisboa',
        ]);

        return $user;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(User $user, array $overrides = [], float $amount = 20): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => $user->id,
            'data_fatura' => '2026-06-01',
            'data_emissao' => '2026-06-01',
            'data_vencimento' => '2026-06-15',
            'mes' => '2026-06',
            'valor_total' => $amount,
            'valor_pago' => $amount,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-06-10',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee_legacy',
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade',
            'quantidade' => 1,
            'valor_unitario' => $amount,
            'imposto_percentual' => 0,
            'total_linha' => $amount,
        ]);

        return $invoice->fresh();
    }

    /**
     * @param array<string,mixed> $invoiceOverrides
     * @param array<string,mixed> $requestOverrides
     * @return array{0:Invoice,1:Payment,2:PaymentAllocation,3:FiscalDocumentRequest}
     */
    private function paidPendingChain(float $amount = 20, array $invoiceOverrides = [], array $requestOverrides = [], bool $withFinancialEntry = false): array
    {
        $user = $this->member();
        $invoice = $this->invoice($user, $invoiceOverrides, $amount);
        $payment = Payment::query()->create([
            'user_id' => $invoice->user_id,
            'amount' => $amount,
            'allocated_amount' => $amount,
            'unallocated_amount' => 0,
            'payment_date' => '2026-06-10',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-06-10 12:00:00',
        ]);

        if ($withFinancialEntry) {
            FinancialEntry::query()->create([
                'data' => '2026-06-10',
                'tipo' => 'receita',
                'categoria' => 'Mensalidade',
                'descricao' => 'Pagamento mensalidade',
                'documento_ref' => $invoice->referencia_pagamento,
                'valor' => $amount,
                'valor_pago' => $amount,
                'valor_em_aberto' => 0,
                'estado' => 'pago',
                'data_pagamento' => '2026-06-10',
                'user_id' => $invoice->user_id,
                'fatura_id' => $invoice->id,
                'payment_id' => $payment->id,
                'origem_tipo' => 'invoice',
                'origem_modulo' => 'financeiro',
                'origem_id' => $invoice->id,
            ]);
        }

        $request = FiscalDocumentRequest::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => $amount,
            'paid_at' => '2026-06-10',
            'due_at' => '2026-06-15',
            'customer_name' => 'External Receipt Member',
            'customer_tax_number' => '123456789',
            'customer_email' => 'external-receipt@example.test',
            'customer_address' => 'Rua Fiscal 2',
            'created_at' => now()->subDays(10),
            'last_error' => null,
        ], $requestOverrides));

        return [$invoice, $payment, $allocation, $request];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'invoice_items' => InvoiceItem::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
            'fiscal_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function financialSnapshot(): array
    {
        return [
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
