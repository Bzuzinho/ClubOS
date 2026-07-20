<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StockMismatchInspectionService
{
    private const VERSION = 'b1-1-stock-mismatch-inspection-v1';

    public function __construct(
        private readonly StockMovementSemantics $stockSemantics = new StockMovementSemantics(),
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function inspect(array $options = []): array
    {
        $filters = $this->filters($options);
        $materials = $this->materials($filters);
        $items = [];

        foreach ($materials as $material) {
            $item = $this->inspectMaterial($material);
            if ($filters['only_mismatch'] && (int) $item['physical_difference'] === 0 && (int) $item['available_difference'] === 0) {
                continue;
            }

            $items[] = $item;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $this->schemaDetected(),
            'summary' => $this->summary($items),
            'items' => $items,
            'read_only' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        return [
            'stock_fields' => [
                'stock' => Schema::hasTable('products') && Schema::hasColumn('products', 'stock'),
                'stock_reservado' => Schema::hasTable('products') && Schema::hasColumn('products', 'stock_reservado'),
                'available_stock_accessor' => true,
            ],
            'stock_field_semantics' => $this->stockSemantics->stockFieldSemantics(),
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        $materials = $options['material'] ?? [];
        if (is_string($materials)) {
            $materials = [$materials];
        }

        return [
            'material' => array_values(array_filter(array_map('strval', is_array($materials) ? $materials : []))),
            'sku' => $this->stringOrNull($options['sku'] ?? null),
            'only_mismatch' => (bool) ($options['only_mismatch'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function materials(array $filters): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        $query = DB::table('products')->orderBy('nome')->orderBy('id');

        if ($filters['material'] !== []) {
            $query->whereIn('products.id', $filters['material']);
        }

        if ($filters['sku']) {
            $sku = $filters['sku'];
            $query->where(function (Builder $query) use ($sku): void {
                if (Schema::hasColumn('products', 'codigo')) {
                    $query->where('products.codigo', $sku);
                }

                if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'sku')) {
                    $query->orWhereExists(function (Builder $exists) use ($sku): void {
                        $exists->selectRaw('1')
                            ->from('product_variants')
                            ->whereColumn('product_variants.product_id', 'products.id')
                            ->where('product_variants.sku', $sku);
                    });
                }
            });
        }

        return $query->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectMaterial(object $material): array
    {
        $movements = $this->movementsForMaterial((string) $material->id);
        $relatedItems = $this->relatedSalesOrInvoiceItems((string) $material->id);
        $movementRows = [];
        $physicalRunning = 0;
        $reservedRunning = 0;
        $previousSignature = null;
        $suspectedDuplicateCount = 0;
        $unknownTypeCount = 0;

        foreach ($movements as $movement) {
            $flags = $this->movementFlags($movement, $material, $previousSignature);
            $deltas = $this->stockSemantics->deltas($movement);
            $counted = ! in_array('movement_cancelled_or_deleted', $flags, true);

            if ($counted) {
                $physicalRunning += $deltas['physical'];
                $reservedRunning += $deltas['reserved'];
            }

            if (in_array('suspected_duplicate_movement', $flags, true)) {
                $suspectedDuplicateCount++;
            }

            if (in_array('unknown_movement_type', $flags, true)) {
                $unknownTypeCount++;
            }

            $movementRows[] = [
                'id' => (string) $movement->id,
                'date' => $this->dateValue($movement),
                'type' => $movement->movement_type,
                'type_category' => $this->movementTypeCategory((string) $movement->movement_type),
                'raw_quantity' => $this->int($movement->quantity ?? 0),
                'signed_quantity' => $deltas['physical'],
                'running_stock' => $physicalRunning,
                'physical_delta' => $deltas['physical'],
                'reserved_delta' => $deltas['reserved'],
                'available_delta' => $deltas['available'],
                'physical_running_stock' => $physicalRunning,
                'reserved_running_stock' => $reservedRunning,
                'available_running_stock' => $physicalRunning - $reservedRunning,
                'source_type' => $movement->reference_type ?? null,
                'source_id' => $movement->reference_id ?? null,
                'sale_id' => $this->saleIdForMovement($movement),
                'invoice_id' => $this->invoiceIdForMovement($movement),
                'invoice_item_id' => $this->invoiceItemIdForMovement($movement),
                'user_id' => $this->userIdForMovement($movement),
                'status' => $this->prop($movement, 'status'),
                'created_at' => $movement->created_at ?? null,
                'notes' => $movement->notes ?? null,
                'counted_in_calculation' => $counted,
                'suspicion_flags' => $flags,
            ];

            $previousSignature = $this->duplicateSignature($movement);
        }

        $relatedItems = $this->withMissingStockDecreaseFlags($relatedItems, $movements);
        $calculatedPhysicalStock = (int) collect($movementRows)->where('counted_in_calculation', true)->sum('physical_delta');
        $calculatedReservedStock = (int) collect($movementRows)->where('counted_in_calculation', true)->sum('reserved_delta');
        $calculatedAvailableStock = $calculatedPhysicalStock - $calculatedReservedStock;
        $storedStock = $this->int($material->stock ?? 0);
        $storedReservedStock = $this->int($material->stock_reservado ?? 0);
        $storedAvailableStock = $storedStock - $storedReservedStock;
        $physicalDifference = $storedStock - $calculatedPhysicalStock;
        $availableDifference = $storedAvailableStock - $calculatedAvailableStock;
        $analysis = $this->analysis($material, $physicalDifference, $availableDifference, $movementRows, $relatedItems, $suspectedDuplicateCount, $unknownTypeCount);

        return [
            'material' => $this->materialPayload($material),
            'stored_stock' => $storedStock,
            'stored_reserved_stock' => $storedReservedStock,
            'stored_available_stock' => $storedAvailableStock,
            'calculated_stock' => $calculatedPhysicalStock,
            'difference' => $physicalDifference,
            'calculated_physical_stock' => $calculatedPhysicalStock,
            'calculated_reserved_stock' => $calculatedReservedStock,
            'calculated_available_stock' => $calculatedAvailableStock,
            'physical_difference' => $physicalDifference,
            'available_difference' => $availableDifference,
            'stock_field_semantics' => $this->stockSemantics->stockFieldSemantics(),
            'movements' => $movementRows,
            'related_sales_or_invoice_items' => $relatedItems,
            'analysis' => $analysis,
            'recommended_next_action' => $analysis['recommended_next_action'],
            'read_only' => true,
        ];
    }

    /**
     * @return Collection<int,object>
     */
    private function movementsForMaterial(string $materialId): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        return DB::table('stock_movements')
            ->where('article_id', $materialId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function relatedSalesOrInvoiceItems(string $materialId): array
    {
        $items = [];

        if (Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'produto_id')) {
            $rows = DB::table('invoice_items')
                ->leftJoin('invoices', 'invoices.id', '=', 'invoice_items.fatura_id')
                ->where('invoice_items.produto_id', $materialId)
                ->select([
                    DB::raw("'invoice_item' as source"),
                    'invoice_items.id as invoice_item_id',
                    'invoice_items.fatura_id as invoice_id',
                    'invoice_items.quantidade as quantity',
                    'invoice_items.created_at',
                    'invoices.user_id',
                    'invoices.estado_pagamento as status',
                ])
                ->orderBy('invoice_items.created_at')
                ->get();

            foreach ($rows as $row) {
                $items[] = $this->relatedItemPayload($row);
            }
        }

        if (Schema::hasTable('loja_encomenda_itens') && Schema::hasTable('loja_encomendas') && Schema::hasColumn('loja_encomenda_itens', 'article_id')) {
            $rows = DB::table('loja_encomenda_itens')
                ->join('loja_encomendas', 'loja_encomendas.id', '=', 'loja_encomenda_itens.loja_encomenda_id')
                ->where('loja_encomenda_itens.article_id', $materialId)
                ->select([
                    DB::raw("'store_order_item' as source"),
                    'loja_encomenda_itens.id as sale_id',
                    'loja_encomenda_itens.loja_encomenda_id',
                    'loja_encomenda_itens.quantidade as quantity',
                    'loja_encomendas.fatura_id as invoice_id',
                    'loja_encomendas.user_id',
                    'loja_encomendas.estado as status',
                    'loja_encomenda_itens.created_at',
                ])
                ->orderBy('loja_encomenda_itens.created_at')
                ->get();

            foreach ($rows as $row) {
                $items[] = $this->relatedItemPayload($row);
            }
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'produto_id')) {
            $rows = DB::table('sales')
                ->where('produto_id', $materialId)
                ->select([
                    DB::raw("'legacy_sale' as source"),
                    'sales.id as sale_id',
                    'sales.quantidade as quantity',
                    'sales.cliente_id as user_id',
                    'sales.data as created_at',
                ])
                ->orderBy('sales.data')
                ->get();

            foreach ($rows as $row) {
                $items[] = $this->relatedItemPayload($row);
            }
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function withMissingStockDecreaseFlags(array $items, Collection $movements): array
    {
        return array_map(function (array $item) use ($movements): array {
            $quantity = $this->int($item['quantity'] ?? 0);
            $hasDecrease = $movements->contains(function (object $movement) use ($item, $quantity): bool {
                $type = (string) ($movement->movement_type ?? '');
                if (! $this->stockSemantics->isPhysicalDecrease($movement)) {
                    return false;
                }

                if ($this->int($movement->quantity ?? 0) < $quantity) {
                    return false;
                }

                $referenceType = (string) ($movement->reference_type ?? '');
                $referenceId = (string) ($movement->reference_id ?? '');

                if ($referenceType === '' && $referenceId === '') {
                    return true;
                }

                return in_array($referenceType, ['invoice', 'invoice_item', 'sale', 'legacy_sale', 'store_order', 'store_order_item', 'loja_encomenda', 'loja_encomenda_item'], true)
                    && in_array($referenceId, array_filter([
                        $item['invoice_id'] ?? null,
                        $item['invoice_item_id'] ?? null,
                        $item['sale_id'] ?? null,
                        $item['loja_encomenda_id'] ?? null,
                    ]), true);
            });

            $item['suspicion_flags'] = $hasDecrease ? [] : ['missing_sale_stock_decrease'];

            return $item;
        }, $items);
    }

    /**
     * @return list<string>
     */
    private function movementFlags(object $movement, object $material, ?string $previousSignature): array
    {
        $flags = [];
        $type = (string) ($movement->movement_type ?? '');
        $quantity = $this->int($movement->quantity ?? 0);

        if (! in_array($type, ['entry', 'return', 'cancel_reservation', 'exit', 'reservation', 'deliver_reservation', 'adjustment', 'ajuste', 'correction', 'correcao', 'import', 'importacao', 'sale', 'venda'], true)) {
            $flags[] = 'unknown_movement_type';
        }

        if ($quantity < 0 && in_array($type, ['entry', 'return', 'cancel_reservation'], true)) {
            $flags[] = 'quantity_sign_incoherent';
        }

        if ($quantity <= 0 && in_array($type, ['exit', 'deliver_reservation'], true)) {
            $flags[] = 'quantity_sign_incoherent';
        }

        if ($previousSignature !== null && $previousSignature === $this->duplicateSignature($movement)) {
            $flags[] = 'suspected_duplicate_movement';
        }

        if (in_array($type, ['adjustment', 'ajuste', 'correction', 'correcao', 'import', 'importacao'], true)) {
            $flags[] = 'possible_initial_or_correction_movement';
        }

        if (in_array($type, ['cancelled', 'canceled', 'deleted'], true) || $this->prop($movement, 'deleted_at') !== null || in_array((string) $this->prop($movement, 'status'), ['cancelled', 'canceled', 'deleted'], true)) {
            $flags[] = 'movement_cancelled_or_deleted';
        }

        if ($this->blank($movement->reference_type ?? null) && $this->blank($movement->reference_id ?? null)) {
            if ($this->stockSemantics->isPhysicalMovement($movement)) {
                $flags[] = 'orphan_physical_stock_movement';
            } elseif ($this->stockSemantics->isReservationMovement($movement)) {
                $flags[] = 'orphan_reservation_movement';
            }
        }

        if (($movement->created_at ?? null) && ($material->created_at ?? null) && Carbon::parse((string) $movement->created_at)->lt(Carbon::parse((string) $material->created_at)->subMinute())) {
            $flags[] = 'movement_before_material_creation';
        }

        return array_values(array_unique($flags));
    }

    private function movementTypeCategory(string $type): string
    {
        return match ($type) {
            'entry' => 'entrada',
            'exit' => 'saida',
            'reservation' => 'reserva',
            'adjustment', 'ajuste' => 'ajuste',
            'sale', 'venda', 'deliver_reservation' => 'venda',
            'return', 'cancel_reservation' => 'devolucao',
            'correction', 'correcao' => 'correcao',
            'import', 'importacao' => 'importacao',
            default => 'desconhecido',
        };
    }

    /**
     * @param list<array<string,mixed>> $movements
     * @param list<array<string,mixed>> $relatedItems
     * @return array<string,mixed>
     */
    private function analysis(object $material, int $physicalDifference, int $availableDifference, array $movements, array $relatedItems, int $duplicateCount, int $unknownTypeCount): array
    {
        $flags = collect($movements)->pluck('suspicion_flags')->flatten()->merge(
            collect($relatedItems)->pluck('suspicion_flags')->flatten()
        )->unique()->values()->all();

        $missingSaleCount = collect($relatedItems)->filter(fn (array $item): bool => in_array('missing_sale_stock_decrease', $item['suspicion_flags'] ?? [], true))->count();
        $orphanPhysicalCount = collect($movements)->filter(fn (array $movement): bool => in_array('orphan_physical_stock_movement', $movement['suspicion_flags'] ?? [], true))->count();
        $hasInitialOrEntry = collect($movements)->contains(fn (array $movement): bool => in_array($movement['type'], ['entry', 'import', 'importacao', 'adjustment', 'ajuste', 'correction', 'correcao'], true));
        $actionable = $physicalDifference !== 0 || $duplicateCount > 0 || $unknownTypeCount > 0 || $missingSaleCount > 0 || $orphanPhysicalCount > 0;

        $recommendation = 'no_action_needed_physical_stock_matches';
        if ($missingSaleCount > 0) {
            $recommendation = 'create_missing_sale_stock_decrease_after_review';
        } elseif ($orphanPhysicalCount > 0) {
            $recommendation = 'inspect_orphan_physical_stock_movement';
        } elseif ($duplicateCount > 0) {
            $recommendation = 'mark_movement_as_ignored_or_cancelled';
        } elseif ($unknownTypeCount > 0) {
            $recommendation = 'manual_review_required';
        } elseif ($physicalDifference > 0) {
            $recommendation = $hasInitialOrEntry ? 'create_stock_correction_movement' : 'create_initial_stock_adjustment';
        } elseif ($physicalDifference < 0) {
            $recommendation = 'recalculate_stored_stock_from_movements';
        }

        return [
            'mismatch' => $physicalDifference !== 0,
            'physical_stock_mismatch' => $physicalDifference !== 0,
            'available_stock_mismatch' => $availableDifference !== 0,
            'suspicion_flags' => $flags,
            'suspected_duplicate_movement_count' => $duplicateCount,
            'unknown_type_movement_count' => $unknownTypeCount,
            'orphan_physical_stock_movement_count' => $orphanPhysicalCount,
            'possible_missing_initial_stock' => $physicalDifference > 0 && ! $hasInitialOrEntry,
            'possible_missing_sale_movement_count' => $missingSaleCount,
            'actionable' => $actionable,
            'recommended_next_action' => $recommendation,
            'explanation' => $this->explanation($material, $physicalDifference, $recommendation),
        ];
    }

    private function explanation(object $material, int $difference, string $recommendation): string
    {
        if ($difference === 0) {
            return 'stored_stock_matches_calculated_physical_stock_from_counted_movements';
        }

        return sprintf(
            'stored_stock_differs_from_physical_stock_movements_by_%d_for_material_%s_recommendation_%s',
            $difference,
            (string) ($material->id ?? ''),
            $recommendation,
        );
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $analyses = collect($items)->pluck('analysis');

        return [
            'total_materials_inspected' => count($items),
            'mismatch_count' => collect($items)->filter(fn (array $item): bool => (int) $item['physical_difference'] !== 0)->count(),
            'physical_stock_mismatch_count' => collect($items)->filter(fn (array $item): bool => (int) $item['physical_difference'] !== 0)->count(),
            'available_stock_mismatch_count' => collect($items)->filter(fn (array $item): bool => (int) $item['available_difference'] !== 0)->count(),
            'clean_count' => collect($items)->filter(fn (array $item): bool => (int) $item['physical_difference'] === 0)->count(),
            'total_movements_inspected' => collect($items)->sum(fn (array $item): int => count($item['movements'])),
            'suspected_duplicate_movement_count' => $analyses->sum('suspected_duplicate_movement_count'),
            'unknown_type_movement_count' => $analyses->sum('unknown_type_movement_count'),
            'orphan_physical_stock_movement_count' => $analyses->sum('orphan_physical_stock_movement_count'),
            'possible_missing_initial_stock_count' => $analyses->filter(fn (array $analysis): bool => (bool) $analysis['possible_missing_initial_stock'])->count(),
            'possible_missing_sale_movement_count' => $analyses->sum('possible_missing_sale_movement_count'),
            'actionable_count' => $analyses->filter(fn (array $analysis): bool => (bool) $analysis['actionable'])->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function materialPayload(object $material): array
    {
        return [
            'id' => (string) $material->id,
            'name' => $material->nome ?? null,
            'sku' => $material->codigo ?? null,
            'category_id' => $material->categoria_id ?? $material->categoria ?? null,
            'location_id' => $material->area_armazenamento ?? null,
            'created_at' => $material->created_at ?? null,
            'variants' => $this->variantsForMaterial((string) $material->id),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function variantsForMaterial(string $materialId): array
    {
        if (! Schema::hasTable('product_variants')) {
            return [];
        }

        return DB::table('product_variants')
            ->where('product_id', $materialId)
            ->orderBy('sku')
            ->get()
            ->map(fn (object $variant): array => [
                'id' => (string) $variant->id,
                'name' => $variant->nome ?? null,
                'sku' => $variant->sku ?? null,
                'stock' => $this->int($variant->stock ?? 0),
                'active' => (bool) ($variant->ativo ?? true),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function relatedItemPayload(object $row): array
    {
        return [
            'source' => $row->source ?? null,
            'sale_id' => $this->prop($row, 'sale_id'),
            'loja_encomenda_id' => $this->prop($row, 'loja_encomenda_id'),
            'invoice_id' => $this->prop($row, 'invoice_id'),
            'invoice_item_id' => $this->prop($row, 'invoice_item_id'),
            'user_id' => $this->prop($row, 'user_id'),
            'quantity' => $this->int($row->quantity ?? 0),
            'status' => $this->prop($row, 'status'),
            'created_at' => $this->prop($row, 'created_at'),
            'suspicion_flags' => [],
        ];
    }

    private function duplicateSignature(object $movement): string
    {
        return implode('|', [
            (string) ($movement->movement_type ?? ''),
            (string) $this->int($movement->quantity ?? 0),
            (string) ($movement->reference_type ?? ''),
            (string) ($movement->reference_id ?? ''),
            Carbon::parse((string) ($movement->created_at ?? Carbon::now()))->format('Y-m-d H:i:s'),
        ]);
    }

    private function dateValue(object $movement): ?string
    {
        return $movement->created_at ? Carbon::parse((string) $movement->created_at)->toDateString() : null;
    }

    private function saleIdForMovement(object $movement): ?string
    {
        return in_array((string) ($movement->reference_type ?? ''), ['sale', 'legacy_sale', 'store_order', 'store_order_item', 'loja_encomenda', 'loja_encomenda_item'], true)
            ? $this->stringOrNull($movement->reference_id ?? null)
            : null;
    }

    private function invoiceIdForMovement(object $movement): ?string
    {
        return (string) ($movement->reference_type ?? '') === 'invoice'
            ? $this->stringOrNull($movement->reference_id ?? null)
            : null;
    }

    private function invoiceItemIdForMovement(object $movement): ?string
    {
        return (string) ($movement->reference_type ?? '') === 'invoice_item'
            ? $this->stringOrNull($movement->reference_id ?? null)
            : null;
    }

    private function userIdForMovement(object $movement): ?string
    {
        if (! $movement->reference_type || ! $movement->reference_id) {
            return null;
        }

        if ((string) $movement->reference_type === 'invoice' && Schema::hasTable('invoices')) {
            return DB::table('invoices')->where('id', $movement->reference_id)->value('user_id');
        }

        if ((string) $movement->reference_type === 'invoice_item' && Schema::hasTable('invoice_items') && Schema::hasTable('invoices')) {
            return DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.fatura_id')
                ->where('invoice_items.id', $movement->reference_id)
                ->value('invoices.user_id');
        }

        return null;
    }

    private function int(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function prop(?object $object, string $property): mixed
    {
        return $object !== null && property_exists($object, $property) ? $object->{$property} : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
