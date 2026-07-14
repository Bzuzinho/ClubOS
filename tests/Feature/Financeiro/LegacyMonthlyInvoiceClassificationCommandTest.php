<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyMonthlyInvoiceClassificationCommandTest extends TestCase
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

    public function test_default_mode_is_dry_run_and_does_not_change_safe_invoice(): void
    {
        $invoice = $this->monthlyInvoice();

        $payload = $this->jsonPayload();

        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame(1, $payload['summary']['safe_to_classify']);
        $this->assertSame('manual', $invoice->fresh()->origem_tipo);
    }

    public function test_dry_run_keeps_snapshot_unchanged(): void
    {
        $product = Product::factory()->create(['stock' => 7]);
        $invoice = $this->monthlyInvoice();
        $invoice->items()->firstOrFail()->forceFill(['produto_id' => $product->id])->save();

        $before = $this->snapshot($invoice, $product);

        $payload = $this->jsonPayload(['--dry-run' => true]);

        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame($before, $this->snapshot($invoice, $product));
    }

    public function test_apply_classifies_safe_invoice_without_changing_financial_values_or_items(): void
    {
        $product = Product::factory()->create(['stock' => 12]);
        $invoice = $this->monthlyInvoice(['observacoes' => 'Nota anterior']);
        $invoice->items()->firstOrFail()->forceFill(['produto_id' => $product->id])->save();

        $before = $this->snapshot($invoice, $product);
        $payload = $this->jsonPayload(['--apply' => true]);
        $fresh = $invoice->fresh('items');

        $this->assertSame('apply', $payload['mode']);
        $this->assertSame(1, $payload['summary']['applied_count']);
        $this->assertSame('monthly_fee_legacy', $fresh->origem_tipo);
        $this->assertNull($fresh->origem_id);
        $this->assertStringContainsString('[A3.4] Classificada como mensalidade legacy canonica em 2026-07-14', (string) $fresh->observacoes);
        $this->assertSame($before['valor_total'], (string) $fresh->valor_total);
        $this->assertSame($before['valor_pago'], (string) $fresh->valor_pago);
        $this->assertSame($before['valor_em_aberto'], (string) $fresh->valor_em_aberto);
        $this->assertSame($before['estado_pagamento'], $fresh->estado_pagamento);
        $this->assertSame($before['data_pagamento'], $fresh->data_pagamento?->toDateString());
        $this->assertSame($before['items'], $fresh->items->toArray());
        $this->assertSame($before['product_stock'], Product::query()->whereKey($product->id)->value('stock'));
        $this->assertSame(0, FiscalDocumentRequest::withTrashed()->count());
        $this->assertSame(0, PaymentAllocation::withTrashed()->count());
    }

    public function test_apply_is_idempotent_and_counts_already_classified(): void
    {
        $invoice = $this->monthlyInvoice();

        $first = $this->jsonPayload(['--apply' => true]);
        $afterFirst = (string) $invoice->fresh()->observacoes;
        $second = $this->jsonPayload(['--apply' => true]);

        $this->assertSame(1, $first['summary']['applied_count']);
        $this->assertSame(1, $second['summary']['already_classified']);
        $this->assertSame(0, $second['summary']['applied_count']);
        $this->assertSame($afterFirst, (string) $invoice->fresh()->observacoes);
        $this->assertSame(1, substr_count((string) $invoice->fresh()->observacoes, '[A3.4] Classificada'));
    }

    public function test_protected_paid_and_allocation_invoices_are_not_changed(): void
    {
        $paid = $this->monthlyInvoice([
            'estado_pagamento' => 'pago',
            'valor_pago' => 25,
            'valor_em_aberto' => 0,
            'data_pagamento' => '2026-07-14',
        ]);
        $withAllocation = $this->monthlyInvoice(['mes' => '2026-08']);
        $payment = Payment::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $withAllocation->user_id,
            'amount' => 25,
            'allocated_amount' => 25,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-14',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        PaymentAllocation::query()->create([
            'id' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'invoice_id' => $withAllocation->id,
            'amount' => 25,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $payload = $this->jsonPayload(['--apply' => true, '--include-protected' => true]);

        $this->assertSame(2, $payload['summary']['protected_legacy_monthly']);
        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame('manual', $paid->fresh()->origem_tipo);
        $this->assertSame('manual', $withAllocation->fresh()->origem_tipo);
    }

    public function test_pending_fiscal_request_for_unpaid_invoice_is_unsafe_and_not_changed(): void
    {
        $invoice = $this->monthlyInvoice([
            'id' => '6e8c6845-84e8-4f52-92bb-747ca3a7a70e',
            'estado_pagamento' => 'vencido',
        ]);
        FiscalDocumentRequest::query()->create([
            'id' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'amount' => 25,
        ]);

        $payload = $this->jsonPayload(['--apply' => true]);
        $item = $payload['items'][0];

        $this->assertSame('unsafe_needs_manual_review', $item['classification']);
        $this->assertSame('review_pending_fiscal_request_before_deleting_or_reopening', $item['recommendation']);
        $this->assertContains('fiscal_request_pending_for_unpaid_invoice', $item['findings']);
        $this->assertFalse($item['applied']);
        $this->assertSame('manual', $invoice->fresh()->origem_tipo);
    }

    public function test_divergent_total_invalid_month_monthly_fee_origin_and_already_classified_behaviour(): void
    {
        $divergent = $this->monthlyInvoice(['valor_total' => 30, 'valor_em_aberto' => 30]);
        $divergent->items()->delete();
        $this->createItem($divergent, ['valor_unitario' => 20, 'total_linha' => 20]);
        $invalidMonth = $this->monthlyInvoice(['mes' => '2026/08']);
        $impossibleMonth = $this->monthlyInvoice(['mes' => '2026-99']);
        $canonical = $this->monthlyInvoice(['mes' => '2026-09', 'origem_tipo' => 'monthly_fee']);
        $already = $this->monthlyInvoice(['mes' => '2026-10', 'origem_tipo' => 'monthly_fee_legacy']);

        $payload = $this->jsonPayload(['--apply' => true]);

        $this->assertFinding($payload, $divergent->id, 'unsafe_needs_manual_review');
        $this->assertFinding($payload, $invalidMonth->id, 'unsafe_needs_manual_review');
        $this->assertFinding($payload, $impossibleMonth->id, 'unsafe_needs_manual_review');
        $this->assertFinding($payload, $already->id, 'already_classified');
        $this->assertFalse(collect($payload['items'])->contains(fn (array $item): bool => $item['invoice_id'] === $canonical->id));
        $this->assertSame('manual', $divergent->fresh()->origem_tipo);
        $this->assertSame('manual', $invalidMonth->fresh()->origem_tipo);
        $this->assertSame('manual', $impossibleMonth->fresh()->origem_tipo);
    }

    public function test_filters_json_report_path_and_fail_on_unsafe(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $aJune = $this->monthlyInvoice(['user_id' => $userA->id, 'mes' => '2026-06']);
        $aJuly = $this->monthlyInvoice(['user_id' => $userA->id, 'mes' => '2026-07']);
        $bAugust = $this->monthlyInvoice(['user_id' => $userB->id, 'mes' => '2026-08']);
        $unsafe = $this->monthlyInvoice(['user_id' => $userB->id, 'mes' => '2026-09', 'valor_total' => 50, 'valor_em_aberto' => 50]);
        $unsafe->items()->delete();
        $this->createItem($unsafe, ['valor_unitario' => 25, 'total_linha' => 25]);

        $this->assertSame([$aJune->id], collect($this->jsonPayload(['--invoice' => $aJune->id])['items'])->pluck('invoice_id')->all());
        $this->assertSame(2, $this->jsonPayload(['--user' => $userA->id])['summary']['total_candidates']);
        $this->assertSame([$aJuly->id, $bAugust->id], collect($this->jsonPayload(['--from-month' => '2026-07', '--to-month' => '2026-08'])['items'])->pluck('invoice_id')->all());

        $relativePath = 'storage/app/audits/legacy-monthly-classification-test.json';
        $absolutePath = base_path($relativePath);
        $exitCode = Artisan::call('finance:classify-legacy-monthly-invoices', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);

        $this->assertSame(1, Artisan::call('finance:classify-legacy-monthly-invoices', ['--fail-on-unsafe' => true]));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:classify-legacy-monthly-invoices', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $invoiceId, string $classification): void
    {
        $item = collect($payload['items'])->first(
            fn (array $item): bool => $item['invoice_id'] === $invoiceId
                && $item['classification'] === $classification,
        );

        $this->assertIsArray($item, sprintf('Missing %s for invoice %s.', $classification, $invoiceId));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function monthlyInvoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-06-01',
            'data_emissao' => '2026-06-01',
            'data_vencimento' => '2026-06-15',
            'mes' => '2026-06',
            'valor_total' => 25,
            'valor_pago' => 0,
            'valor_em_aberto' => 25,
            'estado_pagamento' => 'vencido',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
            'origem_id' => null,
        ], $overrides));

        $this->createItem($invoice);

        return $invoice->fresh('items');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createItem(Invoice $invoice, array $overrides = []): InvoiceItem
    {
        return InvoiceItem::query()->create(array_merge([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade legacy',
            'quantidade' => 1,
            'valor_unitario' => 25,
            'imposto_percentual' => 0,
            'total_linha' => 25,
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Invoice $invoice, Product $product): array
    {
        $fresh = $invoice->fresh('items');

        return [
            'origem_tipo' => $fresh->origem_tipo,
            'origem_id' => $fresh->origem_id,
            'valor_total' => (string) $fresh->valor_total,
            'valor_pago' => (string) $fresh->valor_pago,
            'valor_em_aberto' => (string) $fresh->valor_em_aberto,
            'estado_pagamento' => $fresh->estado_pagamento,
            'data_pagamento' => $fresh->data_pagamento?->toDateString(),
            'items' => $fresh->items->toArray(),
            'product_stock' => Product::query()->whereKey($product->id)->value('stock'),
            'fiscal_request_count' => FiscalDocumentRequest::withTrashed()->count(),
            'payment_allocation_count' => PaymentAllocation::withTrashed()->count(),
        ];
    }
}
