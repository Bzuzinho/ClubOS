<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SupplierPurchaseStockAuditService
{
    private const VERSION = 'b3-supplier-purchase-stock-audit-v1';

    private const PURCHASE_SOURCE = 'supplier_purchase';
    private const UPDATE_ENTRY_SOURCE = 'supplier_purchase_update_entry';
    private const UPDATE_REVERSAL_SOURCE = 'supplier_purchase_update_reversal';
    private const DELETE_SOURCE = 'supplier_purchase_delete';

    public function __construct(
        private readonly StockMovementSemantics $semantics = new StockMovementSemantics(),
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $schema = $this->schemaDetected();

        $purchases = $this->purchases($filters);
        $items = $this->purchaseItems($filters, $purchases);
        $products = $this->products($filters, $items);
        $movements = $this->stockMovements($filters, $purchases, $items);

        $findings = [];

        foreach ($purchases as $purchase) {
            array_push($findings, ...$this->purchaseFindings($purchase, $items, $products, $movements));
        }

        array_push($findings, ...$this->movementReferenceFindings($movements, $purchases, $items, $products));
        array_push($findings, ...$this->deletedPurchaseFindings($movements, $purchases));
        array_push($findings, ...$this->legacyUnlinkedEntryFindings($movements, $products));
        array_push($findings, ...$this->snapshotFindings($products, $movements));

        $findings = $this->uniqueFindings($findings);

        if ($findings === []) {
            $findings[] = $this->finding('info', 'supplier_purchase_stock_clean', false, 'no_action_needed_supplier_purchase_clean');
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($purchases, $items, $movements, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{purchase:?string,material:?string,only_actionable:bool}
     */
    private function filters(array $options): array
    {
        return [
            'purchase' => $this->stringOrNull($options['purchase'] ?? null),
            'material' => $this->stringOrNull($options['material'] ?? null),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        return [
            'supplier_purchase_table' => Schema::hasTable('supplier_purchases') ? 'supplier_purchases' : null,
            'supplier_purchase_item_table' => Schema::hasTable('supplier_purchase_items') ? 'supplier_purchase_items' : null,
            'stock_movement_table' => Schema::hasTable('stock_movements') ? 'stock_movements' : null,
            'purchase_item_fields' => [
                'product_column' => Schema::hasColumn('supplier_purchase_items', 'article_id') ? 'article_id' : null,
                'quantity_column' => Schema::hasColumn('supplier_purchase_items', 'quantity') ? 'quantity' : null,
                'unit_cost_column' => Schema::hasColumn('supplier_purchase_items', 'unit_cost') ? 'unit_cost' : null,
            ],
            'stock_movement_reference_fields' => [
                'source_type_column' => Schema::hasColumn('stock_movements', 'reference_type') ? 'reference_type' : null,
                'source_id_column' => Schema::hasColumn('stock_movements', 'reference_id') ? 'reference_id' : null,
                'source_id_is_uuid' => true,
            ],
            'stock_source_types' => [
                self::PURCHASE_SOURCE,
                self::UPDATE_ENTRY_SOURCE,
                self::UPDATE_REVERSAL_SOURCE,
                self::DELETE_SOURCE,
            ],
            'soft_delete_supported' => [
                'supplier_purchases' => Schema::hasColumn('supplier_purchases', 'deleted_at'),
                'supplier_purchase_items' => Schema::hasColumn('supplier_purchase_items', 'deleted_at'),
                'stock_movements' => Schema::hasColumn('stock_movements', 'deleted_at'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function purchases(array $filters): Collection
    {
        if (! Schema::hasTable('supplier_purchases')) {
            return collect();
        }

        $query = DB::table('supplier_purchases')->orderBy('created_at')->orderBy('id');

        if ($filters['purchase']) {
            $query->where('id', $filters['purchase']);
        }

        if ($filters['material'] && Schema::hasTable('supplier_purchase_items')) {
            $query->whereExists(function (Builder $exists) use ($filters): void {
                $exists->selectRaw('1')
                    ->from('supplier_purchase_items')
                    ->whereColumn('supplier_purchase_items.supplier_purchase_id', 'supplier_purchases.id')
                    ->where('supplier_purchase_items.article_id', $filters['material']);
            });
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $purchases
     * @return Collection<int,object>
     */
    private function purchaseItems(array $filters, Collection $purchases): Collection
    {
        if (! Schema::hasTable('supplier_purchase_items')) {
            return collect();
        }

        $purchaseIds = $purchases->pluck('id')->filter()->values()->all();
        if ($purchaseIds === []) {
            return collect();
        }

        $query = DB::table('supplier_purchase_items')->whereIn('supplier_purchase_id', $purchaseIds)->orderBy('created_at')->orderBy('id');

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $items
     * @return Collection<string,object>
     */
    private function products(array $filters, Collection $items): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        $ids = $items->pluck('article_id')->filter()->map('strval')->unique()->values()->all();
        if ($filters['material']) {
            $ids[] = $filters['material'];
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return collect();
        }

        return DB::table('products')->whereIn('id', $ids)->get()->keyBy(fn (object $product): string => (string) $product->id);
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $purchases
     * @param Collection<int,object> $items
     * @return Collection<int,object>
     */
    private function stockMovements(array $filters, Collection $purchases, Collection $items): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        }

        $purchaseIds = $purchases->pluck('id')->filter()->map('strval')->values()->all();
        $itemIds = $items->pluck('id')->filter()->map('strval')->values()->all();

        if ($filters['purchase']) {
            $query->where(function (Builder $query) use ($filters, $itemIds): void {
                $query->where(function (Builder $purchaseSource) use ($filters): void {
                    $purchaseSource->where('reference_type', self::PURCHASE_SOURCE)
                        ->where('reference_id', $filters['purchase']);
                });

                if ($itemIds !== []) {
                    $query->orWhere(function (Builder $itemSource) use ($itemIds): void {
                        $itemSource->whereIn('reference_type', [
                            self::UPDATE_ENTRY_SOURCE,
                            self::UPDATE_REVERSAL_SOURCE,
                            self::DELETE_SOURCE,
                        ])->whereIn('reference_id', $itemIds);
                    });
                }
            });

            return $query->get();
        }

        $query->where(function (Builder $query) use ($purchaseIds, $itemIds): void {
            $query->whereIn('reference_type', [
                self::PURCHASE_SOURCE,
                self::UPDATE_ENTRY_SOURCE,
                self::UPDATE_REVERSAL_SOURCE,
                self::DELETE_SOURCE,
            ]);

            if ($purchaseIds !== [] || $itemIds !== []) {
                $query->orWhere(function (Builder $sourceQuery) use ($purchaseIds, $itemIds): void {
                    if ($purchaseIds !== []) {
                        $sourceQuery->where('reference_type', self::PURCHASE_SOURCE)->whereIn('reference_id', $purchaseIds);
                    }

                    if ($itemIds !== []) {
                        $method = $purchaseIds !== [] ? 'orWhere' : 'where';
                        $sourceQuery->{$method}(function (Builder $itemQuery) use ($itemIds): void {
                            $itemQuery->whereIn('reference_type', [
                                self::UPDATE_ENTRY_SOURCE,
                                self::UPDATE_REVERSAL_SOURCE,
                                self::DELETE_SOURCE,
                            ])->whereIn('reference_id', $itemIds);
                        });
                    }
                });
            }

            $query->orWhere(function (Builder $legacy): void {
                $legacy->where('movement_type', 'entry')
                    ->where(function (Builder $source): void {
                        $source->whereNull('reference_type')->orWhere('reference_type', '');
                    })
                    ->where(function (Builder $notes): void {
                        $notes->where('notes', 'like', '%fornecedor%')
                            ->orWhere('notes', 'like', '%supplier%')
                            ->orWhere('notes', 'like', '%compra%');
                    });
            });
        });

        return $query->get();
    }

    /**
     * @param Collection<int,object> $items
     * @param Collection<string,object> $products
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function purchaseFindings(object $purchase, Collection $items, Collection $products, Collection $movements): array
    {
        $findings = [];
        $purchaseItems = $items->where('supplier_purchase_id', $purchase->id)->values();

        if ($purchaseItems->isEmpty()) {
            return [$this->finding('warning', 'supplier_purchase_missing_stock_entry', true, 'create_missing_supplier_purchase_stock_entry', purchase: $purchase)];
        }

        foreach ($purchaseItems as $item) {
            $product = $products->get((string) ($item->article_id ?? ''));
            $quantity = (int) ($item->quantity ?? 0);
            $expectedMovements = $this->expectedEntryMovements($purchase, $item, $movements);
            $ledgerQuantity = $expectedMovements->sum(fn (object $movement): int => (int) ($movement->quantity ?? 0));

            if ($this->blank($item->article_id ?? null) || $product === null) {
                $findings[] = $this->finding('critical', 'supplier_purchase_invalid_product', true, 'review_supplier_purchase_item_product_reference', purchase: $purchase, item: $item, quantityPurchase: $quantity, quantityLedger: $ledgerQuantity);
            }

            if ($quantity <= 0) {
                $findings[] = $this->finding('warning', 'supplier_purchase_invalid_quantity', true, 'inspect_purchase_quantity_delta', purchase: $purchase, item: $item, product: $product, quantityPurchase: $quantity, quantityLedger: $ledgerQuantity);
            }

            if ($expectedMovements->isEmpty() && $quantity > 0 && $product !== null) {
                $findings[] = $this->finding('critical', 'supplier_purchase_missing_stock_entry', true, 'create_missing_supplier_purchase_stock_entry', purchase: $purchase, item: $item, product: $product, quantityPurchase: $quantity, quantityLedger: 0);
            }

            if ($expectedMovements->count() > 1) {
                $findings[] = $this->finding('warning', 'supplier_purchase_duplicate_stock_entry', true, 'inspect_duplicate_supplier_purchase_entry', purchase: $purchase, item: $item, product: $product, movement: $expectedMovements->first(), quantityPurchase: $quantity, quantityLedger: $ledgerQuantity, extra: [
                    'movement_ids' => $expectedMovements->pluck('id')->map('strval')->values()->all(),
                    'duplicate_count' => $expectedMovements->count(),
                ]);
            }

            if ($expectedMovements->isNotEmpty() && $quantity !== $ledgerQuantity) {
                $findings[] = $this->finding('critical', 'supplier_purchase_quantity_mismatch', true, 'inspect_purchase_quantity_delta', purchase: $purchase, item: $item, product: $product, movement: $expectedMovements->first(), quantityPurchase: $quantity, quantityLedger: $ledgerQuantity, extra: [
                    'movement_ids' => $expectedMovements->pluck('id')->map('strval')->values()->all(),
                ]);
            }

            $costMismatches = $expectedMovements->filter(fn (object $movement): bool => $this->money($movement->unit_cost ?? null) !== $this->money($item->unit_cost ?? null));
            if ($costMismatches->isNotEmpty()) {
                $findings[] = $this->finding('warning', 'supplier_purchase_unit_cost_mismatch', true, 'inspect_purchase_quantity_delta', purchase: $purchase, item: $item, product: $product, movement: $costMismatches->first(), quantityPurchase: $quantity, quantityLedger: $ledgerQuantity, extra: [
                    'expected_unit_cost' => $this->money($item->unit_cost ?? null),
                    'ledger_unit_cost' => $this->money($costMismatches->first()->unit_cost ?? null),
                ]);
            }

            if ($product !== null && $quantity > 0 && $expectedMovements->count() === 1 && $quantity === $ledgerQuantity && $costMismatches->isEmpty()) {
                $findings[] = $this->finding('info', 'supplier_purchase_entry_clean', false, 'no_action_needed_supplier_purchase_clean', purchase: $purchase, item: $item, product: $product, movement: $expectedMovements->first(), quantityPurchase: $quantity, quantityLedger: $ledgerQuantity);
            }
        }

        return $findings;
    }

    /**
     * @return Collection<int,object>
     */
    private function expectedEntryMovements(object $purchase, object $item, Collection $movements): Collection
    {
        $updateEntries = $movements->filter(fn (object $movement): bool => (string) ($movement->reference_type ?? '') === self::UPDATE_ENTRY_SOURCE
            && (string) ($movement->reference_id ?? '') === (string) ($item->id ?? '')
            && (string) ($movement->article_id ?? '') === (string) ($item->article_id ?? '')
            && (string) ($movement->movement_type ?? '') === 'entry');

        if ($updateEntries->isNotEmpty()) {
            return $updateEntries->values();
        }

        return $movements->filter(fn (object $movement): bool => (string) ($movement->reference_type ?? '') === self::PURCHASE_SOURCE
            && (string) ($movement->reference_id ?? '') === (string) ($purchase->id ?? '')
            && (string) ($movement->article_id ?? '') === (string) ($item->article_id ?? '')
            && (string) ($movement->movement_type ?? '') === 'entry')
            ->values();
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $purchases
     * @param Collection<int,object> $items
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function movementReferenceFindings(Collection $movements, Collection $purchases, Collection $items, Collection $products): array
    {
        $findings = [];
        $purchaseIds = $purchases->pluck('id')->map('strval')->all();
        $itemIds = $items->pluck('id')->map('strval')->all();

        foreach ($movements as $movement) {
            $sourceType = trim((string) ($movement->reference_type ?? ''));
            if (! in_array($sourceType, [self::PURCHASE_SOURCE, self::UPDATE_ENTRY_SOURCE, self::UPDATE_REVERSAL_SOURCE, self::DELETE_SOURCE], true)) {
                continue;
            }

            $sourceId = $movement->reference_id ?? null;
            if ($sourceId === null || trim((string) $sourceId) === '' || ! Str::isUuid((string) $sourceId)) {
                $findings[] = $this->finding('warning', 'supplier_purchase_invalid_source_reference', true, 'normalize_stock_movement_reference_id_to_null_or_valid_uuid', movement: $movement, product: $products->get((string) ($movement->article_id ?? '')), quantityLedger: (int) ($movement->quantity ?? 0));
                continue;
            }

            if ($sourceType === self::PURCHASE_SOURCE && ! in_array((string) $sourceId, $purchaseIds, true)) {
                continue;
            }

            if ($sourceType === self::UPDATE_ENTRY_SOURCE && ! in_array((string) $sourceId, $itemIds, true)) {
                $findings[] = $this->finding('warning', 'supplier_purchase_invalid_source_reference', true, 'review_supplier_purchase_stock_source_reference', movement: $movement, product: $products->get((string) ($movement->article_id ?? '')), quantityLedger: (int) ($movement->quantity ?? 0));
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $purchases
     * @return list<array<string,mixed>>
     */
    private function deletedPurchaseFindings(Collection $movements, Collection $purchases): array
    {
        $findings = [];
        $purchaseIds = $purchases->pluck('id')->map('strval')->all();

        foreach ($movements->where('reference_type', self::PURCHASE_SOURCE) as $movement) {
            if (in_array((string) ($movement->reference_id ?? ''), $purchaseIds, true)) {
                continue;
            }

            if (! Str::isUuid((string) ($movement->reference_id ?? ''))) {
                continue;
            }

            $hasReversal = $movements->contains(fn (object $candidate): bool => (string) ($candidate->reference_type ?? '') === self::DELETE_SOURCE
                && (string) ($candidate->article_id ?? '') === (string) ($movement->article_id ?? '')
                && (string) ($candidate->movement_type ?? '') === 'exit'
                && abs((int) ($candidate->quantity ?? 0)) === abs((int) ($movement->quantity ?? 0)));

            if (! $hasReversal) {
                $findings[] = $this->finding('warning', 'supplier_purchase_deleted_without_reversal', true, 'inspect_deleted_purchase_reversal', movement: $movement, quantityLedger: (int) ($movement->quantity ?? 0));
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function legacyUnlinkedEntryFindings(Collection $movements, Collection $products): array
    {
        return $movements
            ->filter(fn (object $movement): bool => (string) ($movement->movement_type ?? '') === 'entry'
                && $this->blank($movement->reference_type ?? null)
                && $this->looksLikeSupplierPurchase($movement))
            ->map(fn (object $movement): array => $this->finding('info', 'supplier_purchase_legacy_unlinked_entry', false, 'classify_legacy_supplier_purchase_entry', movement: $movement, product: $products->get((string) ($movement->article_id ?? '')), quantityLedger: (int) ($movement->quantity ?? 0)))
            ->values()
            ->all();
    }

    /**
     * @param Collection<string,object> $products
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function snapshotFindings(Collection $products, Collection $movements): array
    {
        $findings = [];
        $productIds = $products->keys()->map('strval')->values()->all();
        if ($productIds === [] || ! Schema::hasTable('stock_movements')) {
            return [];
        }

        $movementsByProduct = DB::table('stock_movements')
            ->whereIn('article_id', $productIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $movement): string => (string) ($movement->article_id ?? ''));

        foreach ($products as $product) {
            $physical = 0;
            foreach ($movementsByProduct->get((string) $product->id, collect()) as $movement) {
                $physical += $this->semantics->deltas($movement)['physical'];
            }

            if ($movementsByProduct->has((string) $product->id) && (int) ($product->stock ?? 0) !== $physical) {
                $findings[] = $this->finding('warning', 'supplier_purchase_quantity_mismatch', true, 'inspect_purchase_quantity_delta', product: $product, quantityLedger: $physical, extra: [
                    'stock_current' => (int) ($product->stock ?? 0),
                    'classification_reason' => 'product_snapshot_differs_from_related_supplier_purchase_stock_movements',
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $purchases
     * @param Collection<int,object> $items
     * @param Collection<int,object> $movements
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(Collection $purchases, Collection $items, Collection $movements, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_supplier_purchases_scanned' => $purchases->count(),
            'total_purchase_items_scanned' => $items->count(),
            'total_related_stock_movements' => $movements->count(),
            'missing_stock_entry_count' => $findingsCollection->where('code', 'supplier_purchase_missing_stock_entry')->count(),
            'duplicate_stock_entry_count' => $findingsCollection->where('code', 'supplier_purchase_duplicate_stock_entry')->count(),
            'quantity_mismatch_count' => $findingsCollection->where('code', 'supplier_purchase_quantity_mismatch')->count(),
            'unit_cost_mismatch_count' => $findingsCollection->where('code', 'supplier_purchase_unit_cost_mismatch')->count(),
            'deleted_without_reversal_count' => $findingsCollection->where('code', 'supplier_purchase_deleted_without_reversal')->count(),
            'invalid_product_count' => $findingsCollection->where('code', 'supplier_purchase_invalid_product')->count(),
            'invalid_quantity_count' => $findingsCollection->where('code', 'supplier_purchase_invalid_quantity')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'supplier_purchase_invalid_source_reference')->count(),
            'legacy_unlinked_entry_count' => $findingsCollection->where('code', 'supplier_purchase_legacy_unlinked_entry')->count(),
            'total_findings' => $findingsCollection->count(),
            'critical_count' => $findingsCollection->where('severity', 'critical')->count(),
            'warning_count' => $findingsCollection->where('severity', 'warning')->count(),
            'info_count' => $findingsCollection->where('severity', 'info')->count(),
            'actionable_count' => $findingsCollection->where('actionable', true)->count(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                (string) ($finding['code'] ?? ''),
                (string) ($finding['purchase_id'] ?? ''),
                (string) ($finding['purchase_item_id'] ?? ''),
                (string) ($finding['material_id'] ?? ''),
                (string) ($finding['movement_id'] ?? ''),
                implode(',', array_map('strval', $finding['movement_ids'] ?? [])),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        bool $actionable,
        string $recommendation,
        ?object $purchase = null,
        ?object $item = null,
        ?object $product = null,
        ?object $movement = null,
        ?int $quantityPurchase = null,
        ?int $quantityLedger = null,
        array $extra = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'purchase_id' => $this->prop($purchase, 'id') ? (string) $this->prop($purchase, 'id') : null,
            'purchase_item_id' => $this->prop($item, 'id') ? (string) $this->prop($item, 'id') : null,
            'material_id' => $this->prop($product, 'id') ? (string) $this->prop($product, 'id') : ($this->prop($item, 'article_id') ? (string) $this->prop($item, 'article_id') : ($this->prop($movement, 'article_id') ? (string) $this->prop($movement, 'article_id') : null)),
            'material_name' => $this->prop($product, 'nome') ?? $this->prop($item, 'article_name_snapshot'),
            'quantity_purchase' => $quantityPurchase,
            'quantity_ledger' => $quantityLedger,
            'unit_cost_purchase' => $this->prop($item, 'unit_cost') !== null ? $this->money($this->prop($item, 'unit_cost')) : null,
            'unit_cost_ledger' => $this->prop($movement, 'unit_cost') !== null ? $this->money($this->prop($movement, 'unit_cost')) : null,
            'movement_id' => $this->prop($movement, 'id') ? (string) $this->prop($movement, 'id') : null,
            'movement_type' => $this->prop($movement, 'movement_type'),
            'source_type' => $this->prop($movement, 'reference_type'),
            'source_id' => $this->prop($movement, 'reference_id'),
            'created_at' => $this->prop($movement, 'created_at') ?? $this->prop($item, 'created_at') ?? $this->prop($purchase, 'created_at'),
            'classification_reason' => $extra['classification_reason'] ?? $code,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            ...$extra,
        ];
    }

    private function looksLikeSupplierPurchase(object $movement): bool
    {
        $notes = mb_strtolower((string) ($movement->notes ?? ''));

        return str_contains($notes, 'fornecedor') || str_contains($notes, 'supplier') || str_contains($notes, 'compra');
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function prop(?object $object, string $property): mixed
    {
        return $object !== null && property_exists($object, $property) ? $object->{$property} : null;
    }
}
