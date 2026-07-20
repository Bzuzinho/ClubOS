<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StockMismatchInspectionCommandTest extends TestCase
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

    public function test_clean_product_is_reported_without_mismatch(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame(1, $payload['summary']['clean_count']);
        $this->assertSame(0, $payload['summary']['mismatch_count']);
        $this->assertSame('no_action_needed', $payload['items'][0]['recommended_next_action']);
    }

    public function test_stored_stock_above_calculated_recommends_initial_or_correction_movement(): void
    {
        $product = $this->product(['stock' => 6]);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame(6, $payload['items'][0]['difference']);
        $this->assertContains($payload['items'][0]['recommended_next_action'], ['create_initial_stock_adjustment', 'create_stock_correction_movement']);
    }

    public function test_calculated_stock_above_stored_recommends_recalculate(): void
    {
        $product = $this->product(['stock' => 1]);
        $this->movement($product, 'entry', 3);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame(-2, $payload['items'][0]['difference']);
        $this->assertSame('recalculate_stored_stock_from_movements', $payload['items'][0]['recommended_next_action']);
    }

    public function test_unknown_movement_type_is_flagged(): void
    {
        $product = $this->product(['stock' => 0]);
        $this->movement($product, 'mystery', 4);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertContains('unknown_movement_type', $payload['items'][0]['movements'][0]['suspicion_flags']);
        $this->assertSame(1, $payload['summary']['unknown_type_movement_count']);
    }

    public function test_probable_duplicate_movement_is_flagged(): void
    {
        $product = $this->product(['stock' => 10]);
        $this->movement($product, 'entry', 5, ['reference_type' => 'manual_adjustment', 'reference_id' => null]);
        $this->movement($product, 'entry', 5, ['reference_type' => 'manual_adjustment', 'reference_id' => null]);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertContains('suspected_duplicate_movement', $payload['items'][0]['movements'][1]['suspicion_flags']);
        $this->assertSame(1, $payload['summary']['suspected_duplicate_movement_count']);
    }

    public function test_invoice_item_without_stock_movement_is_flagged(): void
    {
        $product = $this->product(['stock' => 5]);
        $invoice = Invoice::query()->create([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-07-20',
            'data_emissao' => '2026-07-20',
            'data_vencimento' => '2026-07-20',
            'valor_total' => 10,
            'valor_pago' => 0,
            'valor_em_aberto' => 10,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
        ]);
        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => $product->nome,
            'quantidade' => 1,
            'valor_unitario' => 10,
            'imposto_percentual' => 0,
            'total_linha' => 10,
            'produto_id' => $product->id,
        ]);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertContains('missing_sale_stock_decrease', $payload['items'][0]['related_sales_or_invoice_items'][0]['suspicion_flags']);
        $this->assertSame(1, $payload['summary']['possible_missing_sale_movement_count']);
    }

    public function test_cancelled_movement_type_is_not_counted_and_is_flagged(): void
    {
        $product = $this->product(['stock' => 0]);
        $this->movement($product, 'cancelled', 5);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame(0, $payload['items'][0]['calculated_stock']);
        $this->assertFalse($payload['items'][0]['movements'][0]['counted_in_calculation']);
        $this->assertContains('movement_cancelled_or_deleted', $payload['items'][0]['movements'][0]['suspicion_flags']);
    }

    public function test_running_stock_is_calculated_chronologically(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);
        $this->movement($product, 'exit', 2);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame([5, 3], collect($payload['items'][0]['movements'])->pluck('running_stock')->all());
    }

    public function test_material_and_sku_filters_work(): void
    {
        $matching = $this->product(['stock' => 2, 'codigo' => 'SKU-P']);
        $other = $this->product(['stock' => 2]);
        ProductVariant::query()->create([
            'product_id' => $matching->id,
            'nome' => 'S',
            'sku' => 'SKU-V',
            'stock' => 0,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $byMaterial = $this->jsonPayload(['--material' => [$matching->id]]);
        $bySku = $this->jsonPayload(['--sku' => 'SKU-V']);

        $this->assertSame([$matching->id], collect($byMaterial['items'])->pluck('material.id')->all());
        $this->assertSame([$matching->id], collect($bySku['items'])->pluck('material.id')->all());
        $this->assertNotContains($other->id, collect($bySku['items'])->pluck('material.id')->all());
    }

    public function test_only_mismatch_removes_clean_items(): void
    {
        $clean = $this->product(['stock' => 5]);
        $mismatch = $this->product(['stock' => 2]);
        $this->movement($clean, 'entry', 5);

        $payload = $this->jsonPayload([
            '--material' => [$clean->id, $mismatch->id],
            '--only-mismatch' => true,
        ]);

        $this->assertSame([$mismatch->id], collect($payload['items'])->pluck('material.id')->all());
    }

    public function test_fail_on_mismatch_returns_exit_one(): void
    {
        $product = $this->product(['stock' => 2]);

        $exitCode = Artisan::call('inventory:inspect-stock-mismatch', [
            '--material' => [$product->id],
            '--fail-on-mismatch' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->product(['stock' => 2]);
        $relativePath = 'storage/app/testing/stock-mismatch-inspection.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame('b1-1-stock-mismatch-inspection-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:inspect-stock-mismatch', [
            '--material' => [$product->id],
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-1-stock-mismatch-inspection-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_inspection_is_read_only(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);
        $before = $this->snapshot();

        $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:inspect-stock-mismatch', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Produto ' . (string) str()->uuid(),
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
            'ativo' => true,
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
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'products' => Product::query()->orderBy('id')->get()->toArray(),
            'product_variants' => ProductVariant::query()->orderBy('id')->get()->toArray(),
            'stock_movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'invoice_items' => InvoiceItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
