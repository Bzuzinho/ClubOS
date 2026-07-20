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

final class StockCorrectionPreflightCommandTest extends TestCase
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

    public function test_delivered_sale_without_exit_that_resolves_mismatch_exactly_is_safe(): void
    {
        $product = $this->product(['stock' => 9]);
        $this->movement($product, 'entry', 10);
        $orderItem = $this->deliveredOrderItem($product, 1);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertSame('create_missing_sale_stock_decrease', $action['action_type']);
        $this->assertTrue($action['safe_to_apply']);
        $this->assertFalse($action['requires_manual_review']);
        $this->assertSame('loja_encomenda_itens', $action['target_table']);
        $this->assertSame($orderItem->id, $action['target_id']);
        $this->assertSame('exit', $action['proposed_stock_movement']['type']);
        $this->assertSame(1, $action['proposed_stock_movement']['quantity']);
        $this->assertSame(9, $action['expected_after']['expected_physical_after']);
        $this->assertSame(0, $action['expected_after']['expected_physical_difference_after']);
        $this->assertSame(1, $payload['summary']['safe_action_count']);
    }

    public function test_delivered_sale_without_exit_that_does_not_resolve_mismatch_is_unsafe(): void
    {
        $product = $this->product(['stock' => 8]);
        $this->movement($product, 'entry', 10);
        $this->deliveredOrderItem($product, 1);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertSame('create_missing_sale_stock_decrease', $action['action_type']);
        $this->assertFalse($action['safe_to_apply']);
        $this->assertTrue($action['requires_manual_review']);
        $this->assertSame(1, $payload['summary']['unsafe_action_count']);
    }

    public function test_orphan_physical_movements_require_manual_review(): void
    {
        $product = $this->product(['stock' => 0]);
        $this->movement($product, 'entry', 5, ['reference_type' => null, 'reference_id' => null]);
        $this->movement($product, 'exit', 2, ['reference_type' => null, 'reference_id' => null]);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = collect($payload['items'][0]['proposed_actions'])->firstWhere('action_type', 'inspect_orphan_physical_stock_movements');

        $this->assertIsArray($action);
        $this->assertFalse($action['safe_to_apply']);
        $this->assertTrue($action['requires_manual_review']);
        $this->assertSame(3, $action['orphan_physical_net_impact']);
        $this->assertSame('inspect_orphan_physical_stock_movements', $payload['items'][0]['final_recommendation']);
    }

    public function test_orphan_or_negative_reservation_balance_requires_manual_review(): void
    {
        $product = $this->product(['stock' => 4, 'stock_reservado' => 5]);
        $this->movement($product, 'entry', 4);
        $this->movement($product, 'reservation', 5, ['reference_type' => null, 'reference_id' => null]);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = collect($payload['items'][0]['proposed_actions'])->firstWhere('action_type', 'inspect_orphan_reservation_balance');

        $this->assertIsArray($action);
        $this->assertFalse($action['safe_to_apply']);
        $this->assertTrue($action['requires_manual_review']);
    }

    public function test_residual_difference_without_known_cause_proposes_unsafe_physical_adjustment(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertSame('create_physical_stock_adjustment', $action['action_type']);
        $this->assertFalse($action['safe_to_apply']);
        $this->assertTrue($action['requires_manual_review']);
    }

    public function test_coherent_material_returns_no_action_needed(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->movement($product, 'entry', 5);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);
        $action = $payload['items'][0]['proposed_actions'][0];

        $this->assertSame('no_action_needed', $action['action_type']);
        $this->assertSame('no_action_needed', $payload['items'][0]['final_recommendation']);
        $this->assertSame(1, $payload['summary']['no_action_needed_count']);
    }

    public function test_only_safe_returns_only_safe_actions(): void
    {
        $safe = $this->product(['stock' => 9]);
        $unsafe = $this->product(['stock' => 8]);
        $this->movement($safe, 'entry', 10);
        $this->movement($unsafe, 'entry', 10);
        $this->deliveredOrderItem($safe, 1);
        $this->deliveredOrderItem($unsafe, 1);

        $payload = $this->jsonPayload([
            '--material' => [$safe->id, $unsafe->id],
            '--only-safe' => true,
        ]);

        $this->assertSame([$safe->id], collect($payload['items'])->pluck('material_id')->all());
        $this->assertSame(1, $payload['summary']['safe_action_count']);
        $this->assertSame(0, $payload['summary']['unsafe_action_count']);
    }

    public function test_fail_on_unsafe_returns_exit_one(): void
    {
        $product = $this->product(['stock' => 3]);
        $this->movement($product, 'entry', 5);

        $exitCode = Artisan::call('inventory:preflight-stock-corrections', [
            '--material' => [$product->id],
            '--fail-on-unsafe' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->product(['stock' => 5]);
        $this->movement($product, 'entry', 5);
        $relativePath = 'storage/app/testing/stock-correction-preflight.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload(['--material' => [$product->id]]);

        $this->assertSame('b1-3-stock-correction-preflight-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:preflight-stock-corrections', [
            '--material' => [$product->id],
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-3-stock-correction-preflight-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_preflight_is_read_only(): void
    {
        $product = $this->product(['stock' => 9]);
        $this->movement($product, 'entry', 10);
        $this->deliveredOrderItem($product, 1);
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
        $exitCode = Artisan::call('inventory:preflight-stock-corrections', array_merge($options, ['--json' => true]));

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
            'reference_type' => 'test_source',
            'reference_id' => (string) str()->uuid(),
        ], $overrides));
    }

    private function deliveredOrderItem(Product $product, int $quantity): LojaEncomendaItem
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
            'loja_encomendas' => LojaEncomenda::query()->orderBy('id')->get()->toArray(),
            'loja_encomenda_itens' => LojaEncomendaItem::query()->orderBy('id')->get()->toArray(),
        ];
    }
}
