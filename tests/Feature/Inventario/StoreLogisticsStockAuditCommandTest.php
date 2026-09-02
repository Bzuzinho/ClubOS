<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StoreLogisticsStockAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_delivered_store_order_with_correct_stock_exit_is_clean(): void
    {
        [$order, $item, $product] = $this->storeOrder('entregue', 2);
        $this->movement($product, 'exit', 2, 'store_order_item', $item->id);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_stock_clean', 'info', false);
        $this->assertSame(0, $payload['summary']['actionable_count']);
    }

    public function test_delivered_store_order_without_exit_generates_missing_physical_exit(): void
    {
        [$order] = $this->storeOrder('entregue', 2);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_missing_physical_exit', 'critical', true);
        $this->assertSame(1, $payload['summary']['missing_physical_exit_count']);
    }

    public function test_pending_store_order_without_checkout_exit_is_also_reported(): void
    {
        [$order] = $this->storeOrder('pendente', 1);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_missing_physical_exit', 'critical', true);
        $this->assertSame(1, $payload['summary']['missing_physical_exit_count']);
    }

    public function test_delivered_store_order_with_two_exits_generates_duplicate_physical_exit(): void
    {
        [$order, $item, $product] = $this->storeOrder('entregue', 2);
        $this->movement($product, 'exit', 2, 'store_order_item', $item->id);
        $this->movement($product, 'exit', 2, 'store_order_item', $item->id);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_duplicate_physical_exit', 'warning', true);
        $this->assertSame(1, $payload['summary']['duplicate_physical_exit_count']);
    }

    public function test_cancelled_store_order_with_unrestored_physical_exit_is_reported(): void
    {
        [$order, $item, $product] = $this->storeOrder('cancelado', 1);
        $this->movement($product, 'exit', 1, 'store_order_item', $item->id);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_cancelled_stock_not_restored', 'warning', true);
        $this->assertSame(1, $payload['summary']['cancelled_stock_unbalanced_count']);
    }

    public function test_cancelled_store_order_with_balanced_return_is_clean(): void
    {
        [$order, $item, $product] = $this->storeOrder('cancelado', 2);
        $this->movement($product, 'exit', 2, 'store_order_item', $item->id);
        $this->movement($product, 'return', 2, 'store_order_item', $item->id);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_order_cancelled_stock_restored', 'info', false);
        $this->assertSame(1, $payload['summary']['cancelled_stock_restored_count']);
        $this->assertSame(0, $payload['summary']['cancelled_stock_unbalanced_count']);
    }

    public function test_invoice_item_with_correct_exit_is_clean(): void
    {
        [$invoice, $item, $product] = $this->manualInvoiceItem(quantity: 1);
        $this->movement($product, 'exit', 1, 'manual_invoice_create', $item->id);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_item_stock_clean', 'info', false);
    }

    public function test_invoice_item_without_required_exit_is_reported(): void
    {
        [$invoice] = $this->manualInvoiceItem(quantity: 1);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_item_missing_physical_exit', 'critical', true);
    }

    public function test_invoice_and_store_item_for_same_sale_cannot_both_have_physical_exit(): void
    {
        [$order, $storeItem, $product] = $this->storeOrder('entregue', 1);
        [$invoice, $invoiceItem] = $this->manualInvoiceItem(quantity: 1, product: $product);
        $order->update(['fatura_id' => $invoice->id]);
        $this->movement($product, 'exit', 1, 'store_order_item', $storeItem->id);
        $this->movement($product, 'exit', 1, 'manual_invoice_create', $invoiceItem->id);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'invoice_store_duplicate_stock_exit', 'warning', true);
        $this->assertSame(1, $payload['summary']['invoice_store_duplicate_exit_count']);
    }

    public function test_logistics_request_and_store_order_for_same_invoice_cannot_duplicate_exit(): void
    {
        [$order, $storeItem, $product] = $this->storeOrder('entregue', 1);
        [$invoice] = $this->manualInvoiceItem(quantity: 1, product: $product);
        $request = $this->logisticsRequest($product, $invoice->id, 1);
        $order->update(['fatura_id' => $invoice->id]);
        $this->movement($product, 'exit', 1, 'store_order_item', $storeItem->id);
        $this->movement($product, 'exit', 1, 'logistics_request', $request->id);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'logistics_store_duplicate_stock_exit', 'warning', true);
        $this->assertSame(1, $payload['summary']['logistics_store_duplicate_exit_count']);
    }

    public function test_quantity_mismatch_is_reported_for_store_and_invoice_items(): void
    {
        [$order, $storeItem, $product] = $this->storeOrder('entregue', 3);
        $this->movement($product, 'exit', 2, 'store_order_item', $storeItem->id);

        [$invoice, $invoiceItem, $invoiceProduct] = $this->manualInvoiceItem(quantity: 3);
        $this->movement($invoiceProduct, 'exit', 2, 'manual_invoice_create', $invoiceItem->id);

        $storePayload = $this->jsonPayload(['--order' => $order->id]);
        $invoicePayload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($storePayload, 'store_order_quantity_mismatch', 'warning', true);
        $this->assertFinding($invoicePayload, 'invoice_item_quantity_mismatch', 'warning', true);
    }

    public function test_invalid_product_and_invalid_quantity_are_reported(): void
    {
        [$invoice, $item] = $this->manualInvoiceItem(quantity: 0, product: null);
        $item->update(['produto_id' => (string) str()->uuid()]);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'store_stock_invalid_product', 'critical', true);
        $this->assertFinding($payload, 'store_stock_invalid_quantity', 'warning', true);
    }

    public function test_invalid_empty_source_reference_is_reported_without_crashing(): void
    {
        $product = $this->product();
        DB::table('stock_movements')->insert([
            'id' => (string) str()->uuid(),
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 1,
            'reference_type' => 'store_order_item',
            'reference_id' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'store_stock_invalid_source_reference', 'warning', true);
        $this->assertSame(1, $payload['summary']['invalid_source_reference_count']);
    }

    public function test_b1_4_store_order_correction_is_recognized_as_clean_history(): void
    {
        [$order, $item, $product] = $this->storeOrder('entregue', 1);
        $this->movement($product, 'exit', 1, 'store_order_item', $item->id, [
            'notes' => 'Baixa de stock por venda/encomenda entregue registada retroativamente',
        ]);

        $payload = $this->jsonPayload(['--order' => $order->id]);

        $this->assertFinding($payload, 'store_stock_legacy_corrected_by_audit', 'info', false);
        $this->assertSame(1, $payload['summary']['legacy_corrected_by_audit_count']);
    }

    public function test_only_actionable_fail_flags_json_report_path_and_read_only_snapshot(): void
    {
        [$order] = $this->storeOrder('entregue', 1);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/store-logistics-stock-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--order' => $order->id]);
        $actionablePayload = $this->jsonPayload(['--order' => $order->id, '--only-actionable' => true]);
        $warningExitCode = Artisan::call('inventory:audit-store-logistics-stock', [
            '--order' => $order->id,
            '--fail-on-warning' => true,
        ]);
        $reportExitCode = Artisan::call('inventory:audit-store-logistics-stock', [
            '--order' => $order->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('h5a-store-logistics-stock-audit-v2', $payload['version']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['interpretation']['cancelled_order_is_balanced_when_exit_and_return_match']);
        $this->assertTrue($payload['interpretation']['no_data_changed']);
        $this->assertNotEmpty($actionablePayload['findings']);
        $this->assertSame(1, $warningExitCode);
        $this->assertSame(0, $reportExitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('h5a-store-logistics-stock-audit-v2', json_decode((string) file_get_contents($absolutePath), true)['version']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_stock_integrity_and_source_of_truth_remain_clean_for_clean_store_flow(): void
    {
        [$order, $item, $product] = $this->storeOrder('entregue', 1, productStock: 9);
        $this->movement($product, 'adjustment', 10, 'ledger_opening_snapshot', null);
        $this->movement($product, 'exit', 1, 'store_order_item', $item->id);

        $payload = $this->jsonPayload(['--order' => $order->id]);
        Artisan::call('inventory:audit-stock-integrity', ['--material' => $product->id, '--only-actionable' => true, '--json' => true]);
        $integrity = json_decode(trim(Artisan::output()), true);
        Artisan::call('inventory:audit-stock-source-of-truth', ['--json' => true]);
        $source = json_decode(trim(Artisan::output()), true);

        $this->assertFinding($payload, 'store_order_stock_clean', 'info', false);
        $this->assertSame(0, $integrity['summary']['actionable_count']);
        $this->assertSame(0, $source['summary']['actionable_count']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:audit-store-logistics-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, bool $actionable): void
    {
        $finding = collect($payload['findings'])->firstWhere('code', $code);

        $this->assertIsArray($finding, 'Expected finding '.$code);
        $this->assertSame($severity, $finding['severity']);
        $this->assertSame($actionable, $finding['actionable']);
    }

    /**
     * @return array{0:LojaEncomenda,1:LojaEncomendaItem,2:Product}
     */
    private function storeOrder(string $status, int $quantity, ?Product $product = null, int $productStock = 0): array
    {
        $user = User::factory()->create();
        $product ??= $this->product(['stock' => $productStock]);
        $order = LojaEncomenda::query()->create([
            'numero' => 'LJ-'.strtoupper((string) str()->random(8)),
            'user_id' => $user->id,
            'estado' => $status,
            'subtotal' => 10 * max(1, $quantity),
            'total' => 10 * max(1, $quantity),
            'origem' => 'portal',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $item = LojaEncomendaItem::query()->create([
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'descricao' => $product->nome,
            'quantidade' => $quantity,
            'preco_unitario' => 10,
            'total_linha' => 10 * max(0, $quantity),
        ]);

        return [$order, $item, $product];
    }

    /**
     * @return array{0:Invoice,1:InvoiceItem,2:Product}
     */
    private function manualInvoiceItem(int $quantity, ?Product $product = null): array
    {
        $user = User::factory()->create();
        $product ??= $this->product();
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => today(),
            'data_emissao' => today(),
            'data_vencimento' => today()->addDays(15),
            'valor_total' => 10 * max(0, $quantity),
            'valor_pago' => 0,
            'valor_em_aberto' => 10 * max(0, $quantity),
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'origem_tipo' => 'manual',
        ]);
        $item = InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => $product?->nome ?? 'Produto removido',
            'quantidade' => $quantity,
            'valor_unitario' => 10,
            'imposto_percentual' => 0,
            'total_linha' => 10 * max(0, $quantity),
            'produto_id' => $product?->id,
        ]);

        return [$invoice, $item, $product ?? $this->product()];
    }

    private function logisticsRequest(Product $product, string $invoiceId, int $quantity): LogisticsRequest
    {
        $request = LogisticsRequest::query()->create([
            'requester_name_snapshot' => 'Loja B6',
            'requester_area' => 'Loja',
            'requester_type' => 'store',
            'status' => 'delivered',
            'delivered_at' => now(),
            'total_amount' => 0,
            'financial_invoice_id' => $invoiceId,
        ]);
        LogisticsRequestItem::query()->create([
            'logistics_request_id' => $request->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'unit_price' => 0,
            'line_total' => 0,
        ]);

        return $request;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Produto B6 '.(string) str()->uuid(),
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
            'ativo' => true,
            'stock' => 0,
            'stock_reservado' => 0,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function movement(Product $product, string $type, int $quantity, string $sourceType, ?string $sourceId, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reference_type' => $sourceType,
            'reference_id' => $sourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'products' => Product::query()->orderBy('id')->get()->toArray(),
            'stock_movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'loja_encomendas' => LojaEncomenda::query()->orderBy('id')->get()->toArray(),
            'loja_encomenda_itens' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'invoice_items' => InvoiceItem::query()->orderBy('id')->get()->toArray(),
            'logistics_requests' => LogisticsRequest::query()->orderBy('id')->get()->toArray(),
            'logistics_request_items' => LogisticsRequestItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
