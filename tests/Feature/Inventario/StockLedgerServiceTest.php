<?php

declare(strict_types=1);

namespace Tests\Feature\Inventario;

use App\Exceptions\Inventario\InsufficientStockException;
use App\Exceptions\Inventario\InvalidStockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class StockLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00'));
        $this->ledger = app(StockLedgerService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_entry_exit_return_reservation_and_release_update_snapshots_from_ledger(): void
    {
        $product = $this->product();

        $this->ledger->registerEntry($product, 10, $this->context('purchase'));
        $this->ledger->reserve($product, 4, $this->context('request'));
        $this->ledger->releaseReservation($product, 1, $this->context('request-release'));
        $this->ledger->registerExit($product, 2, $this->context('sale'));
        $this->ledger->registerReturn($product, 1, $this->context('return'));

        $fresh = $product->fresh();

        $this->assertSame(9, (int) $fresh->stock);
        $this->assertSame(3, (int) $fresh->stock_reservado);
        $this->assertSame(6, $fresh->available_stock);
        $this->assertSame(5, StockMovement::query()->where('article_id', $product->id)->count());
    }

    public function test_convert_reservation_to_exit_releases_reserved_stock_and_decreases_physical_stock(): void
    {
        $product = $this->product();
        $this->ledger->registerEntry($product, 5, $this->context('entry'));
        $this->ledger->reserve($product, 2, $this->context('request'));

        $result = $this->ledger->convertReservationToExit($product, 2, [
            ...$this->context('delivery'),
            'idempotency_key' => 'delivery-1',
        ]);

        $this->assertSame('reservation', $result['release']->movement_type);
        $this->assertSame(-2, (int) $result['release']->quantity);
        $this->assertSame('exit', $result['exit']->movement_type);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(0, (int) $product->fresh()->stock_reservado);
    }

    public function test_idempotency_returns_existing_movement_without_duplicate(): void
    {
        $product = $this->product();
        $sourceId = (string) str()->uuid();

        $first = $this->ledger->registerEntry($product, 5, [
            'source_type' => 'purchase',
            'source_id' => $sourceId,
            'notes' => 'Teste ledger',
            'idempotency_key' => 'same-operation',
        ]);
        $second = $this->ledger->registerEntry($product, 5, [
            'source_type' => 'purchase',
            'source_id' => $sourceId,
            'notes' => 'Teste ledger',
            'idempotency_key' => 'same-operation',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockMovement::query()->where('article_id', $product->id)->count());
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_empty_or_invalid_uuid_source_id_is_rejected_and_rolls_back(): void
    {
        $product = $this->product(['stock' => 0]);

        $this->expectException(InvalidStockMovementException::class);

        try {
            $this->ledger->registerEntry($product, 3, [
                'source_type' => 'supplier_purchase',
                'source_id' => '',
            ]);
        } finally {
            $this->assertSame(0, StockMovement::query()->where('article_id', $product->id)->count());
            $this->assertSame(0, (int) $product->fresh()->stock);
        }
    }

    public function test_negative_physical_or_available_stock_is_blocked_without_explicit_exception(): void
    {
        $product = $this->product();

        $this->expectException(InsufficientStockException::class);

        $this->ledger->registerExit($product, 1, $this->context('sale'));
    }

    public function test_explicit_negative_stock_exception_requires_reason_or_notes(): void
    {
        $product = $this->product();

        $this->expectException(InvalidStockMovementException::class);

        $this->ledger->registerExit($product, 1, [
            ...$this->context('sale'),
            'allow_negative_stock' => true,
            'allow_negative_available' => true,
            'notes' => '',
        ]);
    }

    public function test_explicit_negative_stock_exception_with_reason_is_allowed(): void
    {
        $product = $this->product();

        $movement = $this->ledger->registerExit($product, 1, [
            ...$this->context('sale'),
            'allow_negative_stock' => true,
            'allow_negative_available' => true,
            'reason' => 'historical_adjustment',
        ]);

        $this->assertSame('exit', $movement->movement_type);
        $this->assertSame(-1, (int) $product->fresh()->stock);
    }

    public function test_recalculate_product_snapshot_derives_values_from_movements(): void
    {
        $product = $this->product(['stock' => 99, 'stock_reservado' => 99]);
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'quantity' => 8,
        ]);
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'reservation',
            'quantity' => 3,
        ]);

        $snapshot = $this->ledger->recalculateProductSnapshot($product);

        $this->assertSame(['physical_stock' => 8, 'reserved_stock' => 3, 'available_stock' => 5], $snapshot);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(3, (int) $product->fresh()->stock_reservado);
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
            'stock' => 0,
            'stock_reservado' => 0,
        ], $overrides));
    }

    /**
     * @return array<string,string>
     */
    private function context(string $sourceType): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => (string) str()->uuid(),
            'notes' => 'Teste ledger',
        ];
    }
}
