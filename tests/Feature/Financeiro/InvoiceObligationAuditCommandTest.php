<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InvoiceObligationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00'));

        foreach (['material', 'mensalidade', 'inscricao', 'ajuste'] as $type) {
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

    public function test_command_has_no_findings_for_valid_manual_invoice(): void
    {
        $invoice = $this->createInvoice([
            'tipo' => 'material',
            'valor_total' => 25,
            'valor_pago' => 0,
            'valor_em_aberto' => 25,
            'estado_pagamento' => 'pendente',
            'origem_tipo' => 'manual',
        ]);
        $this->createItem($invoice, ['total_linha' => 25, 'valor_unitario' => 25]);

        $payload = $this->jsonAuditPayload();

        $this->assertSame(1, $payload['summary']['total_invoices_scanned']);
        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame([], $payload['findings']);
    }

    public function test_detects_totals_and_financial_state_inconsistencies(): void
    {
        $withoutItems = $this->createInvoice(['valor_total' => 30, 'valor_em_aberto' => 30]);

        $totalDiffers = $this->createInvoice(['valor_total' => 40, 'valor_em_aberto' => 40]);
        $this->createItem($totalDiffers, ['valor_unitario' => 15, 'total_linha' => 15]);

        $lineDiffers = $this->createInvoice(['valor_total' => 50, 'valor_em_aberto' => 50]);
        $this->createItem($lineDiffers, ['valor_unitario' => 10, 'quantidade' => 2, 'total_linha' => 50]);

        $openInconsistent = $this->createInvoice(['valor_total' => 20, 'valor_pago' => 5, 'valor_em_aberto' => 20]);
        $this->createItem($openInconsistent, ['valor_unitario' => 20, 'total_linha' => 20]);

        $paidWithOpen = $this->createInvoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 5, 'estado_pagamento' => 'pago', 'data_pagamento' => '2026-07-14']);
        $this->createItem($paidWithOpen, ['valor_unitario' => 20, 'total_linha' => 20]);

        $paidExceeds = $this->createInvoice(['valor_total' => 20, 'valor_pago' => 25, 'valor_em_aberto' => -5, 'estado_pagamento' => 'pago', 'data_pagamento' => '2026-07-14']);
        $this->createItem($paidExceeds, ['valor_unitario' => 20, 'total_linha' => 20]);

        $paidMissingDate = $this->createInvoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago', 'data_pagamento' => null]);
        $this->createItem($paidMissingDate, ['valor_unitario' => 20, 'total_linha' => 20]);

        $payload = $this->jsonAuditPayload();

        $this->assertFindingForInvoice($payload, 'invoice_without_items', $withoutItems->id, 'critical');
        $this->assertFindingForInvoice($payload, 'invoice_total_differs_from_items_sum', $totalDiffers->id, 'critical');
        $this->assertFindingForInvoice($payload, 'invoice_item_line_total_differs', $lineDiffers->id, 'critical');
        $this->assertFindingForInvoice($payload, 'open_amount_inconsistent', $openInconsistent->id, 'critical');
        $this->assertFindingForInvoice($payload, 'paid_invoice_with_open_amount', $paidWithOpen->id, 'critical');
        $this->assertFindingForInvoice($payload, 'paid_amount_exceeds_total', $paidExceeds->id, 'critical');
        $this->assertFindingForInvoice($payload, 'payment_date_missing_for_paid_invoice', $paidMissingDate->id, 'warning');
    }

    public function test_detects_external_fiscal_document_and_pending_request_inconsistencies(): void
    {
        $mismatch = $this->paidInvoice(['numero_recibo' => 'REC-1']);
        FiscalDocumentRequest::query()->create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $mismatch->id,
            'user_id' => $mismatch->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 20,
            'external_document_number' => 'REC-2',
            'issued_at' => now(),
        ]);

        $missingReceipt = $this->paidInvoice(['numero_recibo' => null]);
        FiscalDocumentRequest::query()->create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $missingReceipt->id,
            'user_id' => $missingReceipt->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'amount' => 20,
            'external_document_number' => 'REC-3',
            'issued_at' => now(),
        ]);

        $pendingForUnpaid = $this->createInvoice(['valor_total' => 20, 'valor_em_aberto' => 20, 'estado_pagamento' => 'pendente']);
        $this->createItem($pendingForUnpaid, ['valor_unitario' => 20, 'total_linha' => 20]);
        FiscalDocumentRequest::query()->create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $pendingForUnpaid->id,
            'user_id' => $pendingForUnpaid->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'amount' => 20,
        ]);

        $payload = $this->jsonAuditPayload();

        $this->assertFindingForInvoice($payload, 'receipt_number_differs_from_external_document', $mismatch->id, 'critical');
        $this->assertFindingForInvoice($payload, 'external_fiscal_document_without_receipt_number', $missingReceipt->id, 'critical');
        $this->assertFindingForInvoice($payload, 'fiscal_request_pending_for_unpaid_invoice', $pendingForUnpaid->id, 'warning');
    }

    public function test_detects_monthly_origin_and_duplicate_findings(): void
    {
        $user = User::factory()->create();
        $first = $this->createInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'mes' => '2026-08',
            'valor_total' => 40,
            'valor_em_aberto' => 40,
            'origem_tipo' => 'manual',
            'origem_id' => null,
        ]);
        $this->createItem($first, ['valor_unitario' => 40, 'total_linha' => 40]);

        $second = $this->createInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'mes' => '2026-08',
            'valor_total' => 40,
            'valor_em_aberto' => 40,
            'origem_tipo' => 'monthly_fee',
            'origem_id' => (string) Str::uuid(),
        ]);
        $this->createItem($second, ['valor_unitario' => 40, 'total_linha' => 40]);

        $withoutMonth = $this->createInvoice([
            'tipo' => 'mensalidade',
            'mes' => null,
            'valor_total' => 40,
            'valor_em_aberto' => 40,
            'origem_tipo' => 'monthly_fee',
            'origem_id' => (string) Str::uuid(),
        ]);
        $this->createItem($withoutMonth, ['valor_unitario' => 40, 'total_linha' => 40]);

        $payload = $this->jsonAuditPayload();

        $this->assertFindingForInvoice($payload, 'monthly_invoice_without_canonical_origin', $first->id, 'warning');
        $this->assertFindingForInvoice($payload, 'manual_invoice_with_monthly_type', $first->id, 'warning');
        $this->assertFindingForInvoice($payload, 'duplicate_active_monthly_invoice', $first->id, 'critical');
        $this->assertFindingForInvoice($payload, 'monthly_invoice_without_month', $withoutMonth->id, 'critical');
    }

    public function test_filters_json_report_path_exit_codes_include_cancelled_and_read_only_contract(): void
    {
        $product = Product::factory()->create(['stock' => 10]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $valid = $this->createInvoice([
            'user_id' => $userA->id,
            'tipo' => 'material',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-15',
            'valor_total' => 20,
            'valor_em_aberto' => 20,
            'origem_tipo' => 'manual',
        ]);
        $this->createItem($valid, ['produto_id' => $product->id, 'valor_unitario' => 20, 'total_linha' => 20]);

        $warning = $this->createInvoice([
            'user_id' => $userB->id,
            'tipo' => 'material',
            'data_emissao' => '2026-08-01',
            'data_vencimento' => '2026-08-15',
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => null,
            'origem_tipo' => 'manual',
        ]);
        $this->createItem($warning, ['valor_unitario' => 20, 'total_linha' => 20]);

        $critical = $this->createInvoice([
            'user_id' => $userB->id,
            'tipo' => 'material',
            'data_emissao' => '2026-09-01',
            'data_vencimento' => '2026-09-15',
            'valor_total' => 30,
            'valor_em_aberto' => 30,
            'origem_tipo' => 'manual',
        ]);

        $cancelled = $this->createInvoice([
            'user_id' => $userB->id,
            'tipo' => 'material',
            'estado_pagamento' => 'cancelado',
            'valor_total' => 20,
            'valor_em_aberto' => 20,
            'origem_tipo' => 'manual',
        ]);
        $this->createItem($cancelled, ['valor_unitario' => 20, 'total_linha' => 20]);

        $payment = Payment::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $warning->user_id,
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'payment_date' => '2026-08-02',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        PaymentAllocation::query()->create([
            'id' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'invoice_id' => $warning->id,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);
        FiscalDocumentRequest::query()->create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $warning->id,
            'user_id' => $warning->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'amount' => 20,
        ]);

        $before = $this->snapshot($product->id);

        $this->assertSame(1, $this->jsonAuditPayload(['--invoice' => $valid->id])['summary']['total_invoices_scanned']);
        $this->assertSame(1, $this->jsonAuditPayload(['--user' => $userA->id])['summary']['total_invoices_scanned']);
        $this->assertSame(4, $this->jsonAuditPayload(['--type' => 'material', '--include-cancelled' => true])['summary']['total_invoices_scanned']);
        $this->assertSame(3, $this->jsonAuditPayload(['--only-open' => true, '--include-cancelled' => true])['summary']['total_invoices_scanned']);
        $this->assertSame(2, $this->jsonAuditPayload(['--from' => '2026-08-01', '--to' => '2026-09-30'])['summary']['total_invoices_scanned']);

        $this->assertSame(3, $this->jsonAuditPayload()['summary']['total_invoices_scanned']);
        $this->assertSame(4, $this->jsonAuditPayload(['--include-cancelled' => true])['summary']['total_invoices_scanned']);

        $relativePath = 'storage/app/audits/invoice-obligation-audit-test.json';
        $absolutePath = base_path($relativePath);
        $exitCode = Artisan::call('finance:audit-invoices', [
            '--report-path' => $relativePath,
        ]);
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);

        $this->assertSame(1, Artisan::call('finance:audit-invoices', ['--fail-on-critical' => true]));
        $this->assertSame(1, Artisan::call('finance:audit-invoices', ['--fail-on-warning' => true]));

        $this->assertSame($before, $this->snapshot($product->id));
        $this->assertDatabaseHas('invoices', ['id' => $critical->id, 'valor_total' => '30.00']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonAuditPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-invoices', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertFindingForInvoice(array $payload, string $code, string $invoiceId, string $severity): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['invoice_id'] === $invoiceId
                && $finding['severity'] === $severity,
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s for invoice %s.', $code, $invoiceId));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createInvoice(array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-07-01',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-15',
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
    private function createItem(Invoice $invoice, array $overrides = []): InvoiceItem
    {
        return InvoiceItem::query()->create(array_merge([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha auditoria',
            'quantidade' => 1,
            'valor_unitario' => 20,
            'imposto_percentual' => 0,
            'total_linha' => 20,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function paidInvoice(array $overrides = []): Invoice
    {
        $invoice = $this->createInvoice(array_merge([
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-14',
            'numero_recibo' => 'REC-OK',
        ], $overrides));
        $this->createItem($invoice, ['valor_unitario' => 20, 'total_linha' => 20]);

        return $invoice;
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(string $productId): array
    {
        return [
            'invoice_count' => Invoice::query()->count(),
            'invoice_item_count' => InvoiceItem::query()->count(),
            'payment_count' => Payment::query()->count(),
            'payment_allocation_count' => PaymentAllocation::query()->count(),
            'fiscal_document_request_count' => FiscalDocumentRequest::withTrashed()->count(),
            'product_stock' => Product::query()->whereKey($productId)->value('stock'),
            'invoices' => Invoice::query()
                ->orderBy('id')
                ->get(['id', 'valor_total', 'valor_pago', 'valor_em_aberto', 'estado_pagamento', 'updated_at'])
                ->toArray(),
        ];
    }
}
