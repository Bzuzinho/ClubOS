<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class StockCorrectionApplicationCommandTest extends TestCase
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

    public function test_dry_run_does_not_change_database(): void
    {
        $product = $this->safeMissingSaleProduct();
        $before = $this->snapshot();

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertTrue($payload['dry_run']);
        $this->assertSame('dry_run_ready', $payload['items'][0]['status']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_without_confirmation_is_blocked(): void
    {
        $product = $this->safeMissingSaleProduct();

        $exitCode = Artisan::call('inventory:apply-stock-corrections', [
            '--material' => [$product->id],
            '--apply' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('confirm_stock_correction_required', $payload['block_reason']);
        $this->assertDatabaseMissing('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'reference_type' => 'store_order_item',
        ]);
    }

    public function test_apply_creates_safe_missing_sale_stock_decrease(): void
    {
        $product = $this->safeMissingSaleProduct(['stock' => 9]);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertFalse($payload['dry_run']);
        $this->assertSame(1, $payload['summary']['applied_count']);
        $this->assertSame(1, $payload['summary']['stock_movements_created_count']);
        $this->assertSame(0, $payload['summary']['products_updated_count']);
        $this->assertSame('applied', $payload['items'][0]['status']);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 1,
            'reference_type' => 'store_order_item',
            'notes' => 'Baixa de stock por venda/encomenda entregue registada retroativamente',
        ]);
        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    public function test_apply_keeps_product_stock_as_physical_snapshot_when_already_expected(): void
    {
        $product = $this->safeMissingSaleProduct(['stock' => 9]);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertSame(0, $payload['summary']['products_updated_count']);
        $this->assertSame(9, $payload['items'][0]['product_stock_before']);
        $this->assertSame(9, $payload['items'][0]['product_stock_after']);
        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    public function test_second_apply_is_idempotent_and_does_not_duplicate_movement(): void
    {
        $product = $this->safeMissingSaleProduct();

        $first = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);
        $second = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertSame(1, $first['summary']['applied_count']);
        $this->assertSame(0, $second['summary']['applied_count']);
        $this->assertSame(0, $second['summary']['stock_movements_created_count']);
        $this->assertSame(1, StockMovement::query()->where('article_id', $product->id)->where('movement_type', 'exit')->count());
    }

    public function test_unsafe_action_is_not_applied(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame('unsafe_action_not_applied', $payload['items'][0]['status']);
        $this->assertDatabaseMissing('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'adjustment',
        ]);
    }

    public function test_only_safe_filters_unsafe_actions(): void
    {
        $safe = $this->safeMissingSaleProduct();
        $unsafe = $this->product(['stock' => 3]);
        $this->movement($unsafe, 'entry', 5);

        $payload = $this->jsonPayload([
            '--material' => [$safe->id, $unsafe->id],
            '--only-safe' => true,
        ]);

        $this->assertSame([$safe->id], collect($payload['items'])->pluck('material_id')->all());
        $this->assertSame(1, $payload['summary']['safe_action_count']);
        $this->assertSame(0, $payload['summary']['unsafe_action_count']);
    }

    public function test_fail_on_unsafe_aborts_apply_before_safe_actions(): void
    {
        $safe = $this->safeMissingSaleProduct();
        $unsafe = $this->product(['stock' => 3]);
        $this->movement($unsafe, 'entry', 5);

        $exitCode = Artisan::call('inventory:apply-stock-corrections', [
            '--material' => [$safe->id, $unsafe->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
            '--fail-on-unsafe' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unsafe_actions_present', $payload['block_reason']);
        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertDatabaseMissing('stock_movements', [
            'article_id' => $safe->id,
            'movement_type' => 'exit',
            'reference_type' => 'store_order_item',
        ]);
    }

    public function test_non_delivered_order_is_blocked_by_preflight_and_not_applied(): void
    {
        $product = $this->product(['stock' => 9]);
        $this->movement($product, 'entry', 10);
        $this->orderItem($product, 1, LojaEncomenda::ESTADO_PREPARADO);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame('unsafe_action_not_applied', $payload['items'][0]['status']);
        $this->assertDatabaseMissing('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'reference_type' => 'store_order_item',
        ]);
    }

    public function test_existing_source_movement_keeps_apply_idempotent(): void
    {
        $product = $this->safeMissingSaleProduct();
        $item = LojaEncomendaItem::query()->where('article_id', $product->id)->firstOrFail();
        $this->movement($product, 'exit', 1, [
            'reference_type' => 'store_order_item',
            'reference_id' => $item->id,
        ]);
        Product::query()->whereKey($product->id)->update(['stock' => 9]);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $this->assertSame(0, $payload['summary']['applied_count']);
        $this->assertSame(1, StockMovement::query()->where('article_id', $product->id)->where('movement_type', 'exit')->count());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->safeMissingSaleProduct();
        $relativePath = 'storage/app/testing/stock-correction-application.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame('b1-4-stock-correction-application-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:apply-stock-corrections', [
            '--material' => [$product->id],
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-4-stock-correction-application-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_after_apply_stock_integrity_audit_no_longer_reports_mismatch_for_material(): void
    {
        $product = $this->safeMissingSaleProduct();

        $this->jsonPayload([
            '--material' => [$product->id],
            '--apply' => true,
            '--confirm-stock-correction' => true,
        ]);

        $exitCode = Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $product->id,
            '--json' => true,
            '--only-actionable' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $payload['summary']['stock_mismatch_count']);
        $this->assertFalse(collect($payload['findings'])->contains(fn (array $finding): bool => $finding['code'] === 'missing_sale_stock_decrease'));
    }

    public function test_dry_run_read_only_snapshot_stays_equal(): void
    {
        $product = $this->safeMissingSaleProduct();
        $before = $this->snapshot();

        $this->jsonPayload(['--material' => [$product->id], '--dry-run' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:apply-stock-corrections', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function safeMissingSaleProduct(array $overrides = []): Product
    {
        $product = $this->product(array_merge(['stock' => 9], $overrides));
        $this->movement($product, 'entry', 10);
        $this->orderItem($product, 1, LojaEncomenda::ESTADO_ENTREGUE);

        return $product;
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
            'reference_type' => 'test_source',
            'reference_id' => (string) str()->uuid(),
        ], $overrides));
    }

    private function orderItem(Product $product, int $quantity, string $status): LojaEncomendaItem
    {
        $order = LojaEncomenda::query()->create([
            'numero' => 'ENC-' . strtoupper((string) str()->random(8)),
            'user_id' => User::factory()->create()->id,
            'estado' => $status,
            'subtotal' => $quantity * 10,
            'total' => $quantity * 10,
            'origem' => 'test',
        ]);

        return LojaEncomendaItem::query()->create([
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'descricao' => $product->nome,
            'quantidade' => $quantity,
            'preco_unitario' => 10,
            'total_linha' => $quantity * 10,
        ]);
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
            'loja_encomendas' => LojaEncomenda::query()->orderBy('id')->get()->toArray(),
            'loja_encomenda_itens' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
