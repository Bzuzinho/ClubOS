<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SupplierPurchaseStockAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_purchase_with_matching_entry_is_clean(): void
    {
        [$purchase, $product] = $this->purchaseWithItem(quantity: 3, unitCost: 7.5, productStock: 3);
        $this->movement($product, 'entry', 3, [
            'unit_cost' => 7.5,
            'reference_type' => 'supplier_purchase',
            'reference_id' => $purchase->id,
        ]);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_entry_clean', 'info', $purchase->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_active_purchase_without_entry_generates_missing_stock_entry(): void
    {
        [$purchase] = $this->purchaseWithItem(quantity: 2);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_missing_stock_entry', 'critical', $purchase->id);
        $this->assertSame(1, $payload['summary']['missing_stock_entry_count']);
    }

    public function test_duplicate_entries_for_same_purchase_are_reported(): void
    {
        [$purchase, $product] = $this->purchaseWithItem(quantity: 2, productStock: 4);
        $this->movement($product, 'entry', 2, ['reference_type' => 'supplier_purchase', 'reference_id' => $purchase->id]);
        $this->movement($product, 'entry', 2, ['reference_type' => 'supplier_purchase', 'reference_id' => $purchase->id]);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_duplicate_stock_entry', 'warning', $purchase->id);
        $this->assertSame(1, $payload['summary']['duplicate_stock_entry_count']);
    }

    public function test_quantity_mismatch_is_reported(): void
    {
        [$purchase, $product] = $this->purchaseWithItem(quantity: 5, productStock: 3);
        $this->movement($product, 'entry', 3, ['reference_type' => 'supplier_purchase', 'reference_id' => $purchase->id]);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_quantity_mismatch', 'critical', $purchase->id);
        $this->assertSame(1, $payload['summary']['quantity_mismatch_count']);
    }

    public function test_unit_cost_mismatch_is_reported(): void
    {
        [$purchase, $product] = $this->purchaseWithItem(quantity: 1, unitCost: 10, productStock: 1);
        $this->movement($product, 'entry', 1, [
            'unit_cost' => 11,
            'reference_type' => 'supplier_purchase',
            'reference_id' => $purchase->id,
        ]);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_unit_cost_mismatch', 'warning', $purchase->id);
        $this->assertSame(1, $payload['summary']['unit_cost_mismatch_count']);
    }

    public function test_deleted_purchase_entry_without_reversal_is_reported(): void
    {
        $product = $this->product(['stock' => 4]);
        $deletedPurchaseId = (string) str()->uuid();
        $this->movement($product, 'entry', 4, [
            'reference_type' => 'supplier_purchase',
            'reference_id' => $deletedPurchaseId,
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'supplier_purchase_deleted_without_reversal', 'warning');
        $this->assertSame(1, $payload['summary']['deleted_without_reversal_count']);
    }

    public function test_purchase_item_without_product_is_reported(): void
    {
        [$purchase] = $this->purchaseWithItem(articleId: null, quantity: 1);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_invalid_product', 'critical', $purchase->id);
        $this->assertSame(1, $payload['summary']['invalid_product_count']);
    }

    public function test_zero_quantity_purchase_item_is_reported(): void
    {
        [$purchase] = $this->purchaseWithItem(quantity: 0);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);

        $this->assertFinding($payload, 'supplier_purchase_invalid_quantity', 'warning', $purchase->id);
        $this->assertSame(1, $payload['summary']['invalid_quantity_count']);
    }

    public function test_empty_uuid_source_reference_is_reported_without_crashing(): void
    {
        $product = $this->product(['stock' => 1]);

        DB::table('stock_movements')->insert([
            'id' => (string) str()->uuid(),
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'quantity' => 1,
            'unit_cost' => 1,
            'reference_type' => 'supplier_purchase',
            'reference_id' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'supplier_purchase_invalid_source_reference', 'warning');
        $this->assertSame(1, $payload['summary']['invalid_source_reference_count']);
    }

    public function test_legacy_unlinked_entry_is_info_and_only_actionable_removes_it(): void
    {
        $product = $this->product(['stock' => 2]);
        $this->movement($product, 'entry', 2, [
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Entrada legacy de compra a fornecedor',
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);
        $actionablePayload = $this->jsonPayload(['--material' => $product->id, '--only-actionable' => true]);

        $this->assertFinding($payload, 'supplier_purchase_legacy_unlinked_entry', 'info');
        $this->assertNotContains('supplier_purchase_legacy_unlinked_entry', collect($actionablePayload['findings'])->pluck('code')->all());
    }

    public function test_fail_flags_json_report_path_and_read_only_snapshot(): void
    {
        [$purchase] = $this->purchaseWithItem(quantity: 2);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/supplier-purchase-stock-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--purchase' => $purchase->id]);
        $exitCode = Artisan::call('inventory:audit-supplier-purchase-stock', [
            '--purchase' => $purchase->id,
            '--fail-on-warning' => true,
        ]);
        $reportExitCode = Artisan::call('inventory:audit-supplier-purchase-stock', [
            '--purchase' => $purchase->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame('b3-supplier-purchase-stock-audit-v1', $payload['version']);
        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $reportExitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b3-supplier-purchase-stock-audit-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        $this->assertSame($before, $this->snapshot());
        @unlink($absolutePath);
    }

    public function test_existing_stock_audits_remain_without_actionable_findings_for_clean_purchase(): void
    {
        [$purchase, $product] = $this->purchaseWithItem(quantity: 3, productStock: 3);
        $this->movement($product, 'entry', 3, ['reference_type' => 'supplier_purchase', 'reference_id' => $purchase->id]);

        Artisan::call('inventory:audit-stock-integrity', ['--material' => $product->id, '--json' => true]);
        $integrity = json_decode(trim(Artisan::output()), true);

        Artisan::call('inventory:audit-stock-source-of-truth', ['--json' => true]);
        $sourceOfTruth = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $integrity['summary']['actionable_count']);
        $this->assertSame(0, $sourceOfTruth['summary']['actionable_count']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:audit-supplier-purchase-stock', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $purchaseId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code
            && ($purchaseId === null || ($finding['purchase_id'] ?? null) === $purchaseId));

        $this->assertIsArray($finding, 'Expected finding '.$code);
        $this->assertSame($severity, $finding['severity']);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Material '.(string) str()->uuid(),
            'categoria' => 'Equipamento',
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
     * @return array{0:SupplierPurchase,1:Product,2:SupplierPurchaseItem}
     */
    private function purchaseWithItem(?string $articleId = 'create-product', int $quantity = 1, float $unitCost = 5.0, int $productStock = 0): array
    {
        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor B3 '.(string) str()->uuid(),
            'nif' => '509999991',
            'email' => 'supplier-b3@example.test',
            'telefone' => '912345679',
            'categoria' => 'Equipamento',
            'ativo' => true,
        ]);
        $product = $articleId === 'create-product' ? $this->product(['stock' => $productStock]) : null;
        $resolvedArticleId = $articleId === 'create-product' ? $product?->id : $articleId;

        $purchase = SupplierPurchase::query()->create([
            'supplier_id' => $supplier->id,
            'supplier_name_snapshot' => $supplier->nome,
            'invoice_reference' => 'B3-'.(string) str()->uuid(),
            'invoice_date' => now()->toDateString(),
            'total_amount' => $quantity * $unitCost,
        ]);

        $item = SupplierPurchaseItem::query()->create([
            'supplier_purchase_id' => $purchase->id,
            'article_id' => $resolvedArticleId,
            'article_name_snapshot' => $product?->nome ?? 'Produto ausente',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $quantity * $unitCost,
        ]);

        return [$purchase, $product ?? new Product(['id' => $resolvedArticleId]), $item];
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function movement(Product $product, string $type, int $quantity, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => 5,
            'reference_type' => 'test_source',
            'reference_id' => (string) str()->uuid(),
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
            'supplier_purchases' => SupplierPurchase::query()->orderBy('id')->get()->toArray(),
            'supplier_purchase_items' => SupplierPurchaseItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
