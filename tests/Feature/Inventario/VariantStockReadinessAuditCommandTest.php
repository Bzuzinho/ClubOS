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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class VariantStockReadinessAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_schema_returns_aggregated_read_only_report(): void
    {
        $exitCode = Artisan::call('inventory:audit-variant-stock-readiness', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('variant-stock-readiness-v1', $payload['version'] ?? null);
        $this->assertTrue((bool) ($payload['read_only'] ?? false));
        $this->assertTrue((bool) ($payload['schema_detected']['required_source_schema_present'] ?? false));
        $this->assertTrue((bool) ($payload['schema_detected']['stock_movement_variant_column_present'] ?? false));
        $this->assertSame(0, (int) ($payload['summary']['variant_count'] ?? -1));
        $this->assertTrue((bool) ($payload['summary']['ready_for_design'] ?? false));
    }

    public function test_report_measures_variant_snapshots_and_historical_exit_attribution_without_writes(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'stock' => 7,
            'stock_reservado' => 1,
            'track_stock' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Senior',
            'sku' => 'VARIANT-AUDIT-SR',
            'stock' => 7,
            'stock_reservado' => 1,
            'ativo' => true,
        ]);
        $order = LojaEncomenda::query()->create([
            'numero' => 'VARIANT-AUDIT-001',
            'user_id' => $user->id,
            'estado' => LojaEncomenda::ESTADO_PENDENTE,
            'subtotal' => 20,
            'total' => 20,
            'origem' => 'portal',
        ]);
        $item = LojaEncomendaItem::query()->create([
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'descricao' => 'Produto variante',
            'quantidade' => 2,
            'preco_unitario' => 10,
            'total_linha' => 20,
        ]);
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 2,
            'reference_type' => 'store_order_item',
            'reference_id' => $item->id,
        ]);

        $before = [
            'variants' => ProductVariant::query()->orderBy('id')->get()->toArray(),
            'movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'items' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
        ];

        $exitCode = Artisan::call('inventory:audit-variant-stock-readiness', [
            '--json' => true,
            '--fail-on-invalid-reference' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, (int) $payload['summary']['variant_count']);
        $this->assertSame(1, (int) $payload['summary']['active_variant_count']);
        $this->assertSame(1, (int) $payload['summary']['variant_with_nonzero_stock_count']);
        $this->assertSame(1, (int) $payload['summary']['variant_with_nonzero_reserved_count']);
        $this->assertSame(0, (int) $payload['summary']['invalid_variant_snapshot_count']);
        $this->assertSame(1, (int) $payload['summary']['product_with_variants_count']);
        $this->assertSame(1, (int) $payload['summary']['tracked_product_with_variants_count']);
        $this->assertSame(0, (int) $payload['summary']['product_variant_aggregate_mismatch_count']);
        $this->assertSame(1, (int) $payload['summary']['variant_order_item_count']);
        $this->assertSame(1, (int) $payload['summary']['variant_order_item_exact_exit_count']);
        $this->assertSame(0, (int) $payload['summary']['variant_order_item_missing_exit_count']);
        $this->assertSame(0, (int) $payload['summary']['invalid_product_variant_reference_count']);
        $this->assertSame(0, (int) $payload['summary']['known_direct_variant_stock_writer_count']);
        $this->assertTrue((bool) $payload['summary']['ready_for_design']);

        $this->assertSame($before, [
            'variants' => ProductVariant::query()->orderBy('id')->get()->toArray(),
            'movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'items' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
        ]);
    }

    public function test_report_path_contains_no_row_level_identifiers(): void
    {
        $path = storage_path('framework/testing/variant-stock-readiness.json');

        Artisan::call('inventory:audit-variant-stock-readiness', [
            '--json' => true,
            '--report-path' => $path,
        ]);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('items', $payload);
        $this->assertArrayNotHasKey('findings', $payload);
        $this->assertTrue(Schema::hasTable('product_variants'));
    }
}
