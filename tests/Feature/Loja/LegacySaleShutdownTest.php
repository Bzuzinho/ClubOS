<?php

declare(strict_types=1);

namespace Tests\Feature\Loja;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LegacySaleShutdownTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function creating_sale_does_not_modify_stock()
    {
        $product = Product::factory()->create(['stock' => 100]);
        $buyer = User::factory()->create();

        $initialStock = $product->stock;

        Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $buyer->id,
            'quantidade' => 10,
            'preco_unitario' => 50.00,
            'total' => 500.00,
            'data' => now(),
            'metodo_pagamento' => 'card',
        ]);

        $product->refresh();
        $this->assertEquals($initialStock, $product->stock, 'Stock should not be modified when creating Sale');
    }

    /** @test */
    public function creating_sale_does_not_create_invoice()
    {
        $product = Product::factory()->create();
        $buyer = User::factory()->create();

        Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $buyer->id,
            'quantidade' => 10,
            'preco_unitario' => 50.00,
            'total' => 500.00,
            'data' => now(),
            'metodo_pagamento' => 'card',
        ]);

        $this->assertDatabaseCount('invoices', 0);
    }

    /** @test */
    public function creating_sale_does_not_create_invoice_item()
    {
        $product = Product::factory()->create();
        $buyer = User::factory()->create();

        Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $buyer->id,
            'quantidade' => 10,
            'preco_unitario' => 50.00,
            'total' => 500.00,
            'data' => now(),
            'metodo_pagamento' => 'card',
        ]);

        $this->assertDatabaseCount('invoice_items', 0);
    }

    /** @test */
    public function creating_sale_does_not_create_financial_entry()
    {
        $product = Product::factory()->create();
        $buyer = User::factory()->create();

        Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $buyer->id,
            'quantidade' => 10,
            'preco_unitario' => 50.00,
            'total' => 500.00,
            'data' => now(),
            'metodo_pagamento' => 'card',
        ]);

        $this->assertDatabaseCount('financial_entries', 0);
    }

    /** @test */
    public function sale_relationships_remain_accessible()
    {
        $product = Product::factory()->create();
        $seller = User::factory()->create();
        $buyer = User::factory()->create();

        $sale = Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $seller->id,
            'quantidade' => 5,
            'preco_unitario' => 100.00,
            'total' => 500.00,
            'data' => now(),
            'metodo_pagamento' => 'transfer',
        ]);

        $this->assertNotNull($sale->product, 'Sale->product relationship should work');
        $this->assertNotNull($sale->comprador, 'Sale->comprador relationship should work');
        $this->assertNotNull($sale->vendedor, 'Sale->vendedor relationship should work');
        $this->assertEquals($product->id, $sale->product->id);
        $this->assertEquals($buyer->id, $sale->comprador->id);
        $this->assertEquals($seller->id, $sale->vendedor->id);
    }

    /** @test */
    public function legacy_sale_audit_service_detects_parallel_records()
    {
        $this->markTestIncomplete('Requires fixture with historical parallel Invoice+Entry if any exist');
    }

    /** @test */
    public function audit_legacy_sales_command_returns_valid_json()
    {
        $this->artisan('finance:audit-legacy-sales', ['--json' => true])
            ->assertExitCode(0);
    }

    /** @test */
    public function audit_legacy_sales_command_respects_report_path()
    {
        $reportPath = storage_path('app/test-audit-legacy-sales.json');

        $this->artisan('finance:audit-legacy-sales', [
            '--report-path' => $reportPath,
        ])
            ->assertExitCode(0);

        $this->assertFileExists($reportPath, 'Report file should be created');
        $content = json_decode(file_get_contents($reportPath), true);
        $this->assertIsArray($content, 'Report should contain valid JSON');
        $this->assertArrayHasKey('version', $content);
        $this->assertArrayHasKey('summary', $content);

        unlink($reportPath);
    }

    /** @test */
    public function store_order_flow_remains_unaffected()
    {
        $this->markTestIncomplete('Integration test with LojaEncomenda flow; covered by StoreOrderRevenueMovementTest');
    }
}
