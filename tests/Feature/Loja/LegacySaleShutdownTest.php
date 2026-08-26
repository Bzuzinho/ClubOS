<?php

declare(strict_types=1);

namespace Tests\Feature\Loja;

use App\Services\Financeiro\LegacySaleAuditService;
use App\Services\Financeiro\LegacySaleCodeReferenceScanner;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LegacySaleShutdownTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function legacy_sale_audit_service_detects_parallel_records()
    {
        $fixture = $this->createLegacyParallelFixture();

        $payload = app(LegacySaleAuditService::class)->audit();

        $this->assertGreaterThanOrEqual(1, (int) ($payload['summary']['critical_count'] ?? 0));

        $findings = collect($payload['findings'] ?? []);
        $this->assertTrue(
            $findings->contains(
                fn (array $finding): bool => ($finding['code'] ?? null) === 'legacy_sale_parallel_invoice_and_entry'
                    && ($finding['entity_id'] ?? null) === (string) $fixture['sale']->id
            ),
            'Expected finding legacy_sale_parallel_invoice_and_entry for created sale fixture.'
        );
    }

    #[Test]
    public function audit_legacy_sales_command_returns_valid_json()
    {
        $this->artisan('finance:audit-legacy-sales', ['--json' => true])
            ->assertExitCode(0);
    }

    #[Test]
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

    #[Test]
    public function audit_legacy_sales_command_fail_on_parallel_finance_returns_exit_code_one_with_parallel_fixture()
    {
        $this->createLegacyParallelFixture();

        $this->artisan('finance:audit-legacy-sales', [
            '--fail-on-parallel-finance' => true,
        ])->assertExitCode(1);
    }

    #[Test]
    public function audit_legacy_sales_command_fail_on_parallel_finance_returns_exit_code_zero_without_parallel_fixture()
    {
        $this->artisan('finance:audit-legacy-sales', [
            '--fail-on-parallel-finance' => true,
        ])->assertExitCode(0);
    }

    #[Test]
    public function audit_legacy_sales_command_fail_on_operational_write_returns_exit_code_one_when_paths_exist()
    {
        $this->bindScannerResult([
            'operational_write_paths' => [[
                'path' => 'app/Services/FakeWritePath.php',
                'line' => 10,
                'snippet' => 'Sale::create([',
            ]],
            'operational_read_paths' => [],
        ]);

        $this->artisan('finance:audit-legacy-sales', [
            '--fail-on-operational-write' => true,
        ])->assertExitCode(1);
    }

    #[Test]
    public function audit_legacy_sales_command_fail_on_operational_write_returns_exit_code_zero_when_no_paths_exist()
    {
        $this->bindScannerResult([
            'operational_write_paths' => [],
            'operational_read_paths' => [],
        ]);

        $this->artisan('finance:audit-legacy-sales', [
            '--fail-on-operational-write' => true,
        ])->assertExitCode(0);
    }

    /**
     * @return array{sale:Sale,invoice:Invoice,entry:FinancialEntry}
     */
    private function createLegacyParallelFixture(): array
    {
        $product = Product::factory()->create();
        $buyer = User::factory()->create();
        $seller = User::factory()->create();

        $sale = Sale::create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $seller->id,
            'quantidade' => 2,
            'preco_unitario' => 35.00,
            'total' => 70.00,
            'data' => now(),
            'metodo_pagamento' => 'dinheiro',
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $buyer->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => 70,
            'valor_pago' => 0,
            'valor_em_aberto' => 70,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'origem_tipo' => 'stock',
            'origem_id' => $sale->id,
            'oculta' => false,
        ]);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Loja',
            'descricao' => 'Entrada legacy paralela de Sale',
            'valor' => 70,
            'valor_pago' => 0,
            'valor_em_aberto' => 70,
            'estado' => 'pendente',
            'user_id' => $buyer->id,
            'fatura_id' => $invoice->id,
            'origem_tipo' => 'stock',
            'origem_id' => $sale->id,
        ]);

        return [
            'sale' => $sale,
            'invoice' => $invoice,
            'entry' => $entry,
        ];
    }

    /**
     * @param array{operational_write_paths:list<array{path:string,line:int,snippet:string}>,operational_read_paths:list<array{path:string,line:int,snippet:string}>} $scanResult
     */
    private function bindScannerResult(array $scanResult): void
    {
        $fakeScanner = new class($scanResult) extends LegacySaleCodeReferenceScanner {
            /**
             * @param array{operational_write_paths:list<array{path:string,line:int,snippet:string}>,operational_read_paths:list<array{path:string,line:int,snippet:string}>} $scanResult
             */
            public function __construct(private array $scanResult)
            {
            }

            public function scan(): array
            {
                return $this->scanResult;
            }
        };

        $this->app->instance(LegacySaleCodeReferenceScanner::class, $fakeScanner);
        $this->app->forgetInstance(LegacySaleAuditService::class);
    }
}
