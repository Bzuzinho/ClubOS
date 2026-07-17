<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReceiptImportItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PendingFiscalRequestInspectionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_paid_confirmed_pending_without_external_document_recommends_external_issue(): void
    {
        [$invoice, $payment, $allocation, $request] = $this->paidPendingChain();

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $item = $payload['items'][0];

        $this->assertSame('process_external_fiscal_document', $item['decision']['recommended_next_action']);
        $this->assertSame('medium', $item['decision']['risk_level']);
        $this->assertTrue($item['decision']['should_process_external_issue']);
        $this->assertFalse($item['decision']['can_archive_without_external_issue']);
        $this->assertTrue($item['decision']['actionable']);
        $this->assertContains('invoice_paid', $item['reasons']);
        $this->assertContains('payment_confirmed', $item['reasons']);
        $this->assertContains('allocation_confirmed', $item['reasons']);
        $this->assertContains('amount_matches_invoice_or_allocation', $item['reasons']);
        $this->assertContains('fiscal_obligation_likely_real', $item['reasons']);
        $this->assertSame($invoice->id, $item['invoice']['id']);
        $this->assertSame($payment->id, $item['payment'][0]['id']);
        $this->assertSame($allocation->id, $item['allocation'][0]['id']);
        $this->assertSame(1, $payload['summary']['process_external_issue_count']);
        $this->assertSame(1, $payload['summary']['actionable_count']);
    }

    public function test_historical_or_test_signal_recommends_archive_without_external_issue(): void
    {
        $invoice = $this->invoice([
            'estado_pagamento' => 'pendente',
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'origem_tipo' => 'legacy_test_fixture',
            'observacoes' => 'Teste historico sem obrigacao real',
        ]);
        $request = $this->pendingRequest($invoice, [
            'metadata' => ['legacy_only' => true, 'test' => true],
        ]);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $item = $payload['items'][0];

        $this->assertSame('archive_historical_pending_without_external_document', $item['decision']['recommended_next_action']);
        $this->assertSame('low', $item['decision']['risk_level']);
        $this->assertFalse($item['decision']['should_process_external_issue']);
        $this->assertTrue($item['decision']['can_archive_without_external_issue']);
        $this->assertFalse($item['decision']['actionable']);
        $this->assertContains('legacy_or_test_data_signal', $item['reasons']);
        $this->assertSame(1, $payload['summary']['archive_historical_count']);
        $this->assertSame(0, $payload['summary']['actionable_count']);
    }

    public function test_provider_waiting_metadata_recommends_keep_pending(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: [
            'metadata' => ['provider_waiting' => true, 'queue' => 'wintouch'],
        ]);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $item = $payload['items'][0];

        $this->assertSame('keep_pending_waiting_provider', $item['decision']['recommended_next_action']);
        $this->assertSame('medium', $item['decision']['risk_level']);
        $this->assertFalse($item['decision']['should_process_external_issue']);
        $this->assertFalse($item['decision']['can_archive_without_external_issue']);
        $this->assertFalse($item['decision']['actionable']);
        $this->assertContains('provider_queue_missing_response', $item['reasons']);
        $this->assertSame(1, $payload['summary']['keep_pending_count']);
    }

    public function test_incomplete_data_recommends_manual_review(): void
    {
        $request = FiscalDocumentRequest::query()->create([
            'invoice_id' => null,
            'user_id' => User::factory()->create()->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 20,
            'created_at' => now()->subDays(40),
        ]);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $item = $payload['items'][0];

        $this->assertSame('manual_review_required', $item['decision']['recommended_next_action']);
        $this->assertSame('high', $item['decision']['risk_level']);
        $this->assertTrue($item['decision']['actionable']);
        $this->assertContains('no_external_document', $item['reasons']);
        $this->assertSame(1, $payload['summary']['manual_review_count']);
    }

    public function test_filters_by_fiscal_request_invoice_payment_and_user(): void
    {
        [$invoiceA, $paymentA, , $requestA] = $this->paidPendingChain(amount: 20);
        [$invoiceB, , , $requestB] = $this->paidPendingChain(amount: 30);

        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--fiscal-request' => $requestA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--invoice' => $invoiceA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--payment' => $paymentA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestB->id], collect($this->jsonPayload(['--user' => $invoiceB->user_id])['items'])->pluck('fiscal_request.id')->all());
    }

    public function test_only_actionable_removes_non_actionable_decisions(): void
    {
        $this->paidPendingChain();
        $this->paidPendingChain(requestOverrides: ['metadata' => ['provider_waiting' => true]]);

        $payload = $this->jsonPayload(['--only-actionable' => true]);

        $this->assertSame(1, $payload['summary']['total_pending_scanned']);
        $this->assertSame(['process_external_fiscal_document'], collect($payload['items'])->pluck('decision.recommended_next_action')->all());
    }

    public function test_fail_on_actionable_returns_failure_for_process_or_manual_review(): void
    {
        $this->paidPendingChain();

        $this->assertSame(1, Artisan::call('finance:inspect-pending-fiscal-requests', [
            '--fail-on-actionable' => true,
        ]));

        FiscalDocumentRequest::query()->delete();
        $this->paidPendingChain(requestOverrides: ['metadata' => ['provider_waiting' => true]]);

        $this->assertSame(0, Artisan::call('finance:inspect-pending-fiscal-requests', [
            '--fail-on-actionable' => true,
        ]));
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $relativePath = 'storage/app/testing/pending-fiscal-request-inspection.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $this->assertSame('a6-2-pending-fiscal-request-inspection-v1', $payload['version']);
        $this->assertArrayHasKey('summary', $payload);

        $exitCode = Artisan::call('finance:inspect-pending-fiscal-requests', [
            '--fiscal-request' => $request->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame($request->id, json_decode((string) file_get_contents($absolutePath), true)['items'][0]['fiscal_request']['id']);
        @unlink($absolutePath);
    }

    public function test_inspection_is_read_only(): void
    {
        $this->paidPendingChain(withBankTrace: true);
        $before = $this->snapshot();

        $payload = $this->jsonPayload();

        $this->assertSame(1, $payload['summary']['total_pending_scanned']);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:inspect-pending-fiscal-requests', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $invoiceOverrides
     */
    private function invoice(array $invoiceOverrides = [], float $amount = 20): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
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
            'origem_tipo' => 'monthly_fee',
        ], $invoiceOverrides));

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
     * @param array<string,mixed> $requestOverrides
     * @return array{0:Invoice,1:Payment,2:PaymentAllocation,3:FiscalDocumentRequest}
     */
    private function paidPendingChain(float $amount = 20, array $requestOverrides = [], bool $withBankTrace = false): array
    {
        $invoice = $this->invoice(amount: $amount);
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

        if ($withBankTrace) {
            $bank = BankStatement::query()->create([
                'data_movimento' => '2026-06-10',
                'descricao' => 'Pagamento mensalidade',
                'valor' => $amount,
                'conciliado' => true,
                'valor_conciliado' => $amount,
                'valor_por_conciliar' => 0,
                'conciliacao_status' => 'conciliado',
            ]);
            $payment->update(['bank_statement_id' => $bank->id, 'source' => Payment::SOURCE_BANK_STATEMENT]);
            $entry = FinancialEntry::query()->create([
                'data' => '2026-06-10',
                'tipo' => 'receita',
                'categoria' => 'Mensalidade',
                'descricao' => 'Pagamento mensalidade',
                'valor' => $amount,
                'valor_pago' => $amount,
                'valor_em_aberto' => 0,
                'estado' => 'pago',
                'data_pagamento' => '2026-06-10',
                'user_id' => $invoice->user_id,
                'fatura_id' => $invoice->id,
                'payment_id' => $payment->id,
                'bank_statement_id' => $bank->id,
                'origem_tipo' => 'payment_allocation',
                'origem_modulo' => 'financeiro',
                'origem_id' => $allocation->id,
            ]);
            $allocation->update(['financial_entry_id' => $entry->id]);
            $map = MapaConciliacao::query()->create([
                'extrato_id' => $bank->id,
                'lancamento_id' => $entry->id,
                'fatura_id' => $invoice->id,
                'payment_id' => $payment->id,
                'payment_allocation_id' => $allocation->id,
                'valor_conciliado' => $amount,
                'status' => 'confirmado',
            ]);
            BankTransactionAllocation::query()->create([
                'bank_statement_id' => $bank->id,
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'payment_id' => $payment->id,
                'payment_allocation_id' => $allocation->id,
                'mapa_conciliacao_id' => $map->id,
                'valor_alocado' => $amount,
                'status' => BankTransactionAllocation::STATUS_CONFIRMED,
                'origem' => 'test',
                'committed_at' => '2026-06-10 12:00:00',
            ]);
        }

        $request = $this->pendingRequest($invoice, $requestOverrides, $amount);

        return [$invoice, $payment->fresh(), $allocation, $request];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function pendingRequest(Invoice $invoice, array $overrides = [], float $amount = 20): FiscalDocumentRequest
    {
        return FiscalDocumentRequest::query()->create(array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => $amount,
            'paid_at' => '2026-06-10',
            'due_at' => '2026-06-15',
            'created_at' => now()->subDays(40),
        ], $overrides))->fresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'fiscal_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
            'receipt_import_items' => ReceiptImportItem::query()->orderBy('id')->get()->toArray(),
            'mapa_conciliacao' => MapaConciliacao::query()->orderBy('id')->get()->toArray(),
            'bank_transaction_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
            'bank_statements' => BankStatement::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
