<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OrphanStockMovementInspectionService
{
    private const VERSION = 'b1-5-orphan-stock-movement-inspection-v1';
    private const WINDOW_MINUTES = 10;

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
        $items = $this->orphanMovements($filters)
            ->map(fn (object $movement): array => $this->inspectMovement($movement))
            ->when($filters['only_actionable'], fn (Collection $items): Collection => $items->filter(fn (array $item): bool => (bool) ($item['actionable'] ?? false)))
            ->values()
            ->all();

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $this->summary($items),
            'items' => $items,
            'read_only' => true,
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

        $movements = $options['movement'] ?? [];
        if (is_string($movements)) {
            $movements = [$movements];
        }

        return [
            'material' => array_values(array_filter(array_map('strval', is_array($materials) ? $materials : []))),
            'movement' => array_values(array_filter(array_map('strval', is_array($movements) ? $movements : []))),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function orphanMovements(array $filters): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')
            ->where(function (Builder $query): void {
                $query->whereNull('reference_type')->orWhere('reference_type', '');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('reference_id')->orWhere('reference_id', '');
            })
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['material'] !== []) {
            $query->whereIn('article_id', $filters['material']);
        }

        if ($filters['movement'] !== []) {
            $query->whereIn('id', $filters['movement']);
        }

        return $query->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectMovement(object $movement): array
    {
        $material = $this->material((string) $movement->article_id);
        $deltas = $this->stockSemantics->deltas($movement);
        $nearbyContext = $this->nearbyContext($movement);
        $candidateLinks = $this->candidateLinks($movement);
        [$classification, $recommendation, $actionable] = $this->classify($movement, $candidateLinks);

        return [
            'movement' => $this->movementPayload($movement),
            'material' => $this->materialPayload($material, (string) $movement->article_id),
            'impact' => $this->impact($movement, $deltas),
            'nearby_context' => $nearbyContext,
            'candidate_links' => $candidateLinks,
            'classification' => $classification,
            'recommended_next_action' => $recommendation,
            'actionable' => $actionable,
            'read_only' => true,
        ];
    }

    private function material(string $materialId): ?object
    {
        if (! Schema::hasTable('products')) {
            return null;
        }

        return DB::table('products')->where('id', $materialId)->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function movementPayload(object $movement): array
    {
        return [
            'id' => (string) $movement->id,
            'article_id' => (string) $movement->article_id,
            'type' => (string) $movement->movement_type,
            'quantity' => $this->int($movement->quantity ?? 0),
            'unit_cost' => $movement->unit_cost ?? null,
            'source_type' => $movement->reference_type ?? null,
            'source_id' => $movement->reference_id ?? null,
            'notes' => $movement->notes ?? null,
            'created_by' => $movement->created_by ?? null,
            'created_at' => $movement->created_at ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function materialPayload(?object $material, string $materialId): array
    {
        return [
            'id' => $material ? (string) $material->id : $materialId,
            'name' => $material->nome ?? null,
            'stored_stock' => $this->int($material->stock ?? 0),
            'stored_reserved_stock' => $this->int($material->stock_reservado ?? 0),
            'stored_available_stock' => $this->int($material->stock ?? 0) - $this->int($material->stock_reservado ?? 0),
        ];
    }

    /**
     * @param array{physical:int,reserved:int,available:int} $deltas
     * @return array<string,mixed>
     */
    private function impact(object $movement, array $deltas): array
    {
        $stock = $this->stockSnapshot((string) $movement->article_id);
        $physicalAfterExclusion = $stock['calculated_physical_stock'] - $deltas['physical'];
        $reservedAfterExclusion = $stock['calculated_reserved_stock'] - $deltas['reserved'];
        $availableAfterExclusion = $physicalAfterExclusion - $reservedAfterExclusion;

        return [
            'physical_delta' => $deltas['physical'],
            'reserved_delta' => $deltas['reserved'],
            'available_delta' => $deltas['available'],
            'impact_if_excluded' => [
                'physical_delta' => -$deltas['physical'],
                'reserved_delta' => -$deltas['reserved'],
                'available_delta' => -$deltas['available'],
            ],
            'stock_after_if_excluded' => [
                'calculated_physical_stock' => $physicalAfterExclusion,
                'calculated_reserved_stock' => $reservedAfterExclusion,
                'calculated_available_stock' => $availableAfterExclusion,
                'physical_difference' => $stock['stored_stock'] - $physicalAfterExclusion,
                'available_difference' => ($stock['stored_stock'] - $stock['stored_reserved_stock']) - $availableAfterExclusion,
            ],
            'material_orphan_physical_net_impact' => $stock['orphan_physical_net_impact'],
            'material_orphan_reserved_net_impact' => $stock['orphan_reserved_net_impact'],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function stockSnapshot(string $materialId): array
    {
        $material = $this->material($materialId);
        $movements = DB::table('stock_movements')->where('article_id', $materialId)->get();
        $physical = 0;
        $reserved = 0;
        $orphanPhysical = 0;
        $orphanReserved = 0;

        foreach ($movements as $movement) {
            $deltas = $this->stockSemantics->deltas($movement);
            $physical += $deltas['physical'];
            $reserved += $deltas['reserved'];

            $isOrphan = $this->blank($movement->reference_type ?? null) && $this->blank($movement->reference_id ?? null);
            if ($isOrphan && $this->stockSemantics->isPhysicalMovement($movement)) {
                $orphanPhysical += $deltas['physical'];
            }
            if ($isOrphan && $this->stockSemantics->isReservationMovement($movement)) {
                $orphanReserved += $deltas['reserved'];
            }
        }

        return [
            'stored_stock' => $this->int($material->stock ?? 0),
            'stored_reserved_stock' => $this->int($material->stock_reservado ?? 0),
            'calculated_physical_stock' => $physical,
            'calculated_reserved_stock' => $reserved,
            'orphan_physical_net_impact' => $orphanPhysical,
            'orphan_reserved_net_impact' => $orphanReserved,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nearbyContext(object $movement): array
    {
        $start = Carbon::parse((string) $movement->created_at)->subMinutes(self::WINDOW_MINUTES);
        $end = Carbon::parse((string) $movement->created_at)->addMinutes(self::WINDOW_MINUTES);

        return [
            'window_minutes' => self::WINDOW_MINUTES,
            'same_material_movements' => $this->nearbyStockMovements($movement, $start, $end),
            'same_type_quantity_movements' => $this->sameTypeQuantityMovements($movement),
            'actor' => $this->actor($movement->created_by ?? null),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function nearbyStockMovements(object $movement, Carbon $start, Carbon $end): array
    {
        return DB::table('stock_movements')
            ->where('article_id', $movement->article_id)
            ->where('id', '!=', $movement->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $row): array => $this->movementPayload($row))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sameTypeQuantityMovements(object $movement): array
    {
        return DB::table('stock_movements')
            ->where('id', '!=', $movement->id)
            ->where('article_id', $movement->article_id)
            ->where('movement_type', $movement->movement_type)
            ->where('quantity', $movement->quantity)
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => $this->movementPayload($row))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function actor(mixed $createdBy): ?array
    {
        if ($this->blank($createdBy) || ! Schema::hasTable('users')) {
            return null;
        }

        $user = DB::table('users')->where('id', $createdBy)->first();
        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function candidateLinks(object $movement): array
    {
        $start = Carbon::parse((string) $movement->created_at)->subMinutes(self::WINDOW_MINUTES);
        $end = Carbon::parse((string) $movement->created_at)->addMinutes(self::WINDOW_MINUTES);

        return array_values(array_merge(
            $this->supplierPurchaseLinks($movement, $start, $end),
            $this->logisticsRequestLinks($movement, $start, $end),
            $this->equipmentLoanLinks($movement, $start, $end),
            $this->storeOrderLinks($movement, $start, $end),
            $this->legacySaleLinks($movement, $start, $end),
            $this->invoiceItemLinks($movement, $start, $end),
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function supplierPurchaseLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('supplier_purchases') || ! Schema::hasTable('supplier_purchase_items')) {
            return [];
        }

        return DB::table('supplier_purchase_items')
            ->join('supplier_purchases', 'supplier_purchases.id', '=', 'supplier_purchase_items.supplier_purchase_id')
            ->where('supplier_purchase_items.article_id', $movement->article_id)
            ->whereBetween('supplier_purchases.created_at', [$start, $end])
            ->select([
                'supplier_purchases.id',
                'supplier_purchase_items.id as item_id',
                'supplier_purchase_items.quantity',
                'supplier_purchases.supplier_name_snapshot as label',
                'supplier_purchases.created_at',
            ])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('supplier_purchase', $row, $movement, 'supplier_purchase_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function logisticsRequestLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('logistics_requests') || ! Schema::hasTable('logistics_request_items')) {
            return [];
        }

        return DB::table('logistics_request_items')
            ->join('logistics_requests', 'logistics_requests.id', '=', 'logistics_request_items.logistics_request_id')
            ->where('logistics_request_items.article_id', $movement->article_id)
            ->whereBetween('logistics_requests.created_at', [$start, $end])
            ->select([
                'logistics_requests.id',
                'logistics_request_items.id as item_id',
                'logistics_request_items.quantity',
                'logistics_requests.requester_name_snapshot as label',
                'logistics_requests.status',
                'logistics_requests.created_at',
            ])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('logistics_request', $row, $movement, 'logistics_request_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function equipmentLoanLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('equipment_loans')) {
            return [];
        }

        return DB::table('equipment_loans')
            ->where('article_id', $movement->article_id)
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'quantity', 'borrower_name_snapshot as label', 'status', 'created_at'])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('equipment_loan', $row, $movement, 'equipment_loan_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storeOrderLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('loja_encomendas') || ! Schema::hasTable('loja_encomenda_itens')) {
            return [];
        }

        return DB::table('loja_encomenda_itens')
            ->join('loja_encomendas', 'loja_encomendas.id', '=', 'loja_encomenda_itens.loja_encomenda_id')
            ->where('loja_encomenda_itens.article_id', $movement->article_id)
            ->whereBetween('loja_encomendas.created_at', [$start, $end])
            ->select([
                'loja_encomendas.id',
                'loja_encomenda_itens.id as item_id',
                'loja_encomenda_itens.quantidade as quantity',
                'loja_encomendas.numero as label',
                'loja_encomendas.estado as status',
                'loja_encomendas.created_at',
            ])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('store_order', $row, $movement, 'store_order_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function legacySaleLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('sales') || ! Schema::hasColumn('sales', 'produto_id')) {
            return [];
        }

        return DB::table('sales')
            ->where('produto_id', $movement->article_id)
            ->whereBetween(Schema::hasColumn('sales', 'created_at') ? 'created_at' : 'data', [$start, $end])
            ->select([
                'id',
                'quantidade as quantity',
                DB::raw("'legacy_sale' as label"),
                DB::raw('NULL as status'),
                Schema::hasColumn('sales', 'created_at') ? 'created_at' : 'data as created_at',
            ])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('legacy_sale', $row, $movement, 'legacy_sale_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function invoiceItemLinks(object $movement, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('invoice_items') || ! Schema::hasColumn('invoice_items', 'produto_id')) {
            return [];
        }

        $invoiceLabel = Schema::hasColumn('invoices', 'numero')
            ? 'invoices.numero as label'
            : DB::raw('NULL as label');

        return DB::table('invoice_items')
            ->leftJoin('invoices', 'invoices.id', '=', 'invoice_items.fatura_id')
            ->where('invoice_items.produto_id', $movement->article_id)
            ->whereBetween('invoice_items.created_at', [$start, $end])
            ->select([
                'invoice_items.fatura_id as id',
                'invoice_items.id as item_id',
                'invoice_items.quantidade as quantity',
                $invoiceLabel,
                'invoices.estado_pagamento as status',
                'invoice_items.created_at',
            ])
            ->get()
            ->map(fn (object $row): array => $this->linkPayload('invoice_item', $row, $movement, 'invoice_item_created_near_orphan_movement'))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function linkPayload(string $sourceType, object $row, object $movement, string $reason): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => (string) $row->id,
            'source_item_id' => property_exists($row, 'item_id') && $row->item_id ? (string) $row->item_id : null,
            'quantity' => $this->int($row->quantity ?? 0),
            'quantity_matches' => $this->int($row->quantity ?? 0) === $this->int($movement->quantity ?? 0),
            'label' => $row->label ?? null,
            'status' => $row->status ?? null,
            'created_at' => $row->created_at ?? null,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<array<string,mixed>> $candidateLinks
     * @return array{0:string,1:string,2:bool}
     */
    private function classify(object $movement, array $candidateLinks): array
    {
        $notes = mb_strtolower((string) ($movement->notes ?? ''));
        $type = (string) ($movement->movement_type ?? '');

        if ($notes !== '' && preg_match('/\b(teste|debug|experimental|sandbox)\b/u', $notes) === 1) {
            return ['orphan_test_or_debug_candidate', 'neutralize_or_exclude_after_review', true];
        }

        if ($notes !== '' && preg_match('/\b(aceite|aceito|legitimo|legítimo|validado|documentado)\b/u', $notes) === 1) {
            return ['orphan_accepted_manual_movement_candidate', 'accept_as_manual_adjustment', false];
        }

        if ($notes !== '' && (preg_match('/\b(manual|ajuste|correcao|correção|inventario|inventário|inicial)\b/u', $notes) === 1 || in_array($type, ['adjustment', 'ajuste', 'correction', 'correcao', 'import', 'importacao'], true))) {
            return ['orphan_manual_adjustment_candidate', 'accept_as_manual_adjustment', false];
        }

        if ($candidateLinks !== []) {
            return ['orphan_missing_source_candidate', 'link_to_existing_source_after_review', true];
        }

        return ['orphan_requires_manual_review', 'manual_review_required', true];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $collection = collect($items);

        return [
            'total_orphan_movements' => count($items),
            'manual_adjustment_candidate_count' => $collection->where('classification', 'orphan_manual_adjustment_candidate')->count(),
            'missing_source_candidate_count' => $collection->where('classification', 'orphan_missing_source_candidate')->count(),
            'test_or_debug_candidate_count' => $collection->where('classification', 'orphan_test_or_debug_candidate')->count(),
            'accepted_candidate_count' => $collection->where('classification', 'orphan_accepted_manual_movement_candidate')->count(),
            'requires_manual_review_count' => $collection->where('classification', 'orphan_requires_manual_review')->count(),
            'actionable_count' => $collection->filter(fn (array $item): bool => (bool) ($item['actionable'] ?? false))->count(),
            'orphan_physical_net_impact' => $collection->sum(fn (array $item): int => $this->int(data_get($item, 'impact.physical_delta'))),
            'orphan_reserved_net_impact' => $collection->sum(fn (array $item): int => $this->int(data_get($item, 'impact.reserved_delta'))),
        ];
    }

    private function int(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
