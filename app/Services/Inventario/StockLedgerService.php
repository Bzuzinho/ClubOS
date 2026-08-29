<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Exceptions\Inventario\InsufficientStockException;
use App\Exceptions\Inventario\InvalidStockMovementException;
use App\Exceptions\Inventario\StockSnapshotMismatchException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockLedgerService
{
    public function __construct(
        private readonly StockMovementSemantics $semantics = new StockMovementSemantics(),
    ) {
    }

    /**
     * @param array<string,mixed> $context
     */
    public function registerEntry(Product $product, int|float $quantity, array $context = []): StockMovement
    {
        return $this->registerPhysicalMovement($product, 'entry', $quantity, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function registerExit(Product $product, int|float $quantity, array $context = []): StockMovement
    {
        return $this->registerPhysicalMovement($product, 'exit', $quantity, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function registerReturn(Product $product, int|float $quantity, array $context = []): StockMovement
    {
        return $this->registerPhysicalMovement($product, 'return', $quantity, $context);
    }

    /**
     * Ajusta o snapshot agregado para um valor absoluto através do ledger.
     *
     * @param array<string,mixed> $context
     */
    public function adjustProductToStock(Product $product, int $desiredStock, array $context = []): ?StockMovement
    {
        if ($desiredStock < 0) {
            throw new InvalidStockMovementException('desired_stock_must_not_be_negative');
        }

        return DB::transaction(function () use ($product, $desiredStock, $context): ?StockMovement {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOpeningSnapshotMovement($locked);
            $current = $this->calculatedSnapshot((string) $locked->id)['physical_stock'];
            $delta = $desiredStock - $current;

            if ($delta === 0) {
                return null;
            }

            return $this->register($locked, 'adjustment', $delta, $context);
        });
    }

    /**
     * Ajusta uma variante e o agregado do produto na mesma transação.
     *
     * @param array<string,mixed> $context
     */
    public function adjustVariantToStock(Product $product, ProductVariant $variant, int $desiredStock, array $context = []): ?StockMovement
    {
        if ($desiredStock < 0) {
            throw new InvalidStockMovementException('desired_variant_stock_must_not_be_negative');
        }

        return DB::transaction(function () use ($product, $variant, $desiredStock, $context): ?StockMovement {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $lockedVariant = ProductVariant::query()
                ->whereKey($variant->getKey())
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureOpeningSnapshotMovement($lockedProduct);
            $this->ensureVariantOpeningSnapshotMovement($lockedProduct, $lockedVariant);
            $current = $this->calculatedVariantSnapshot((string) $lockedVariant->id)['physical_stock'];
            $delta = $desiredStock - $current;

            if ($delta === 0) {
                return null;
            }

            return $this->register($lockedProduct, 'adjustment', $delta, [
                ...$context,
                'product_variant_id' => (string) $lockedVariant->id,
            ]);
        });
    }

    /**
     * @param array<string,mixed> $context
     */
    public function reserve(Product $product, int|float $quantity, array $context = []): StockMovement
    {
        return $this->registerReservationMovement($product, 'reservation', $quantity, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function releaseReservation(Product $product, int|float $quantity, array $context = []): StockMovement
    {
        return $this->registerReservationMovement($product, 'reservation', -$this->positiveQuantity($quantity), $context);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{release:StockMovement,exit:StockMovement}
     */
    public function convertReservationToExit(Product $product, int|float $quantity, array $context = []): array
    {
        return DB::transaction(function () use ($product, $quantity, $context): array {
            $release = $this->releaseReservation($product, $quantity, [
                ...$context,
                'idempotency_key' => $this->suffixIdempotencyKey($context, 'release'),
            ]);
            $exit = $this->registerExit($product, $quantity, [
                ...$context,
                'idempotency_key' => $this->suffixIdempotencyKey($context, 'exit'),
            ]);

            return ['release' => $release, 'exit' => $exit];
        });
    }

    /**
     * @return array{physical_stock:int,reserved_stock:int,available_stock:int}
     */
    public function recalculateProductSnapshot(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $snapshot = $this->calculatedSnapshot((string) $locked->id);

            $locked->forceFill([
                'stock' => $snapshot['physical_stock'],
                'stock_reservado' => $snapshot['reserved_stock'],
            ])->save();

            return $snapshot;
        });
    }

    public function assertCanReserve(Product $product, int|float $quantity, array $context = []): void
    {
        $quantity = $this->positiveQuantity($quantity);
        $snapshot = $this->operationalSnapshot($product);

        if ($snapshot['available_stock'] < $quantity && ! (bool) ($context['allow_negative_available'] ?? false)) {
            throw new InsufficientStockException('insufficient_available_stock_to_reserve');
        }
    }

    public function assertCanExit(Product $product, int|float $quantity, array $context = []): void
    {
        $quantity = $this->positiveQuantity($quantity);
        $snapshot = $this->operationalSnapshot($product);

        if ($snapshot['physical_stock'] < $quantity && ! (bool) ($context['allow_negative_stock'] ?? false)) {
            throw new InsufficientStockException('insufficient_physical_stock_to_exit');
        }

        if ($snapshot['available_stock'] < $quantity && ! (bool) ($context['allow_negative_available'] ?? false)) {
            throw new InsufficientStockException('insufficient_available_stock_to_exit');
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function registerPhysicalMovement(Product $product, string $type, int|float $quantity, array $context): StockMovement
    {
        $quantity = $this->positiveQuantity($quantity);

        return $this->register($product, $type, $quantity, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function registerReservationMovement(Product $product, string $type, int|float $quantity, array $context): StockMovement
    {
        $quantity = (int) $quantity;
        if ($quantity === 0) {
            throw new InvalidStockMovementException('stock_movement_quantity_must_not_be_zero');
        }

        return $this->register($product, $type, $quantity, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function register(Product $product, string $type, int $quantity, array $context): StockMovement
    {
        return DB::transaction(function () use ($product, $type, $quantity, $context): StockMovement {
            $this->validateContext($context);

            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOpeningSnapshotMovement($locked);

            $variant = $this->lockedVariant($locked, $context);
            if ($variant) {
                $this->ensureVariantOpeningSnapshotMovement($locked, $variant);
            }

            $existing = $this->existingMovement($locked, $type, $quantity, $context);
            if ($existing) {
                return $existing;
            }

            $movementPreview = (object) ['movement_type' => $type, 'quantity' => $quantity];
            $deltas = $this->semantics->deltas($movementPreview);
            if ($deltas['physical'] === 0 && $deltas['reserved'] === 0) {
                throw new InvalidStockMovementException('stock_movement_type_has_no_stock_impact');
            }

            $before = $this->calculatedSnapshot((string) $locked->id);
            $afterPhysical = $before['physical_stock'] + $deltas['physical'];
            $afterReserved = $before['reserved_stock'] + $deltas['reserved'];
            $afterAvailable = $afterPhysical - $afterReserved;

            $this->guardNegativeSnapshot($afterPhysical, $afterAvailable, $context);

            $variantAfter = null;
            if ($variant) {
                $variantBefore = $this->calculatedVariantSnapshot((string) $variant->id);
                $variantAfter = [
                    'physical_stock' => $variantBefore['physical_stock'] + $deltas['physical'],
                    'reserved_stock' => $variantBefore['reserved_stock'] + $deltas['reserved'],
                ];
                $this->guardNegativeSnapshot(
                    $variantAfter['physical_stock'],
                    $variantAfter['physical_stock'] - $variantAfter['reserved_stock'],
                    $context,
                );
            }

            $movement = new StockMovement();
            $movement->forceFill([
                'article_id' => $locked->id,
                'product_variant_id' => $variant?->id,
                'movement_type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $context['unit_cost'] ?? null,
                'reference_type' => $this->stringOrNull($context['source_type'] ?? null),
                'reference_id' => $this->sourceId($context),
                'notes' => $this->notesWithIdempotency($context),
                'created_by' => $this->sourceId($context, 'created_by'),
                'created_at' => $this->dateOrNow($context['occurred_at'] ?? null),
                'updated_at' => Carbon::now(),
            ])->save();

            if ($variant && $variantAfter) {
                $variant->forceFill([
                    'stock' => $variantAfter['physical_stock'],
                    'stock_reservado' => $variantAfter['reserved_stock'],
                ])->save();
            }

            $locked->forceFill([
                'stock' => $afterPhysical,
                'stock_reservado' => $afterReserved,
            ])->save();

            $fresh = $locked->fresh();
            if ((int) $fresh->stock !== $afterPhysical || (int) $fresh->stock_reservado !== $afterReserved) {
                throw new StockSnapshotMismatchException('stock_snapshot_mismatch_after_ledger_operation');
            }

            if ($variant && $variantAfter) {
                $freshVariant = $variant->fresh();
                if ((int) $freshVariant->stock !== $variantAfter['physical_stock']
                    || (int) $freshVariant->stock_reservado !== $variantAfter['reserved_stock']) {
                    throw new StockSnapshotMismatchException('variant_stock_snapshot_mismatch_after_ledger_operation');
                }
            }

            return $movement;
        });
    }

    /**
     * @param array<string,mixed> $context
     */
    private function existingMovement(Product $product, string $type, int $quantity, array $context): ?StockMovement
    {
        $sourceType = $this->stringOrNull($context['source_type'] ?? null);
        $sourceId = $this->sourceId($context);
        $idempotencyKey = $this->stringOrNull($context['idempotency_key'] ?? null);

        if ($sourceType === null && $sourceId === null && $idempotencyKey === null) {
            return null;
        }

        $query = StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', $type)
            ->where('quantity', $quantity);

        $variantId = $this->sourceId($context, 'product_variant_id');
        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        if ($sourceType !== null) {
            $query->where('reference_type', $sourceType);
        } else {
            $query->whereNull('reference_type');
        }

        if ($sourceId !== null) {
            $query->where('reference_id', $sourceId);
        } else {
            $query->whereNull('reference_id');
        }

        if ($idempotencyKey !== null) {
            $query->where('notes', 'like', '%idempotency_key:'.$idempotencyKey.'%');
        }

        return $query->orderBy('created_at')->first();
    }

    /**
     * @param array<string,mixed> $context
     */
    private function validateContext(array $context): void
    {
        foreach (['source_id', 'created_by', 'product_variant_id'] as $field) {
            $value = $context[$field] ?? null;
            if ($value === null) {
                continue;
            }

            if (! is_string($value) || trim($value) === '' || ! Str::isUuid(trim($value))) {
                throw new InvalidStockMovementException($field.'_must_be_null_or_valid_uuid');
            }
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function guardNegativeSnapshot(int $physicalStock, int $availableStock, array $context): void
    {
        if ($physicalStock < 0 && ! (bool) ($context['allow_negative_stock'] ?? false)) {
            throw new InsufficientStockException('negative_physical_stock_not_allowed');
        }

        if ($availableStock < 0 && ! (bool) ($context['allow_negative_available'] ?? false)) {
            throw new InsufficientStockException('negative_available_stock_not_allowed');
        }

        if (($physicalStock < 0 || $availableStock < 0) && ! filled($context['reason'] ?? null) && ! filled($context['notes'] ?? null)) {
            throw new InvalidStockMovementException('negative_stock_exception_requires_reason_or_notes');
        }
    }

    private function positiveQuantity(int|float $quantity): int
    {
        $quantity = (int) $quantity;
        if ($quantity <= 0) {
            throw new InvalidStockMovementException('stock_movement_quantity_must_be_positive');
        }

        return $quantity;
    }

    /**
     * @return array{physical_stock:int,reserved_stock:int,available_stock:int}
     */
    private function operationalSnapshot(Product $product): array
    {
        if (StockMovement::query()->where('article_id', $product->id)->exists()) {
            return $this->calculatedSnapshot((string) $product->id);
        }

        $physical = (int) ($product->stock ?? 0);
        $reserved = (int) ($product->stock_reservado ?? 0);

        return [
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
            'available_stock' => $physical - $reserved,
        ];
    }

    private function ensureOpeningSnapshotMovement(Product $product): void
    {
        if (StockMovement::query()->where('article_id', $product->id)->exists()) {
            return;
        }

        $physical = (int) ($product->stock ?? 0);
        $reserved = (int) ($product->stock_reservado ?? 0);
        if ($physical === 0 && $reserved === 0) {
            return;
        }

        if ($physical !== 0) {
            $movement = new StockMovement();
            $movement->forceFill([
                'article_id' => $product->id,
                'movement_type' => 'adjustment',
                'quantity' => $physical,
                'reference_type' => 'ledger_opening_snapshot',
                'reference_id' => null,
                'notes' => 'Snapshot inicial de stock antes do ledger B2',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])->save();
        }

        if ($reserved !== 0) {
            $movement = new StockMovement();
            $movement->forceFill([
                'article_id' => $product->id,
                'movement_type' => 'reservation',
                'quantity' => $reserved,
                'reference_type' => 'ledger_opening_snapshot',
                'reference_id' => null,
                'notes' => 'Snapshot inicial de stock reservado antes do ledger B2',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])->save();
        }
    }

    private function ensureVariantOpeningSnapshotMovement(Product $product, ProductVariant $variant): void
    {
        if (StockMovement::query()->where('product_variant_id', $variant->id)->exists()) {
            return;
        }

        $physical = (int) ($variant->stock ?? 0);
        $reserved = (int) ($variant->stock_reservado ?? 0);

        if ($physical !== 0) {
            $movement = new StockMovement();
            $movement->forceFill([
                'article_id' => $product->id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'variant_opening_snapshot',
                'quantity' => $physical,
                'reference_type' => 'variant_ledger_opening_snapshot',
                'reference_id' => $variant->id,
                'notes' => 'Snapshot inicial da variante antes do ledger H2.4b [action_key:physical]',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])->save();
        }

        if ($reserved !== 0) {
            $movement = new StockMovement();
            $movement->forceFill([
                'article_id' => $product->id,
                'product_variant_id' => $variant->id,
                'movement_type' => 'variant_opening_reservation',
                'quantity' => $reserved,
                'reference_type' => 'variant_ledger_opening_snapshot',
                'reference_id' => $variant->id,
                'notes' => 'Snapshot inicial reservado da variante antes do ledger H2.4b [action_key:reserved]',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])->save();
        }
    }

    /**
     * @return array{physical_stock:int,reserved_stock:int,available_stock:int}
     */
    private function calculatedSnapshot(string $productId): array
    {
        $physical = 0;
        $reserved = 0;

        StockMovement::query()
            ->where('article_id', $productId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (StockMovement $movement) use (&$physical, &$reserved): void {
                $deltas = $this->semantics->deltas($movement);
                $physical += $deltas['physical'];
                $reserved += $deltas['reserved'];
            });

        return [
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
            'available_stock' => $physical - $reserved,
        ];
    }

    /**
     * @return array{physical_stock:int,reserved_stock:int,available_stock:int}
     */
    private function calculatedVariantSnapshot(string $variantId): array
    {
        $physical = 0;
        $reserved = 0;

        StockMovement::query()
            ->where('product_variant_id', $variantId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (StockMovement $movement) use (&$physical, &$reserved): void {
                if ($movement->movement_type === 'variant_opening_snapshot') {
                    $physical += (int) $movement->quantity;

                    return;
                }

                if ($movement->movement_type === 'variant_opening_reservation') {
                    $reserved += (int) $movement->quantity;

                    return;
                }

                $deltas = $this->semantics->deltas($movement);
                $physical += $deltas['physical'];
                $reserved += $deltas['reserved'];
            });

        return [
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
            'available_stock' => $physical - $reserved,
        ];
    }

    /** @param array<string,mixed> $context */
    private function lockedVariant(Product $product, array $context): ?ProductVariant
    {
        $variantId = $this->sourceId($context, 'product_variant_id');
        if ($variantId === null) {
            return null;
        }

        return ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param array<string,mixed> $context
     */
    private function sourceId(array $context, string $field = 'source_id'): ?string
    {
        $value = $context[$field] ?? null;
        if ($value === null) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function notesWithIdempotency(array $context): ?string
    {
        $notes = $this->stringOrNull($context['notes'] ?? null);
        $idempotencyKey = $this->stringOrNull($context['idempotency_key'] ?? null);

        if ($idempotencyKey === null) {
            return $notes;
        }

        $suffix = 'idempotency_key:'.$idempotencyKey;

        return $notes === null ? $suffix : $notes.' ['.$suffix.']';
    }

    private function dateOrNow(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse($value ?: Carbon::now());
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function suffixIdempotencyKey(array $context, string $suffix): ?string
    {
        $key = $this->stringOrNull($context['idempotency_key'] ?? null);

        return $key === null ? null : $key.'-'.$suffix;
    }
}
