<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosPessoais;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderAdapter;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FiscalDocumentRequestProcessingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fiscal.operation_mode' => 'provider_api']);
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));
        FakeFiscalProviderAdapter::$result = FiscalDocumentProviderResult::success(
            externalDocumentNumber: 'WT-REC-1',
            externalDocumentId: 'wt-doc-1',
            issuedAt: '2026-07-17 12:00:00',
            rawResponse: ['ok' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_without_apply_is_dry_run_and_does_not_change_database(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $payload = $this->jsonPayload(['--fiscal-request' => [$request->id]]);

        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['apply']);
        $this->assertSame(1, $payload['summary']['ready_count']);
        $this->assertSame('dry_run_ready', $payload['items'][0]['action']);
        $this->assertFalse($payload['items'][0]['processed']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_explicit_dry_run_does_not_change_database(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $payload = $this->jsonPayload([
            '--fiscal-request' => [$request->id],
            '--dry-run' => true,
        ]);

        $this->assertTrue($payload['dry_run']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_export_payload_path_writes_ready_payloads_without_database_changes(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/fiscal-processing-export.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload([
            '--fiscal-request' => [$request->id],
            '--export-payload-path' => $relativePath,
        ]);

        $this->assertSame(1, $payload['summary']['exported_count']);
        $this->assertSame($absolutePath, $payload['export_path']);
        $this->assertFileExists($absolutePath);
        $export = json_decode((string) file_get_contents($absolutePath), true);
        $this->assertSame($request->id, $export['payloads'][0]['references']['fiscal_request_id']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_apply_without_confirm_external_issue_aborts_without_changes(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_confirm_external_issue', $payload['items'][0]['blocked_reasons']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_with_blocked_candidate_aborts_without_changes(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['customer_tax_number' => null], withMemberFiscalData: false);
        $before = $this->snapshot();

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('missing_customer_fiscal_identity', $payload['items'][0]['blocked_reasons']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_with_missing_provider_adapter_aborts_without_changes(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('provider_adapter_not_configured', $payload['items'][0]['blocked_reasons']);
        $this->assertSame('blocked_provider_adapter_not_configured', $payload['items'][0]['action']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_is_fail_closed_in_manual_wintouch_mode_even_with_an_adapter_registered(): void
    {
        config(['fiscal.operation_mode' => 'manual_wintouch']);
        $this->app->instance(FiscalDocumentProviderAdapter::class, new FakeFiscalProviderAdapter());
        [, , , $request] = $this->paidPendingChain();
        $before = $this->snapshot();

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('manual_wintouch', $payload['operation_mode']);
        $this->assertContains('automated_provider_mode_disabled', $payload['items'][0]['blocked_reasons']);
        $this->assertSame('blocked_automated_provider_mode_disabled', $payload['items'][0]['action']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_with_fake_provider_success_marks_request_and_invoice_issued(): void
    {
        $this->app->instance(FiscalDocumentProviderAdapter::class, new FakeFiscalProviderAdapter());
        [$invoice, , , $request] = $this->paidPendingChain();

        $payload = $this->jsonPayload([
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
        ]);

        $this->assertFalse($payload['dry_run']);
        $this->assertSame(1, $payload['summary']['processed_count']);
        $this->assertSame('processed', $payload['items'][0]['action']);
        $this->assertSame('WT-REC-1', $payload['items'][0]['external_document_number']);

        $request->refresh();
        $invoice->refresh();

        $this->assertSame(FiscalDocumentRequest::STATUS_ISSUED, $request->status);
        $this->assertSame('WT-REC-1', $request->external_document_number);
        $this->assertSame('wt-doc-1', $request->external_document_id);
        $this->assertSame('WT-REC-1', $invoice->numero_recibo);
        $this->assertSame('2026-07-17', $invoice->recibo_emitido_em?->toDateString());
    }

    public function test_provider_failure_does_not_mark_request_as_issued(): void
    {
        FakeFiscalProviderAdapter::$result = FiscalDocumentProviderResult::failure('provider_error', ['ok' => false]);
        $this->app->instance(FiscalDocumentProviderAdapter::class, new FakeFiscalProviderAdapter());
        [$invoice, , , $request] = $this->paidPendingChain();

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('provider_failed', $payload['items'][0]['action']);
        $this->assertSame('provider_error', $payload['items'][0]['error']);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->refresh()->status);
        $this->assertNull($request->external_document_number);
        $this->assertNull($invoice->refresh()->numero_recibo);
    }

    public function test_already_issued_request_is_skipped_idempotently(): void
    {
        [$invoice, , , $request] = $this->paidPendingChain();
        $request->forceFill([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'WT-OLD',
            'external_document_id' => 'old-id',
            'issued_at' => '2026-07-16 10:00:00',
            'handled_at' => '2026-07-16 10:00:00',
        ])->save();
        $invoice->forceFill([
            'numero_recibo' => 'WT-OLD',
            'recibo_emitido_em' => '2026-07-16',
        ])->save();

        $payload = $this->jsonPayload([
            '--fiscal-request' => [$request->id],
            '--apply' => true,
            '--confirm-external-issue' => true,
        ]);

        $this->assertSame(1, $payload['summary']['skipped_count']);
        $this->assertSame('skipped_already_issued', $payload['items'][0]['action']);
        $this->assertSame('WT-OLD', $payload['items'][0]['external_document_number']);
    }

    public function test_filters_by_fiscal_request_invoice_payment_and_provider(): void
    {
        [$invoiceA, $paymentA, , $requestA] = $this->paidPendingChain(amount: 20);
        [, , , $requestB] = $this->paidPendingChain(amount: 30);

        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--fiscal-request' => [$requestA->id]])['items'])->pluck('fiscal_request_id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--invoice' => [$invoiceA->id]])['items'])->pluck('fiscal_request_id')->all());
        $this->assertSame([$requestA->id], collect($this->jsonPayload(['--payment' => [$paymentA->id]])['items'])->pluck('fiscal_request_id')->all());
        $this->assertEqualsCanonicalizing([$requestA->id, $requestB->id], collect($this->jsonPayload(['--provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH])['items'])->pluck('fiscal_request_id')->all());
    }

    public function test_fail_on_blocked_returns_exit_one_for_blocked_items(): void
    {
        [, , , $request] = $this->paidPendingChain(requestOverrides: ['customer_tax_number' => null], withMemberFiscalData: false);

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--fail-on-blocked' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, , , $request] = $this->paidPendingChain();
        $relativePath = 'storage/app/testing/fiscal-processing-report.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--fiscal-request' => [$request->id]]);

        $this->assertSame('a6-4-fiscal-document-request-processing-v1', $payload['version']);
        $this->assertArrayHasKey('summary', $payload);

        $exitCode = Artisan::call('finance:process-fiscal-document-requests', [
            '--fiscal-request' => [$request->id],
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame($request->id, json_decode((string) file_get_contents($absolutePath), true)['items'][0]['fiscal_request_id']);
        @unlink($absolutePath);
    }

    public function test_dry_run_is_read_only(): void
    {
        $this->paidPendingChain();
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
        $exitCode = Artisan::call('finance:process-fiscal-document-requests', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function member(bool $withFiscalData = true): User
    {
        $user = User::factory()->create([
            'name' => 'Processing Member',
            'email' => 'processing-' . (string) str()->uuid() . '@example.test',
        ]);

        if ($withFiscalData) {
            DadosPessoais::query()->create([
                'user_id' => $user->id,
                'nome_completo' => 'Processing Member',
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
     * @param array<string,mixed> $requestOverrides
     * @return array{0:Invoice,1:Payment,2:PaymentAllocation,3:FiscalDocumentRequest}
     */
    private function paidPendingChain(float $amount = 20, array $requestOverrides = [], bool $withMemberFiscalData = true): array
    {
        $user = $this->member($withMemberFiscalData);
        $invoice = $this->invoice($user, amount: $amount);
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
        $request = $this->pendingRequest($invoice, $requestOverrides, $amount);

        return [$invoice, $payment, $allocation, $request];
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
            'customer_name' => 'Processing Member',
            'customer_tax_number' => '123456789',
            'customer_email' => 'processing@example.test',
            'customer_address' => 'Rua Fiscal 1',
            'created_at' => now()->subDays(10),
            'last_error' => null,
        ], $overrides);

        $existing = FiscalDocumentRequest::withTrashed()
            ->where('invoice_id', $invoice->id)
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
        ];
    }
}

final class FakeFiscalProviderAdapter implements FiscalDocumentProviderAdapter
{
    public static FiscalDocumentProviderResult $result;

    public function provider(): string
    {
        return FiscalDocumentRequest::PROVIDER_WINTOUCH;
    }

    public function issueReceipt(array $payload): FiscalDocumentProviderResult
    {
        return self::$result;
    }
}
