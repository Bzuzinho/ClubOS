<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ItemCategory;
use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StockIntegrityAuditCommandTest extends TestCase
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

    public function test_without_inventory_records_returns_info_and_does_not_fail(): void
    {
        $payload = $this->jsonPayload();

        $this->assertSame(0, $payload['summary']['total_materials_scanned']);
        $this->assertSame('no_inventory_records', $payload['findings'][0]['code']);
        $this->assertSame('info', $payload['findings'][0]['severity']);
        $this->assertFalse($payload['findings'][0]['actionable']);
    }

    public function test_coherent_material_stock_does_not_generate_warning(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertSame([], collect($payload['findings'])->whereIn('severity', ['warning', 'critical'])->values()->all());
    }

    public function test_include_clean_reports_clean_stock_item_as_info(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => $product->id, '--include-clean' => true]);

        $this->assertFinding($payload, 'clean_stock_item', 'info', $product->id);
    }

    public function test_negative_stock_generates_critical(): void
    {
        $product = $this->product(['stock' => -1]);

        $payload = $this->jsonPayload(['--material' => $product->id, '--include-zero' => true]);

        $this->assertFinding($payload, 'negative_stock', 'critical', $product->id);
    }

    public function test_stock_quantity_mismatch_generates_warning_or_critical(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'stock_quantity_mismatch', 'warning', $product->id);
    }

    public function test_stock_movement_without_material_generates_warning(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::table('stock_movements')->insert([
                'id' => (string) str()->uuid(),
                'article_id' => (string) str()->uuid(),
                'movement_type' => 'entry',
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }

        $payload = $this->jsonPayload(['--include-zero' => true, '--include-inactive' => true]);

        $this->assertFinding($payload, 'stock_movement_without_material', 'warning');
    }

    public function test_active_overdue_loan_generates_warning(): void
    {
        $product = $this->product(['stock' => 5]);
        EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'Atleta',
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => 1,
            'loan_date' => '2026-07-01',
            'due_date' => '2026-07-10',
            'status' => 'active',
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'overdue_loan', 'warning', $product->id);
    }

    public function test_active_loans_exceeding_available_stock_generate_warning(): void
    {
        $product = $this->product(['stock' => 1]);
        EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'Atleta',
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => 2,
            'loan_date' => '2026-07-19',
            'status' => 'active',
        ]);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'active_loan_exceeds_available_stock', 'warning', $product->id);
    }

    public function test_pending_request_without_stock_generates_warning(): void
    {
        $product = $this->product(['stock' => 1]);
        $this->requestItem($product, 'approved', 2);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'pending_request_without_stock', 'warning', $product->id);
    }

    public function test_inactive_material_with_stock_generates_warning_when_included(): void
    {
        $product = $this->product(['stock' => 2, 'ativo' => false]);

        $payload = $this->jsonPayload(['--material' => $product->id, '--include-inactive' => true]);

        $this->assertFinding($payload, 'inactive_material_with_stock', 'warning', $product->id);
    }

    public function test_invoice_item_without_stock_decrease_generates_warning(): void
    {
        $product = $this->product(['stock' => 5, 'allow_sale' => true, 'track_stock' => true]);
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

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertFinding($payload, 'sale_or_invoice_item_without_stock_decrease', 'warning', $product->id);
    }

    public function test_only_actionable_removes_infos(): void
    {
        $product = $this->product(['stock' => 0]);

        $payload = $this->jsonPayload([
            '--material' => $product->id,
            '--include-zero' => true,
            '--only-actionable' => true,
        ]);

        $this->assertNotContains('zero_stock_active_material', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_fail_flags_return_exit_one(): void
    {
        $critical = $this->product(['stock' => -1]);
        $warning = $this->product(['stock' => 3]);
        $this->movement($warning, 'entry', 5);

        $criticalExit = Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $critical->id,
            '--fail-on-critical' => true,
        ]);
        $warningExit = Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $warning->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $criticalExit);
        $this->assertSame(1, $warningExit);
    }

    public function test_filters_material_category_and_location_work(): void
    {
        $category = ItemCategory::query()->create(['codigo' => 'CAT-A', 'nome' => 'Categoria A', 'contexto' => 'stock', 'ativo' => true]);
        $matching = $this->product(['stock' => 1, 'categoria_id' => $category->id, 'area_armazenamento' => 'Sala A']);
        $other = $this->product(['stock' => 1, 'area_armazenamento' => 'Sala B']);
        $this->movement($matching, 'entry', 3);
        $this->movement($other, 'entry', 3);

        $this->assertSame([$matching->id], collect($this->jsonPayload(['--material' => $matching->id])['findings'])->pluck('material_id')->filter()->unique()->values()->all());
        $this->assertNotContains($other->id, collect($this->jsonPayload(['--category' => $category->id])['findings'])->pluck('material_id')->all());
        $this->assertNotContains($other->id, collect($this->jsonPayload(['--location' => 'Sala A'])['findings'])->pluck('material_id')->all());
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->product(['stock' => -1]);
        $relativePath = 'storage/app/testing/stock-integrity-audit.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--material' => $product->id]);

        $this->assertSame('b1-stock-integrity-audit-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $product->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-stock-integrity-audit-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_audit_is_read_only(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);
        $before = $this->snapshot();

        $this->jsonPayload(['--material' => $product->id]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:audit-stock-integrity', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $materialId = null): void
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code
            && ($materialId === null || $finding['material_id'] === $materialId));

        $this->assertIsArray($finding, 'Expected finding ' . $code);
        $this->assertSame($severity, $finding['severity']);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
            'ativo' => true,
        ], $overrides));
    }

    private function movement(Product $product, string $type, int $quantity): StockMovement
    {
        return StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
        ]);
    }

    private function requestItem(Product $product, string $status, int $quantity): LogisticsRequestItem
    {
        $request = LogisticsRequest::query()->create([
            'requester_name_snapshot' => 'Equipa',
            'status' => $status,
            'total_amount' => 0,
        ]);

        return LogisticsRequestItem::query()->create([
            'logistics_request_id' => $request->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'unit_price' => 0,
            'line_total' => 0,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'products' => Product::query()->orderBy('id')->get()->toArray(),
            'stock_movements' => StockMovement::query()->orderBy('id')->get()->toArray(),
            'equipment_loans' => EquipmentLoan::query()->orderBy('id')->get()->toArray(),
            'logistics_requests' => LogisticsRequest::query()->orderBy('id')->get()->toArray(),
            'logistics_request_items' => LogisticsRequestItem::query()->orderBy('id')->get()->toArray(),
            'invoice_items' => InvoiceItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
