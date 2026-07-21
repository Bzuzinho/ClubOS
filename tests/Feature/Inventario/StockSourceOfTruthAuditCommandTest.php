<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StockSourceOfTruthAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_coherent_ledger_data_has_no_snapshot_or_source_reference_warnings(): void
    {
        $product = $this->product(['stock' => 4, 'stock_reservado' => 1]);
        $this->movement($product, 'entry', 5);
        $this->movement($product, 'exit', 1);
        $this->movement($product, 'reservation', 1);

        $payload = $this->jsonPayload();
        $codes = collect($payload['findings'])->pluck('code')->all();

        $this->assertNotContains('stock_snapshot_mismatch', $codes);
        $this->assertNotContains('invalid_uuid_source_reference', $codes);
        $this->assertNotContains('negative_available_stock', $codes);
        $this->assertSame('stock_movements', $payload['source_of_truth']['ledger_table']);
    }

    public function test_snapshot_mismatch_and_negative_available_are_actionable(): void
    {
        $product = $this->product(['stock' => 1, 'stock_reservado' => 3]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload();

        $this->assertFinding($payload, 'stock_snapshot_mismatch', 'warning', $product->id);
        $this->assertFinding($payload, 'negative_available_stock', 'warning', $product->id);
        $this->assertSame(1, $payload['summary']['snapshot_mismatch_count']);
        $this->assertSame(1, $payload['summary']['negative_available_count']);
    }

    public function test_invalid_uuid_unknown_type_non_idempotent_and_duplicate_sources_are_reported(): void
    {
        $product = $this->product(['stock' => 2]);
        $sourceId = (string) str()->uuid();

        $this->movement($product, 'entry', 1, ['reference_type' => 'supplier_purchase', 'reference_id' => $sourceId]);
        $this->movement($product, 'entry', 1, ['reference_type' => 'supplier_purchase', 'reference_id' => $sourceId]);
        $this->movement($product, 'manual_note', 1, ['reference_type' => 'manual_import', 'reference_id' => null]);
        $this->movement($product, 'entry', 1, ['reference_type' => 'bad_source', 'reference_id' => '']);

        $payload = $this->jsonPayload();

        $this->assertFinding($payload, 'duplicate_source_stock_movement', 'warning', $product->id);
        $this->assertFinding($payload, 'unknown_stock_movement_type', 'warning', $product->id);
        $this->assertFinding($payload, 'invalid_uuid_source_reference', 'warning', $product->id);
        $this->assertFinding($payload, 'non_idempotent_source_candidate', 'info', $product->id);
    }

    public function test_audit_accepts_b1_resolution_and_store_order_item_sources(): void
    {
        $product = $this->product(['stock' => 9, 'stock_reservado' => 0]);
        $this->movement($product, 'entry', 10, ['reference_type' => 'supplier_purchase', 'reference_id' => (string) str()->uuid()]);
        $this->movement($product, 'exit', 1, ['reference_type' => 'store_order_item', 'reference_id' => (string) str()->uuid()]);
        $this->movement($product, 'reservation', 0, ['reference_type' => 'audit_orphan_resolution', 'reference_id' => null]);

        $payload = $this->jsonPayload();
        $codes = collect($payload['findings'])->pluck('code')->all();

        $this->assertNotContains('invalid_uuid_source_reference', $codes);
        $this->assertNotContains('non_idempotent_source_candidate', $codes);
    }

    public function test_direct_stock_write_candidate_is_reported_by_code_scan(): void
    {
        $baseline = $this->jsonPayload();
        $path = app_path('Services/Inventario/DirectStockWriteCandidateForTest.php');
        File::put($path, "<?php\nfunction stock_write_candidate(\$product) { \$product->stock = 1; }\n");

        try {
            $payload = $this->jsonPayload();
        } finally {
            File::delete($path);
        }

        $this->assertFinding($payload, 'direct_stock_write_candidate', 'warning');
        $this->assertSame(
            $baseline['summary']['direct_stock_write_candidate_count'] + 1,
            $payload['summary']['direct_stock_write_candidate_count'],
        );
    }

    public function test_only_actionable_and_fail_flags_behave_correctly(): void
    {
        $clean = $this->product(['stock' => 1]);
        $this->movement($clean, 'entry', 1);
        $this->movement($clean, 'entry', 1, ['reference_type' => 'source_without_id', 'reference_id' => null]);

        $infoOnly = $this->jsonPayload(['--only-actionable' => true]);
        $this->assertNotContains('non_idempotent_source_candidate', collect($infoOnly['findings'])->pluck('code')->all());

        $warning = $this->product(['stock' => 0]);
        $this->movement($warning, 'entry', 1);

        $exitCode = Artisan::call('inventory:audit-stock-source-of-truth', [
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_report_path_and_read_only_snapshot(): void
    {
        $product = $this->product(['stock' => 1]);
        $this->movement($product, 'entry', 1);
        $before = $this->snapshot();
        $relativePath = 'storage/app/testing/stock-source-of-truth-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload();

        $this->assertSame('b2-stock-source-of-truth-audit-v1', $payload['version']);
        $this->assertSame($before, $this->snapshot());

        $exitCode = Artisan::call('inventory:audit-stock-source-of-truth', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b2-stock-source-of-truth-audit-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:audit-stock-source-of-truth', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $materialId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code
            && ($materialId === null || ($finding['material_id'] ?? null) === $materialId));

        $this->assertIsArray($finding, 'Expected finding '.$code);
        $this->assertSame($severity, $finding['severity']);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Produto '.(string) str()->uuid(),
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
    private function movement(Product $product, string $type, int $quantity, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
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
        ];
    }
}
