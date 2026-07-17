<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\DadosPessoais;
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

final class FiscalDocumentIssuePreflightCommandTest extends TestCase
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

    public function test_ready_paid_confirmed_request_is_safe_to_issue(): void
    {
        [$invoice, $payment, $allocation, $request] = $this->paidPendingChain();

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $item = $payload['items'][0];

        $this->assertSame('a6-3-fiscal-document-issue-preflight-v1', $payload['version']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($item['readiness']['ready']);
        $this->assertTrue($item['readiness']['safe_to_issue']);
        $this->assertSame([], $item['readiness']['blocked_reasons']);
        $this->assertSame($invoice->id, $item['invoice']['id']);
        $this->assertSame($payment->id, $item['payment_allocation']['payment_id']);
        $this->assertSame($allocation->id, $item['payment_allocation']['allocation_id']);
        $this->assertSame('123456789', $item['user']['nif']);
        $this->assertSame(1, $payload['summary']['ready_count']);
        $this->assertSame(0, $payload['summary']['blocked_count']);
    }

    public function test_missing_customer_tax_number_blocks_readiness(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['customer_tax_number' => null], withMemberFiscalData: false);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('missing_customer_fiscal_identity', $item['readiness']['blocked_reasons']);
        $this->assertContains('customer_tax_number', $item['readiness']['required_fields_missing']);
    }

    public function test_missing_line_items_blocks_readiness(): void
    {
        [, , , $request] = $this->paidPendingChain(withItems: false);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('missing_line_items', $item['readiness']['blocked_reasons']);
    }

    public function test_missing_provider_blocks_readiness(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['provider' => 'unknown_provider']);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('missing_or_unknown_provider', $item['readiness']['blocked_reasons']);
    }

    public function test_existing_receipt_signal_blocks_readiness(): void
    {
        [, , , $request] = $this->paidPendingChain(invoiceOverrides: ['numero_recibo' => 'REC-1']);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('already_has_fiscal_document_signal', $item['readiness']['blocked_reasons']);
    }

    public function test_amount_mismatch_blocks_readiness(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['amount' => 19]);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('amount_mismatch', $item['readiness']['blocked_reasons']);
    }

    public function test_unconfirmed_payment_or_allocation_blocks_readiness(): void
    {
        [, $payment, $allocation, $request] = $this->paidPendingChain();
        $payment->update(['status' => Payment::STATUS_DRAFT]);
        $allocation->update(['status' => PaymentAllocation::STATUS_CANCELLED]);

        $item = $this->jsonPayload(['--fiscal-request' => $request->id])['items'][0];

        $this->assertFalse($item['readiness']['ready']);
        $this->assertContains('missing_confirmed_payment_or_allocation', $item['readiness']['blocked_reasons']);
    }

    public function test_multi_allocation_payment_keeps_items_separate_and_grouping_informative(): void
    {
        $user = $this->member();
        $invoiceA = $this->invoice($user, amount: 20);
        $invoiceB = $this->invoice($user, amount: 30);
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'allocated_amount' => 50,
            'unallocated_amount' => 0,
            'payment_date' => '2026-06-10',
            'method' => 'transferencia',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoiceA->id,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-06-10 12:00:00',
        ]);
        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoiceB->id,
            'amount' => 30,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-06-10 12:00:00',
        ]);
        $this->pendingRequest($invoiceA, amount: 20);
        $this->pendingRequest($invoiceB, amount: 30);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertSame(2, $payload['summary']['total_candidates']);
        $this->assertSame(1, $payload['summary']['payment_groups_count']);
        $this->assertTrue($payload['groups'][0]['payment_is_multi_allocation']);
        $this->assertEquals(50.0, $payload['groups'][0]['group_total']);
        $this->assertSame(2, $payload['groups'][0]['item_count_in_group']);
        $this->assertEqualsCanonicalizing([$invoiceA->id, $invoiceB->id], collect($payload['items'])->pluck('invoice.id')->all());
    }

    public function test_only_ready_filters_blocked_items(): void
    {
        $this->paidPendingChain();
        $this->paidPendingChain(requestOverrides: ['customer_tax_number' => null], withMemberFiscalData: false);

        $payload = $this->jsonPayload(['--only-ready' => true]);

        $this->assertSame(1, $payload['summary']['total_candidates']);
        $this->assertTrue($payload['items'][0]['readiness']['ready']);
    }

    public function test_fail_on_blocked_only_fails_when_blocked_items_exist(): void
    {
        $this->paidPendingChain(requestOverrides: ['customer_tax_number' => null], withMemberFiscalData: false);

        $this->assertSame(1, Artisan::call('finance:preflight-fiscal-document-issue', [
            '--fail-on-blocked' => true,
        ]));

        FiscalDocumentRequest::query()->delete();
        $this->paidPendingChain();

        $this->assertSame(0, Artisan::call('finance:preflight-fiscal-document-issue', [
            '--fail-on-blocked' => true,
        ]));
    }

    public function test_filters_by_fiscal_request_invoice_payment_user_and_provider(): void
    {
        [$invoiceA, $paymentA, , $requestA] = $this->paidPendingChain(amount: 20);
        [$invoiceB, , , $requestB] = $this->paidPendingChain(amount: 30);

        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--fiscal-request' => $requestA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--invoice' => $invoiceA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--payment' => $paymentA->id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertSame([$requestB->id], collect($this->jsonPayload(['--user' => $invoiceB->user_id])['items'])->pluck('fiscal_request.id')->all());
        $this->assertEqualsCanonicalizing([$requestA->id, $requestB->id], collect($this->jsonPayload(['--provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH])['items'])->pluck('fiscal_request.id')->all());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $relativePath = 'storage/app/testing/fiscal-document-issue-preflight.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--fiscal-request' => $request->id]);
        $this->assertSame($request->id, $payload['items'][0]['fiscal_request']['id']);
        $this->assertArrayHasKey('provider_payload_preview', $payload['items'][0]);

        $exitCode = Artisan::call('finance:preflight-fiscal-document-issue', [
            '--fiscal-request' => $request->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame($request->id, json_decode((string) file_get_contents($absolutePath), true)['items'][0]['fiscal_request']['id']);
        @unlink($absolutePath);
    }

    public function test_preflight_is_read_only(): void
    {
        $this->paidPendingChain(withBankTrace: true);
        $before = $this->snapshot();

        $payload = $this->jsonPayload();

        $this->assertSame(1, $payload['summary']['total_candidates']);
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:preflight-fiscal-document-issue', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function member(bool $withFiscalData = true): User
    {
        $user = User::factory()->create([
            'name' => 'Preflight Member',
            'email' => 'preflight-' . (string) str()->uuid() . '@example.test',
        ]);

        if ($withFiscalData) {
            DadosPessoais::query()->create([
                'user_id' => $user->id,
                'nome_completo' => 'Preflight Member',
                'nif' => '123456789',
                'morada' => 'Rua Fiscal 1',
                'codigo_postal' => '1000-100',
                'localidade' => 'Lisboa',
            ]);
        }

        return $user;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(User $user, array $overrides = [], float $amount = 20, bool $withItems = true): Invoice
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

        if ($withItems) {
            InvoiceItem::query()->create([
                'fatura_id' => $invoice->id,
                'descricao' => 'Mensalidade',
                'quantidade' => 1,
                'valor_unitario' => $amount,
                'imposto_percentual' => 0,
                'total_linha' => $amount,
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * @param array<string,mixed> $invoiceOverrides
     * @param array<string,mixed> $requestOverrides
     * @return array{0:Invoice,1:Payment,2:PaymentAllocation,3:FiscalDocumentRequest}
     */
    private function paidPendingChain(float $amount = 20, array $invoiceOverrides = [], array $requestOverrides = [], bool $withItems = true, bool $withMemberFiscalData = true, bool $withBankTrace = false): array
    {
        $user = $this->member($withMemberFiscalData);
        $invoice = $this->invoice($user, $invoiceOverrides, $amount, $withItems);
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
                'descricao' => 'Pagamento fiscal',
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
                'categoria' => 'Fiscal',
                'descricao' => 'Pagamento fiscal',
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
            MapaConciliacao::query()->create([
                'extrato_id' => $bank->id,
                'lancamento_id' => $entry->id,
                'fatura_id' => $invoice->id,
                'payment_id' => $payment->id,
                'payment_allocation_id' => $allocation->id,
                'valor_conciliado' => $amount,
                'status' => 'confirmado',
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
        $payload = array_merge([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => $amount,
            'paid_at' => '2026-06-10',
            'due_at' => '2026-06-15',
            'customer_name' => 'Preflight Member',
            'customer_tax_number' => '123456789',
            'customer_email' => 'preflight@example.test',
            'customer_address' => 'Rua Fiscal 1',
            'created_at' => now()->subDays(10),
        ], $overrides);

        $existing = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->first();

        if ($existing instanceof FiscalDocumentRequest) {
            $existing->forceFill($payload)->save();

            return $existing->fresh();
        }

        return FiscalDocumentRequest::query()->create($payload)->fresh();
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
            'fiscal_requests' => FiscalDocumentRequest::withTrashed()->orderBy('id')->get()->toArray(),
            'receipt_import_items' => ReceiptImportItem::query()->orderBy('id')->get()->toArray(),
            'mapa_conciliacao' => MapaConciliacao::query()->orderBy('id')->get()->toArray(),
            'bank_statements' => BankStatement::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
