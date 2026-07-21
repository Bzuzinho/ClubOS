<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Models\EquipmentLoan;
use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OrphanStockMovementResolutionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_full_safe_resolution_proposes_reservation_release_and_physical_adjustment(): void
    {
        $product = $this->shirtSFixture();

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
        ]);

        $actions = collect($payload['items'][0]['proposed_actions']);

        $this->assertTrue($payload['dry_run']);
        $this->assertSame(6, $payload['summary']['proposed_action_count']);
        $this->assertSame(-5, $actions->firstWhere('action_type', 'release_orphan_reservation')['quantity']);
        $this->assertSame(1, $actions->firstWhere('action_type', 'physical_adjustment')['quantity']);
        $this->assertSame(5, $payload['items'][0]['expected_after']['calculated_physical_stock']);
        $this->assertSame(0, $payload['items'][0]['expected_after']['calculated_reserved_stock']);
        $this->assertSame(5, $payload['items'][0]['expected_after']['calculated_available_stock']);
    }

    public function test_apply_without_confirmation_is_blocked(): void
    {
        $product = $this->shirtSFixture();

        $exitCode = Artisan::call('inventory:resolve-orphan-stock-movements', [
            '--material' => [$product->id],
            '--strategy' => 'release_orphan_reservation',
            '--apply' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('confirm_orphan_resolution_required', $payload['block_reason']);
        $this->assertSame(5, StockMovement::query()->where('article_id', $product->id)->count());
    }

    public function test_release_orphan_reservation_creates_negative_reservation_and_updates_reserved_snapshot(): void
    {
        $product = $this->shirtSFixture();

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'release_orphan_reservation',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        $created = StockMovement::query()->where('notes', 'Libertação de reserva órfã após revisão de auditoria B1.6')->first();

        $this->assertFalse($payload['dry_run']);
        $this->assertNotNull($created);
        $this->assertSame('reservation', $created->movement_type);
        $this->assertSame(-5, (int) $created->quantity);
        $this->assertSame('audit_orphan_resolution', $created->reference_type);
        $this->assertNull($created->reference_id);
        $this->assertSame(0, (int) $product->fresh()->stock_reservado);
    }

    public function test_physical_adjustment_creates_entry_for_difference(): void
    {
        $product = $this->product(['stock' => 5, 'stock_reservado' => 0]);
        $this->orphanMovement($product, 'entry', 4);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'physical_adjustment',
            '--target-physical-stock' => 5,
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        $created = StockMovement::query()->where('notes', 'Ajuste físico após revisão de auditoria B1.6 para alinhar stock calculado ao stock guardado')->first();

        $this->assertSame(1, $payload['summary']['stock_movements_created_count']);
        $this->assertNotNull($created);
        $this->assertSame('entry', $created->movement_type);
        $this->assertSame(1, (int) $created->quantity);
        $this->assertSame(5, $payload['items'][0]['expected_after']['calculated_physical_stock']);
    }

    public function test_full_safe_resolution_combines_actions_without_changing_historical_quantities_or_types(): void
    {
        $product = $this->shirtSFixture();
        $before = StockMovement::query()
            ->where('article_id', $product->id)
            ->whereNull('reference_type')
            ->whereNull('reference_id')
            ->orderBy('created_at')
            ->get(['id', 'movement_type', 'quantity'])
            ->keyBy('id')
            ->map(fn (StockMovement $movement): array => [$movement->movement_type, (int) $movement->quantity])
            ->all();

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        foreach ($before as $id => $signature) {
            $movement = StockMovement::query()->findOrFail($id);
            $this->assertSame($signature, [$movement->movement_type, (int) $movement->quantity]);
            $this->assertStringContainsString('Movimento órfão revisto e aceite', (string) $movement->notes);
        }

        $this->assertSame(6, $payload['summary']['applied_count']);
        $this->assertSame(2, $payload['summary']['stock_movements_created_count']);
        $this->assertSame(4, $payload['summary']['orphan_movements_marked_reviewed_count']);
        $this->assertSame(5, $payload['items'][0]['expected_after']['calculated_physical_stock']);
        $this->assertSame(0, $payload['items'][0]['expected_after']['calculated_reserved_stock']);
        $this->assertSame(5, $payload['items'][0]['expected_after']['calculated_available_stock']);
    }

    public function test_second_apply_is_idempotent_and_does_not_duplicate_movements(): void
    {
        $product = $this->shirtSFixture();
        $options = [
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ];

        $this->jsonPayload($options);
        $countAfterFirst = StockMovement::query()->where('article_id', $product->id)->count();
        $second = $this->jsonPayload($options);

        $this->assertSame($countAfterFirst, StockMovement::query()->where('article_id', $product->id)->count());
        $this->assertSame(0, $second['summary']['applied_count']);
        $this->assertGreaterThanOrEqual(1, $second['summary']['skipped_count']);
    }

    public function test_source_id_never_receives_empty_string(): void
    {
        $product = $this->shirtSFixture();

        $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        $this->assertFalse(StockMovement::query()->get()->contains(fn (StockMovement $movement): bool => $movement->reference_id === ''));
        $this->assertGreaterThan(0, StockMovement::query()->where('reference_type', 'audit_orphan_resolution')->whereNull('reference_id')->count());
    }

    public function test_product_without_reservation_does_not_create_release(): void
    {
        $product = $this->product(['stock' => 1, 'stock_reservado' => 0]);
        $this->orphanMovement($product, 'entry', 1);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'release_orphan_reservation',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        $this->assertSame(0, $payload['summary']['stock_movements_created_count']);
        $this->assertSame('no_reserved_stock_to_release', $payload['items'][0]['proposed_actions'][0]['reason']);
    }

    public function test_active_logistics_request_blocks_automatic_reservation_release(): void
    {
        $product = $this->shirtSFixture();
        $this->logisticsRequest($product);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'release_orphan_reservation',
        ]);

        $this->assertSame(1, $payload['summary']['unsafe_action_count']);
        $this->assertStringContainsString('active_or_pending_logistics_request_present', $payload['items'][0]['proposed_actions'][0]['reason']);
    }

    public function test_fail_on_unsafe_returns_exit_one(): void
    {
        $product = $this->shirtSFixture();
        $this->logisticsRequest($product);

        $exitCode = Artisan::call('inventory:resolve-orphan-stock-movements', [
            '--material' => [$product->id],
            '--strategy' => 'release_orphan_reservation',
            '--fail-on-unsafe' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $product = $this->shirtSFixture();
        $relativePath = 'storage/app/testing/orphan-stock-resolution.json';
        $absolutePath = base_path($relativePath);

        $payload = $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'physical_adjustment',
        ]);

        $this->assertSame('b1-6-orphan-stock-movement-resolution-v1', $payload['version']);

        $exitCode = Artisan::call('inventory:resolve-orphan-stock-movements', [
            '--material' => [$product->id],
            '--strategy' => 'physical_adjustment',
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertSame('b1-6-orphan-stock-movement-resolution-v1', json_decode((string) file_get_contents($absolutePath), true)['version']);
        @unlink($absolutePath);
    }

    public function test_after_apply_stock_integrity_audit_has_no_actionable_findings_for_resolved_material(): void
    {
        $product = $this->shirtSFixture();

        $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
            '--apply' => true,
            '--confirm-orphan-resolution' => true,
        ]);

        $exitCode = Artisan::call('inventory:audit-stock-integrity', [
            '--material' => $product->id,
            '--only-actionable' => true,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $payload['summary']['actionable_count']);
        $this->assertSame([], $payload['findings']);
    }

    public function test_dry_run_is_read_only(): void
    {
        $product = $this->shirtSFixture();
        $before = $this->snapshot();

        $this->jsonPayload([
            '--material' => [$product->id],
            '--strategy' => 'full_safe_resolution',
            '--dry-run' => true,
        ]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options): array
    {
        $exitCode = Artisan::call('inventory:resolve-orphan-stock-movements', array_merge($options, ['--json' => true]));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function shirtSFixture(): Product
    {
        $product = $this->product(['stock' => 5, 'stock_reservado' => 5, 'nome' => 'TShirt BSCN - Tamanho - S']);
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'quantity' => 10,
            'reference_type' => 'supplier_purchase',
            'reference_id' => (string) str()->uuid(),
            'notes' => 'Entrada rastreada existente',
            'created_at' => Carbon::parse('2026-03-25 14:00:00'),
        ]);
        $this->orphanMovement($product, 'entry', 15, ['created_at' => Carbon::parse('2026-03-25 14:11:06')]);
        $this->orphanMovement($product, 'reservation', 5, ['created_at' => Carbon::parse('2026-03-25 14:11:46')]);
        $this->orphanMovement($product, 'return', 5, ['created_at' => Carbon::parse('2026-03-25 14:13:03')]);
        $this->orphanMovement($product, 'exit', 26, ['created_at' => Carbon::parse('2026-03-25 15:21:06')]);

        return $product;
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

    private function logisticsRequest(Product $product): LogisticsRequest
    {
        $request = LogisticsRequest::query()->create([
            'requester_name_snapshot' => 'Equipa Teste',
            'requester_area' => 'Desportivo',
            'requester_type' => 'team',
            'status' => 'pending',
            'total_amount' => 0,
        ]);

        LogisticsRequestItem::query()->create([
            'logistics_request_id' => $request->id,
            'article_id' => $product->id,
            'article_name_snapshot' => $product->nome,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
        ]);

        return $request;
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
        ];
    }
}
