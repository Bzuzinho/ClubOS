<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OrphanStockMovementInspectionCommandTest extends TestCase
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

    public function test_orphan_movement_with_manual_adjustment_notes_is_classified_as_manual_candidate(): void
    {
        $product = $this->product();
        $movement = $this->orphanMovement($product, 'entry', 3, [
            'notes' => 'Ajuste manual de inventario inicial',
        ]);

        $payload = $this->jsonPayload(['--movement' => [$movement->id]]);

        $this->assertSame('orphan_manual_adjustment_candidate', $payload['items'][0]['classification']);
        $this->assertSame('accept_as_manual_adjustment', $payload['items'][0]['recommended_next_action']);
        $this->assertFalse($payload['items'][0]['actionable']);
        $this->assertSame(1, $payload['summary']['manual_adjustment_candidate_count']);
    }

    public function test_orphan_movement_with_nearby_supplier_purchase_is_missing_source_candidate(): void
    {
        $product = $this->product();
        $movement = $this->orphanMovement($product, 'entry', 5, [
            'created_at' => Carbon::parse('2026-07-20 10:00:00'),
        ]);
        $purchase = $this->supplierPurchase($product, 5, Carbon::parse('2026-07-20 10:05:00'));

        $payload = $this->jsonPayload(['--movement' => [$movement->id]]);
        $link = collect($payload['items'][0]['candidate_links'])->firstWhere('source_type', 'supplier_purchase');

        $this->assertSame('orphan_missing_source_candidate', $payload['items'][0]['classification']);
        $this->assertSame('link_to_existing_source_after_review', $payload['items'][0]['recommended_next_action']);
        $this->assertTrue($payload['items'][0]['actionable']);
        $this->assertSame($purchase->id, $link['source_id']);
        $this->assertTrue($link['quantity_matches']);
    }

    public function test_orphan_movement_without_notes_or_context_requires_manual_review(): void
    {
        $product = $this->product();
        $movement = $this->orphanMovement($product, 'exit', 2);

        $payload = $this->jsonPayload(['--movement' => [$movement->id]]);

        $this->assertSame('orphan_requires_manual_review', $payload['items'][0]['classification']);
        $this->assertSame('manual_review_required', $payload['items'][0]['recommended_next_action']);
        $this->assertSame(1, $payload['summary']['requires_manual_review_count']);
    }

    public function test_impact_if_excluded_is_calculated_correctly(): void
    {
        $product = $this->product(['stock' => 5, 'stock_reservado' => 1]);
        $movement = $this->orphanMovement($product, 'exit', 2);
        $this->orphanMovement($product, 'reservation', 1);

        $payload = $this->jsonPayload(['--movement' => [$movement->id]]);
        $impact = $payload['items'][0]['impact'];

        $this->assertSame(-2, $impact['physical_delta']);
        $this->assertSame(0, $impact['reserved_delta']);
        $this->assertSame(2, $impact['impact_if_excluded']['physical_delta']);
        $this->assertSame(0, $impact['stock_after_if_excluded']['calculated_physical_stock']);
        $this->assertSame(5, $impact['stock_after_if_excluded']['physical_difference']);
    }

    public function test_material_filter_limits_scope(): void
    {
        $included = $this->product();
        $excluded = $this->product();
        $this->orphanMovement($included, 'entry', 1);
        $this->orphanMovement($excluded, 'entry', 1);

        $payload = $this->jsonPayload(['--material' => [$included->id]]);

        $this->assertSame([$included->id], collect($payload['items'])->pluck('material.id')->all());
    }

    public function test_movement_filter_limits_scope(): void
    {
        $product = $this->product();
        $included = $this->orphanMovement($product, 'entry', 1);
        $this->orphanMovement($product, 'return', 1);

        $payload = $this->jsonPayload(['--movement' => [$included->id]]);

        $this->assertSame([$included->id], collect($payload['items'])->pluck('movement.id')->all());
    }

    public function test_only_actionable_removes_accepted_or_info_candidates(): void
    {
        $product = $this->product();
        $this->orphanMovement($product, 'entry', 1, ['notes' => 'Movimento aceite e documentado']);
        $actionable = $this->orphanMovement($product, 'exit', 1);

        $payload = $this->jsonPayload(['--only-actionable' => true]);

        $this->assertSame([$actionable->id], collect($payload['items'])->pluck('movement.id')->all());
        $this->assertSame(1, $payload['summary']['actionable_count']);
    }

    public function test_fail_on_actionable_returns_exit_one(): void
    {
        $product = $this->product();
        $this->orphanMovement($product, 'exit', 1);

        $exitCode = Artisan::call('inventory:inspect-orphan-stock-movements', [
            '--fail-on-actionable' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->product();
        $this->orphanMovement($product, 'entry', 1);
        $relativePath = 'storage/app/testing/orphan-stock-movements.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload();

        $this->assertSame('b1-5-orphan-stock-movement-inspection-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:inspect-orphan-stock-movements', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-5-orphan-stock-movement-inspection-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_inspection_is_read_only(): void
    {
        $product = $this->product();
        $this->orphanMovement($product, 'entry', 1);
        $this->supplierPurchase($product, 1, Carbon::parse('2026-07-20 12:05:00'));
        $this->equipmentLoan($product, 1);
        $this->storeOrderItem($product, 1);
        $before = $this->snapshot();

        $this->jsonPayload();

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('inventory:inspect-orphan-stock-movements', array_merge($options, ['--json' => true]));

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
    private function orphanMovement(Product $product, string $type, int $quantity, array $overrides = []): StockMovement
    {
        return StockMovement::query()->create(array_merge([
            'article_id' => $product->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'reference_type' => null,
            'reference_id' => null,
        ], $overrides));
    }

    private function supplierPurchase(Product $product, int $quantity, Carbon $createdAt): SupplierPurchase
    {
        $purchase = SupplierPurchase::query()->create([
            'supplier_name_snapshot' => 'Fornecedor Teste',
            'invoice_reference' => 'FT-' . strtoupper((string) str()->random(6)),
            'invoice_date' => $createdAt->toDateString(),
            'total_amount' => $quantity * 10,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        SupplierPurchaseItem::query()->create([
            'supplier_purchase_id' => $purchase->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'unit_cost' => 10,
            'line_total' => $quantity * 10,
        ]);

        return $purchase;
    }

    private function equipmentLoan(Product $product, int $quantity): EquipmentLoan
    {
        return EquipmentLoan::query()->create([
            'borrower_name_snapshot' => 'Atleta Teste',
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => $quantity,
            'loan_date' => Carbon::today()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function storeOrderItem(Product $product, int $quantity): LojaEncomendaItem
    {
        $order = LojaEncomenda::query()->create([
            'numero' => 'ENC-' . strtoupper((string) str()->random(8)),
            'user_id' => User::factory()->create()->id,
            'estado' => LojaEncomenda::ESTADO_ENTREGUE,
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
            'supplier_purchases' => SupplierPurchase::query()->orderBy('id')->get()->toArray(),
            'supplier_purchase_items' => SupplierPurchaseItem::query()->orderBy('id')->get()->toArray(),
            'equipment_loans' => EquipmentLoan::query()->orderBy('id')->get()->toArray(),
            'loja_encomendas' => LojaEncomenda::query()->orderBy('id')->get()->toArray(),
            'loja_encomenda_itens' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
