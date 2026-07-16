<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FiscalRequestAnomalyInspectionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_missing_invoice_returns_failure(): void
    {
        $exitCode = Artisan::call('finance:inspect-fiscal-request-anomaly', [
            'invoice' => (string) Str::uuid(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invoice not found', Artisan::output());
    }

    public function test_unpaid_invoice_with_soft_deleted_pending_fiscal_request_is_medium_risk(): void
    {
        $invoice = $this->invoice();
        $this->cancelledAllocation($invoice);
        $this->pendingFiscalRequest($invoice, deleted: true);

        $payload = $this->jsonPayload($invoice->id);

        $this->assertContains('pending_fiscal_request_for_unpaid_invoice', $payload['detected_anomalies']);
        $this->assertContains('soft_deleted_pending_fiscal_request_still_affecting_invoice_audit', $payload['detected_anomalies']);
        $this->assertContains('unconfirmed_payment_allocation_present', $payload['detected_anomalies']);
        $this->assertContains('payment_record_present_without_confirmed_allocation', $payload['detected_anomalies']);
        $this->assertContains('invoice_protected_from_automatic_change', $payload['detected_anomalies']);
        $this->assertSame('medium', $payload['risk_level']);
        $this->assertFalse($payload['can_auto_fix']);
        $this->assertSame('detach_or_archive_stale_pending_fiscal_request', $payload['future_action_candidate']);
        $this->assertSame('review_stale_pending_fiscal_request_manually', $payload['recommended_next_action']);
    }

    public function test_reversed_payment_allocation_context_marks_safe_archive_candidate(): void
    {
        $invoice = $this->invoice();
        $allocation = $this->cancelledAllocation($invoice);
        $allocation->delete();
        $this->pendingFiscalRequest($invoice);

        $payload = $this->jsonPayload($invoice->id);

        $this->assertTrue($payload['can_archive_stale_request']);
        $this->assertSame(1, $payload['reversal_context']['pending_fiscal_request_count']);
        $this->assertTrue($payload['reversal_context']['has_soft_deleted_cancelled_allocation']);
        $this->assertFalse($payload['reversal_context']['has_external_document']);
        $this->assertFalse($payload['reversal_context']['has_active_confirmed_allocation']);
        $this->assertTrue($payload['reversal_context']['can_archive_stale_request']);
    }

    public function test_external_fiscal_document_keeps_high_risk_and_blocks_auto_fix(): void
    {
        $invoice = $this->invoice();
        $this->pendingFiscalRequest($invoice, [
            'external_document_number' => 'FT 2026/1',
        ]);

        $payload = $this->jsonPayload($invoice->id);

        $this->assertSame('high', $payload['risk_level']);
        $this->assertFalse($payload['can_auto_fix']);
        $this->assertSame('do_not_modify_external_fiscal_document_without_manual_review', $payload['recommended_next_action']);
    }

    public function test_confirmed_payment_allocation_keeps_high_risk_and_blocks_auto_fix(): void
    {
        $invoice = $this->invoice();
        $this->confirmedAllocation($invoice);

        $payload = $this->jsonPayload($invoice->id);

        $this->assertSame('high', $payload['risk_level']);
        $this->assertFalse($payload['can_auto_fix']);
        $this->assertContains('invoice_protected_from_automatic_change', $payload['detected_anomalies']);
    }

    public function test_clean_invoice_has_low_risk(): void
    {
        $invoice = $this->invoice();

        $payload = $this->jsonPayload($invoice->id);

        $this->assertSame([], $payload['detected_anomalies']);
        $this->assertSame('low', $payload['risk_level']);
        $this->assertFalse($payload['can_auto_fix']);
        $this->assertNull($payload['future_action_candidate']);
        $this->assertSame('no_action_needed', $payload['recommended_next_action']);
    }

    public function test_json_output_is_valid(): void
    {
        $invoice = $this->invoice();

        $payload = $this->jsonPayload($invoice->id);

        $this->assertSame($invoice->id, $payload['invoice_id']);
        $this->assertArrayHasKey('invoice_snapshot', $payload);
    }

    public function test_report_path_writes_json_file(): void
    {
        $invoice = $this->invoice();
        $relativePath = 'storage/app/audits/fiscal-request-anomaly-inspection-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:inspect-fiscal-request-anomaly', [
            'invoice' => $invoice->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame($invoice->id, json_decode((string) file_get_contents($absolutePath), true)['invoice_id']);
        @unlink($absolutePath);
    }

    public function test_fail_on_actionable_returns_failure_for_medium_or_high_risk(): void
    {
        $medium = $this->invoice(['mes' => '2026-06']);
        $this->pendingFiscalRequest($medium, deleted: true);

        $low = $this->invoice(['mes' => '2026-07']);

        $this->assertSame(1, Artisan::call('finance:inspect-fiscal-request-anomaly', [
            'invoice' => $medium->id,
            '--fail-on-actionable' => true,
        ]));
        $this->assertSame(0, Artisan::call('finance:inspect-fiscal-request-anomaly', [
            'invoice' => $low->id,
            '--fail-on-actionable' => true,
        ]));
    }

    public function test_inspection_is_read_only(): void
    {
        $invoice = $this->invoice();
        $this->cancelledAllocation($invoice);
        $this->pendingFiscalRequest($invoice, deleted: true);
        $before = $this->snapshot();

        $payload = $this->jsonPayload($invoice->id);

        $this->assertSame('medium', $payload['risk_level']);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonPayload(string $invoiceId): array
    {
        $exitCode = Artisan::call('finance:inspect-fiscal-request-anomaly', [
            'invoice' => $invoiceId,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
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
            'descricao' => 'Mensalidade legacy classificada',
            'quantidade' => 1,
            'valor_unitario' => 27,
            'imposto_percentual' => 0,
            'total_linha' => 27,
        ]);

        return $invoice->fresh('items');
    }

    private function cancelledAllocation(Invoice $invoice): PaymentAllocation
    {
        return $this->allocation($invoice, PaymentAllocation::STATUS_CANCELLED);
    }

    private function confirmedAllocation(Invoice $invoice): PaymentAllocation
    {
        return $this->allocation($invoice, PaymentAllocation::STATUS_CONFIRMED);
    }

    private function allocation(Invoice $invoice, string $status): PaymentAllocation
    {
        $payment = Payment::query()->create([
            'user_id' => $invoice->user_id,
            'amount' => 27,
            'allocated_amount' => $status === PaymentAllocation::STATUS_CONFIRMED ? 27 : 0,
            'unallocated_amount' => $status === PaymentAllocation::STATUS_CONFIRMED ? 0 : 27,
            'payment_date' => '2026-06-10',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        return PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 27,
            'status' => $status,
            'allocated_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function pendingFiscalRequest(Invoice $invoice, array $overrides = [], bool $deleted = false): FiscalDocumentRequest
    {
        $request = FiscalDocumentRequest::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 27,
        ], $overrides));

        if ($deleted) {
            $request->delete();

            return FiscalDocumentRequest::withTrashed()->whereKey($request->id)->firstOrFail();
        }

        return $request->fresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'invoices' => Invoice::query()
                ->orderBy('id')
                ->get(['id', 'valor_total', 'valor_pago', 'valor_em_aberto', 'estado_pagamento', 'updated_at'])
                ->toArray(),
            'payments' => Payment::withTrashed()
                ->orderBy('id')
                ->get(['id', 'amount', 'allocated_amount', 'unallocated_amount', 'status', 'deleted_at', 'updated_at'])
                ->toArray(),
            'allocations' => PaymentAllocation::withTrashed()
                ->orderBy('id')
                ->get(['id', 'payment_id', 'invoice_id', 'amount', 'status', 'deleted_at', 'updated_at'])
                ->toArray(),
            'fiscal_requests' => FiscalDocumentRequest::withTrashed()
                ->orderBy('id')
                ->get(['id', 'invoice_id', 'status', 'external_document_number', 'external_document_id', 'deleted_at', 'updated_at'])
                ->toArray(),
        ];
    }
}
