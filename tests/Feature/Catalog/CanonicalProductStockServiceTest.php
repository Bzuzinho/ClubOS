<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalog\CanonicalProductStockService;
use App\Services\Logistica\RegisterStockMovementAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CanonicalProductStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_decrement_on_sale_routes_product_stock_through_ledger(): void
    {
        $product = $this->product(['stock' => 8, 'stock_reservado' => 1]);
        $sourceId = (string) str()->uuid();

        app(CanonicalProductStockService::class)->decrementOnSale($product, null, 2, [
            'source_type' => 'store_order_item',
            'source_id' => $sourceId,
            'idempotency_key' => 'canonical-product-stock-'.$sourceId,
            'notes' => 'Saída de stock por teste canónico da loja',
        ]);

        $product->refresh();
        $this->assertSame(6, (int) $product->stock);
        $this->assertSame(1, (int) $product->stock_reservado);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 2,
            'reference_type' => 'store_order_item',
            'reference_id' => $sourceId,
        ]);
    }

    public function test_decrement_on_sale_is_idempotent_for_same_context_including_variant_snapshot(): void
    {
        $product = $this->product(['stock' => 8]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Senior',
            'sku' => 'CAN-IDEMPOTENT-SR',
            'stock' => 5,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);
        $sourceId = (string) str()->uuid();
        $context = [
            'source_type' => 'store_order_item',
            'source_id' => $sourceId,
            'idempotency_key' => 'canonical-product-stock-'.$sourceId,
            'notes' => 'Saída de stock por teste idempotente da loja',
        ];

        $service = app(CanonicalProductStockService::class);
        $service->decrementOnSale($product, $variant, 3, $context);
        $service->decrementOnSale($product->fresh(), $variant->fresh(), 3, $context);

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->where('movement_type', 'exit')
            ->where('reference_type', 'store_order_item')
            ->where('reference_id', $sourceId)
            ->count());
    }

    public function test_store_rejects_product_when_sale_capability_is_disabled(): void
    {
        $product = $this->product(['allow_sale' => false, 'stock' => 5]);

        $this->expectException(ValidationException::class);

        app(CanonicalProductStockService::class)->ensureAvailableForStore($product, null, 1);
    }

    public function test_manual_variant_movement_updates_variant_and_aggregate_atomically(): void
    {
        $product = $this->product();
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Adulto',
            'sku' => 'CAN-MOVEMENT-M',
            'stock' => 0,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        app(RegisterStockMovementAction::class)->execute([
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'movement_type' => 'entry',
            'quantity' => 3,
            'notes' => 'Entrada manual por variante',
        ]);

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $variant->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'movement_type' => 'entry',
            'quantity' => 3,
        ]);
    }

    public function test_product_level_logistics_operation_is_rejected_when_variants_exist(): void
    {
        $product = $this->product();
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Junior',
            'sku' => 'CAN-GUARD-JR',
            'stock' => 0,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(RegisterStockMovementAction::class)->execute([
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'quantity' => 1,
        ]);
    }

    public function test_store_requires_variant_for_product_with_active_variants(): void
    {
        $product = $this->product(['stock' => 3]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Senior',
            'sku' => 'CAN-STORE-VARIANT-SR',
            'stock' => 3,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(CanonicalProductStockService::class)->ensureAvailableForStore($product, null, 1);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Produto Catalogo '.(string) str()->uuid(),
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'track_stock' => true,
            'ativo' => true,
            'visible_in_store' => true,
            'stock' => 0,
            'stock_reservado' => 0,
        ], $overrides));
    }
}
