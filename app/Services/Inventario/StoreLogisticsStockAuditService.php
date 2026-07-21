<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class StoreLogisticsStockAuditService
{
    private const VERSION = 'b6-store-logistics-stock-audit-v1';

    private const STORE_SOURCES = ['store_order_item', 'loja_encomenda_item'];
    private const INVOICE_SOURCES = ['invoice_item', 'manual_invoice_item', 'manual_invoice_create', 'manual_invoice_update_exit'];
    private const RETURN_INVOICE_SOURCES = ['manual_invoice_update_reversal', 'manual_invoice_delete'];
    private const LOGISTICS_SOURCE = 'logistics_request';
    private const CORRECTION_SOURCE = 'audit_orphan_resolution';
    private const B1_4_CORRECTION_NOTE = 'Baixa de stock por venda/encomenda entregue registada retroativamente';

    private const STOCK_EXIT_TYPES = ['exit', 'sale', 'venda'];
    private const ACTIVE_STORE_STATUSES = ['preparado', 'entregue', 'pago', 'concluido', 'concluído', 'completed', 'delivered', 'paid'];
    private const CANCELLED_STORE_STATUSES = ['cancelado', 'cancelled', 'canceled', 'anulado', 'voided'];

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

        $storeItems = $this->storeOrderItems($filters);
        $invoiceItems = $this->invoiceItems($filters);
        $logisticsItems = $this->logisticsItems($filters);
        $products = $this->products($filters, $storeItems, $invoiceItems, $logisticsItems);
        $movements = $this->stockMovements($filters, $storeItems, $invoiceItems, $logisticsItems);

        $findings = [
            ...$this->storeFindings($storeItems, $products, $movements),
            ...$this->invoiceFindings($invoiceItems, $products, $movements, $storeItems),
            ...$this->crossDuplicateFindings($storeItems, $invoiceItems, $logisticsItems, $products, $movements),
            ...$this->sourceReferenceFindings($movements, $products),
        ];

        $findings = $this->uniqueFindings($findings);

        if ($findings === []) {
            $findings[] = $this->finding('info', 'store_logistics_stock_clean', 'system', false, 'no_action_needed_store_stock_clean');
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($storeItems, $invoiceItems, $logisticsItems, $movements, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{order:?string,invoice:?string,material:?string,only_actionable:bool}
     */
    private function filters(array $options): array
    {
        return [
            'order' => $this->stringOrNull($options['order'] ?? null),
            'invoice' => $this->stringOrNull($options['invoice'] ?? null),
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
            'tables' => [
                'loja_encomendas' => Schema::hasTable('loja_encomendas'),
                'loja_encomenda_itens' => Schema::hasTable('loja_encomenda_itens'),
                'sales' => Schema::hasTable('sales'),
                'invoice_items' => Schema::hasTable('invoice_items'),
                'invoices' => Schema::hasTable('invoices'),
                'logistics_requests' => Schema::hasTable('logistics_requests'),
                'logistics_request_items' => Schema::hasTable('logistics_request_items'),
                'products' => Schema::hasTable('products'),
                'product_variants' => Schema::hasTable('product_variants'),
                'stock_movements' => Schema::hasTable('stock_movements'),
            ],
            'store_order_fields' => [
                'status' => Schema::hasColumn('loja_encomendas', 'estado') ? 'estado' : null,
                'invoice_id' => Schema::hasColumn('loja_encomendas', 'fatura_id') ? 'fatura_id' : null,
            ],
            'store_order_item_fields' => [
                'product_column' => Schema::hasColumn('loja_encomenda_itens', 'article_id') ? 'article_id' : null,
                'variant_column' => Schema::hasColumn('loja_encomenda_itens', 'product_variant_id') ? 'product_variant_id' : null,
                'quantity_column' => Schema::hasColumn('loja_encomenda_itens', 'quantidade') ? 'quantidade' : null,
            ],
            'invoice_item_fields' => [
                'product_column' => Schema::hasColumn('invoice_items', 'produto_id') ? 'produto_id' : null,
                'quantity_column' => Schema::hasColumn('invoice_items', 'quantidade') ? 'quantidade' : null,
            ],
            'stock_movement_reference_fields' => [
                'source_type_column' => Schema::hasColumn('stock_movements', 'reference_type') ? 'reference_type' : null,
                'source_id_column' => Schema::hasColumn('stock_movements', 'reference_id') ? 'reference_id' : null,
                'source_id_is_uuid' => true,
            ],
            'source_types' => [
                'store' => self::STORE_SOURCES,
                'invoice' => self::INVOICE_SOURCES,
                'invoice_returns' => self::RETURN_INVOICE_SOURCES,
                'logistics' => [self::LOGISTICS_SOURCE],
                'correction' => [self::CORRECTION_SOURCE],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function storeOrderItems(array $filters): Collection
    {
        if (! Schema::hasTable('loja_encomenda_itens') || ! Schema::hasTable('loja_encomendas')) {
            return collect();
        }

        $query = DB::table('loja_encomenda_itens')
            ->join('loja_encomendas', 'loja_encomendas.id', '=', 'loja_encomenda_itens.loja_encomenda_id')
            ->select([
                'loja_encomenda_itens.id',
                'loja_encomenda_itens.loja_encomenda_id as order_id',
                'loja_encomendas.numero as order_number',
                'loja_encomendas.estado as status',
                'loja_encomendas.fatura_id as invoice_id',
                'loja_encomenda_itens.article_id',
                'loja_encomenda_itens.quantidade as quantity',
                'loja_encomenda_itens.descricao as description',
                'loja_encomenda_itens.created_at',
            ])
            ->orderBy('loja_encomenda_itens.created_at')
            ->orderBy('loja_encomenda_itens.id');

        if ($filters['order'] !== null) {
            $query->where('loja_encomendas.id', $filters['order']);
        }

        if ($filters['invoice'] !== null) {
            $query->where('loja_encomendas.fatura_id', $filters['invoice']);
        }

        if ($filters['material'] !== null) {
            $query->where('loja_encomenda_itens.article_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function invoiceItems(array $filters): Collection
    {
        if (! Schema::hasTable('invoice_items') || ! Schema::hasTable('invoices')) {
            return collect();
        }

        $query = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.fatura_id')
            ->whereNotNull('invoice_items.produto_id')
            ->select([
                'invoice_items.id',
                'invoice_items.fatura_id as invoice_id',
                'invoice_items.produto_id as article_id',
                'invoice_items.quantidade as quantity',
                'invoice_items.descricao as description',
                'invoice_items.created_at',
                'invoices.tipo as invoice_type',
                'invoices.origem_tipo',
                'invoices.origem_id',
                'invoices.estado_pagamento',
            ])
            ->orderBy('invoice_items.created_at')
            ->orderBy('invoice_items.id');

        if ($filters['invoice'] !== null) {
            $query->where('invoices.id', $filters['invoice']);
        }

        if ($filters['material'] !== null) {
            $query->where('invoice_items.produto_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function logisticsItems(array $filters): Collection
    {
        if (! Schema::hasTable('logistics_request_items') || ! Schema::hasTable('logistics_requests')) {
            return collect();
        }

        $query = DB::table('logistics_request_items')
            ->join('logistics_requests', 'logistics_requests.id', '=', 'logistics_request_items.logistics_request_id')
            ->select([
                'logistics_request_items.id',
                'logistics_request_items.logistics_request_id as request_id',
                'logistics_request_items.article_id',
                'logistics_request_items.quantity',
                'logistics_requests.status',
                'logistics_requests.financial_invoice_id as invoice_id',
                'logistics_requests.created_at',
            ])
            ->orderBy('logistics_request_items.created_at')
            ->orderBy('logistics_request_items.id');

        if ($filters['invoice'] !== null) {
            $query->where('logistics_requests.financial_invoice_id', $filters['invoice']);
        }

        if ($filters['material'] !== null) {
            $query->where('logistics_request_items.article_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @return Collection<string,object>
     */
    private function products(array $filters, Collection $storeItems, Collection $invoiceItems, Collection $logisticsItems): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        $ids = $storeItems->pluck('article_id')
            ->merge($invoiceItems->pluck('article_id'))
            ->merge($logisticsItems->pluck('article_id'))
            ->filter()
            ->map('strval')
            ->unique()
            ->values()
            ->all();

        if ($filters['material'] !== null) {
            $ids[] = $filters['material'];
        }

        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === []
            ? collect()
            : DB::table('products')->whereIn('id', $ids)->get()->keyBy(fn (object $product): string => (string) $product->id);
    }

    /**
     * @return Collection<int,object>
     */
    private function stockMovements(array $filters, Collection $storeItems, Collection $invoiceItems, Collection $logisticsItems): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $sourceTypes = array_values(array_unique([
            ...self::STORE_SOURCES,
            ...self::INVOICE_SOURCES,
            ...self::RETURN_INVOICE_SOURCES,
            self::LOGISTICS_SOURCE,
            self::CORRECTION_SOURCE,
        ]));

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');

        if ($filters['material'] !== null) {
            $query->where('article_id', $filters['material']);
        }

        $storeIds = $storeItems->pluck('id')->filter()->map('strval')->values()->all();
        $invoiceItemIds = $invoiceItems->pluck('id')->filter()->map('strval')->values()->all();
        $logisticsIds = $logisticsItems->pluck('request_id')->filter()->map('strval')->unique()->values()->all();

        $query->where(function (Builder $query) use ($sourceTypes, $storeIds, $invoiceItemIds, $logisticsIds): void {
            $query->whereIn('reference_type', $sourceTypes);

            if ($storeIds !== [] || $invoiceItemIds !== [] || $logisticsIds !== []) {
                $query->orWhere(function (Builder $linked) use ($storeIds, $invoiceItemIds, $logisticsIds): void {
                    if ($storeIds !== []) {
                        $linked->orWhere(fn (Builder $q): Builder => $q->whereIn('reference_type', self::STORE_SOURCES)->whereIn('reference_id', $storeIds));
                    }
                    if ($invoiceItemIds !== []) {
                        $linked->orWhere(fn (Builder $q): Builder => $q->whereIn('reference_type', [...self::INVOICE_SOURCES, ...self::RETURN_INVOICE_SOURCES])->whereIn('reference_id', $invoiceItemIds));
                    }
                    if ($logisticsIds !== []) {
                        $linked->orWhere(fn (Builder $q): Builder => $q->where('reference_type', self::LOGISTICS_SOURCE)->whereIn('reference_id', $logisticsIds));
                    }
                });
            }
        });

        return $query->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storeFindings(Collection $storeItems, Collection $products, Collection $movements): array
    {
        $findings = [];

        foreach ($storeItems as $item) {
            $product = $products->get((string) ($item->article_id ?? ''));
            $quantity = (int) ($item->quantity ?? 0);
            $status = $this->normalizeStatus($item->status ?? null);
            $itemMovements = $this->sourceMovements($movements, self::STORE_SOURCES, (string) $item->id, (string) ($item->article_id ?? ''));
            $metrics = $this->movementMetrics($itemMovements);

            if ($this->blank($item->article_id ?? null) || $product === null) {
                $findings[] = $this->finding('critical', 'store_stock_invalid_product', 'store_order_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            }

            if ($quantity <= 0) {
                $findings[] = $this->finding('warning', 'store_stock_invalid_quantity', 'store_order_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
                continue;
            }

            if ($this->blank($item->article_id ?? null) || $product === null) {
                continue;
            }

            if (in_array($status, self::CANCELLED_STORE_STATUSES, true)) {
                if ($metrics['exit_qty'] > 0) {
                    $findings[] = $this->finding('warning', 'store_order_cancelled_with_physical_exit', 'store_order_item', true, 'inspect_duplicate_store_stock_exit', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
                }
                continue;
            }

            if (! in_array($status, self::ACTIVE_STORE_STATUSES, true)) {
                continue;
            }

            if ($metrics['exit_qty'] <= 0) {
                $findings[] = $this->finding('critical', 'store_order_missing_physical_exit', 'store_order_item', true, 'create_missing_store_stock_exit', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            } elseif ($metrics['exit_count'] > 1) {
                $findings[] = $this->finding('warning', 'store_order_duplicate_physical_exit', 'store_order_item', true, 'inspect_duplicate_store_stock_exit', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            } elseif ($metrics['exit_qty'] !== $quantity) {
                $findings[] = $this->finding('warning', 'store_order_quantity_mismatch', 'store_order_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            } elseif ($this->isB14Correction($itemMovements)) {
                $findings[] = $this->finding('info', 'store_stock_legacy_corrected_by_audit', 'store_order_item', false, 'no_action_needed_audit_corrected_legacy', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            } else {
                $findings[] = $this->finding('info', 'store_order_stock_clean', 'store_order_item', false, 'no_action_needed_store_stock_clean', $product, $quantity, $metrics, ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null]);
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function invoiceFindings(Collection $invoiceItems, Collection $products, Collection $movements, Collection $storeItems): array
    {
        $findings = [];

        foreach ($invoiceItems as $item) {
            $product = $products->get((string) ($item->article_id ?? ''));
            $quantity = (int) ($item->quantity ?? 0);
            $itemMovements = $this->sourceMovements($movements, self::INVOICE_SOURCES, (string) $item->id, (string) ($item->article_id ?? ''));
            $metrics = $this->movementMetrics($itemMovements);
            $linkedStoreItems = $storeItems->filter(fn (object $store): bool => (string) ($store->invoice_id ?? '') === (string) $item->invoice_id
                && (string) ($store->article_id ?? '') === (string) ($item->article_id ?? ''));
            $storeMetrics = $this->movementMetrics($this->sourceMovementsForIds($movements, self::STORE_SOURCES, $linkedStoreItems->pluck('id')->map('strval')->all(), (string) ($item->article_id ?? '')));

            if ($this->blank($item->article_id ?? null) || $product === null) {
                $findings[] = $this->finding('critical', 'store_stock_invalid_product', 'invoice_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            }

            if ($quantity <= 0) {
                $findings[] = $this->finding('warning', 'store_stock_invalid_quantity', 'invoice_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
                continue;
            }

            if ($this->blank($item->article_id ?? null) || $product === null) {
                continue;
            }

            if ($metrics['exit_qty'] > 0 && $storeMetrics['exit_qty'] > 0) {
                $findings[] = $this->finding('warning', 'invoice_store_duplicate_stock_exit', 'invoice_item', true, 'inspect_invoice_store_duplicate_exit', $product, $quantity, $this->mergeMetrics($metrics, $storeMetrics), ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            } elseif ($metrics['exit_qty'] <= 0 && $storeMetrics['exit_qty'] <= 0) {
                $findings[] = $this->finding('critical', 'invoice_item_missing_physical_exit', 'invoice_item', true, 'create_missing_store_stock_exit', $product, $quantity, $metrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            } elseif ($metrics['exit_count'] > 1) {
                $findings[] = $this->finding('warning', 'invoice_item_duplicate_physical_exit', 'invoice_item', true, 'inspect_duplicate_store_stock_exit', $product, $quantity, $metrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            } elseif (($metrics['exit_qty'] ?: $storeMetrics['exit_qty']) !== $quantity) {
                $findings[] = $this->finding('warning', 'invoice_item_quantity_mismatch', 'invoice_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics['exit_qty'] > 0 ? $metrics : $storeMetrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            } else {
                $findings[] = $this->finding('info', 'invoice_item_stock_clean', 'invoice_item', false, 'no_action_needed_store_stock_clean', $product, $quantity, $metrics['exit_qty'] > 0 ? $metrics : $storeMetrics, ['invoice_id' => (string) ($item->invoice_id ?? ''), 'invoice_item_id' => (string) $item->id]);
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function crossDuplicateFindings(Collection $storeItems, Collection $invoiceItems, Collection $logisticsItems, Collection $products, Collection $movements): array
    {
        $findings = [];

        foreach ($storeItems as $store) {
            $product = $products->get((string) ($store->article_id ?? ''));
            $storeMetrics = $this->movementMetrics($this->sourceMovements($movements, self::STORE_SOURCES, (string) $store->id, (string) ($store->article_id ?? '')));
            $matchingLogistics = $logisticsItems->filter(fn (object $item): bool => (string) ($item->invoice_id ?? '') !== ''
                && (string) ($item->invoice_id ?? '') === (string) ($store->invoice_id ?? '')
                && (string) ($item->article_id ?? '') === (string) ($store->article_id ?? ''));

            foreach ($matchingLogistics as $logistics) {
                $logisticsMetrics = $this->movementMetrics($this->sourceMovements($movements, [self::LOGISTICS_SOURCE], (string) $logistics->request_id, (string) ($store->article_id ?? '')));
                if ($storeMetrics['exit_qty'] > 0 && $logisticsMetrics['exit_qty'] > 0) {
                    $findings[] = $this->finding('warning', 'logistics_store_duplicate_stock_exit', 'logistics_request', true, 'inspect_logistics_store_duplicate_exit', $product, (int) ($store->quantity ?? 0), $this->mergeMetrics($storeMetrics, $logisticsMetrics), ['order_id' => (string) ($store->order_id ?? ''), 'store_order_item_id' => (string) $store->id, 'request_id' => (string) ($logistics->request_id ?? '')]);
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sourceReferenceFindings(Collection $movements, Collection $products): array
    {
        $findings = [];
        $sourceTypes = [...self::STORE_SOURCES, ...self::INVOICE_SOURCES, ...self::RETURN_INVOICE_SOURCES, self::LOGISTICS_SOURCE];

        foreach ($movements->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $sourceTypes, true)) as $movement) {
            $sourceId = $movement->reference_id ?? null;
            if ($sourceId === null || trim((string) $sourceId) === '' || ! Str::isUuid((string) $sourceId)) {
                $findings[] = $this->finding('warning', 'store_stock_invalid_source_reference', (string) ($movement->reference_type ?? ''), true, 'inspect_store_quantity_mismatch', $products->get((string) ($movement->article_id ?? '')), null, $this->movementMetrics(collect([$movement])));
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $sourceTypes
     */
    private function sourceMovements(Collection $movements, array $sourceTypes, string $sourceId, string $materialId): Collection
    {
        return $movements
            ->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $sourceTypes, true)
                && (string) ($movement->reference_id ?? '') === $sourceId
                && (string) ($movement->article_id ?? '') === $materialId)
            ->values();
    }

    /**
     * @param list<string> $sourceTypes
     * @param list<string> $sourceIds
     */
    private function sourceMovementsForIds(Collection $movements, array $sourceTypes, array $sourceIds, string $materialId): Collection
    {
        return $movements
            ->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $sourceTypes, true)
                && in_array((string) ($movement->reference_id ?? ''), $sourceIds, true)
                && (string) ($movement->article_id ?? '') === $materialId)
            ->values();
    }

    /**
     * @return array{physical_net:int,exit_qty:int,exit_count:int,return_qty:int,movement_ids:list<string>}
     */
    private function movementMetrics(Collection $movements): array
    {
        $physical = 0;
        $exit = 0;
        $exitCount = 0;
        $return = 0;

        foreach ($movements as $movement) {
            $physical += $this->semantics->deltas($movement)['physical'];
            $type = (string) ($movement->movement_type ?? '');
            $quantity = abs((int) ($movement->quantity ?? 0));

            if (in_array($type, self::STOCK_EXIT_TYPES, true)) {
                $exit += $quantity;
                $exitCount++;
            }

            if ($type === 'return') {
                $return += $quantity;
            }
        }

        return [
            'physical_net' => $physical,
            'exit_qty' => $exit,
            'exit_count' => $exitCount,
            'return_qty' => $return,
            'movement_ids' => $movements->pluck('id')->map('strval')->values()->all(),
        ];
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @return array<string,mixed>
     */
    private function mergeMetrics(array $left, array $right): array
    {
        return [
            'physical_net' => (int) ($left['physical_net'] ?? 0) + (int) ($right['physical_net'] ?? 0),
            'exit_qty' => (int) ($left['exit_qty'] ?? 0) + (int) ($right['exit_qty'] ?? 0),
            'exit_count' => (int) ($left['exit_count'] ?? 0) + (int) ($right['exit_count'] ?? 0),
            'return_qty' => (int) ($left['return_qty'] ?? 0) + (int) ($right['return_qty'] ?? 0),
            'movement_ids' => array_values(array_unique([
                ...array_map('strval', $left['movement_ids'] ?? []),
                ...array_map('strval', $right['movement_ids'] ?? []),
            ])),
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
                (string) ($finding['source'] ?? ''),
                (string) ($finding['order_id'] ?? ''),
                (string) ($finding['invoice_item_id'] ?? ''),
                (string) ($finding['request_id'] ?? ''),
                (string) ($finding['material_id'] ?? ''),
                implode(',', array_map('strval', $finding['movement_ids'] ?? [])),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string,int>
     */
    private function summary(Collection $storeItems, Collection $invoiceItems, Collection $logisticsItems, Collection $movements, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_store_orders_scanned' => $storeItems->pluck('order_id')->unique()->count(),
            'total_store_order_items_scanned' => $storeItems->count(),
            'total_invoice_items_scanned' => $invoiceItems->count(),
            'total_logistics_movements_scanned' => $logisticsItems->pluck('request_id')->unique()->count(),
            'total_related_stock_movements' => $movements->count(),
            'missing_physical_exit_count' => $findingsCollection->whereIn('code', ['store_order_missing_physical_exit', 'invoice_item_missing_physical_exit'])->count(),
            'duplicate_physical_exit_count' => $findingsCollection->whereIn('code', ['store_order_duplicate_physical_exit', 'invoice_item_duplicate_physical_exit'])->count(),
            'cancelled_with_physical_exit_count' => $findingsCollection->where('code', 'store_order_cancelled_with_physical_exit')->count(),
            'invoice_store_duplicate_exit_count' => $findingsCollection->where('code', 'invoice_store_duplicate_stock_exit')->count(),
            'logistics_store_duplicate_exit_count' => $findingsCollection->where('code', 'logistics_store_duplicate_stock_exit')->count(),
            'quantity_mismatch_count' => $findingsCollection->whereIn('code', ['store_order_quantity_mismatch', 'invoice_item_quantity_mismatch'])->count(),
            'invalid_product_count' => $findingsCollection->where('code', 'store_stock_invalid_product')->count(),
            'invalid_quantity_count' => $findingsCollection->where('code', 'store_stock_invalid_quantity')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'store_stock_invalid_source_reference')->count(),
            'legacy_corrected_by_audit_count' => $findingsCollection->where('code', 'store_stock_legacy_corrected_by_audit')->count(),
            'clean_count' => $findingsCollection->whereIn('code', ['store_order_stock_clean', 'invoice_item_stock_clean', 'store_stock_legacy_corrected_by_audit', 'store_logistics_stock_clean'])->count(),
            'total_findings' => $findingsCollection->count(),
            'critical_count' => $findingsCollection->where('severity', 'critical')->count(),
            'warning_count' => $findingsCollection->where('severity', 'warning')->count(),
            'info_count' => $findingsCollection->where('severity', 'info')->count(),
            'actionable_count' => $findingsCollection->where('actionable', true)->count(),
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        string $source,
        bool $actionable,
        string $recommendation,
        ?object $product = null,
        ?int $quantitySource = null,
        array $metrics = [],
        array $extra = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'source' => $source,
            'material_id' => $this->prop($product, 'id') ? (string) $this->prop($product, 'id') : null,
            'material_name' => $this->prop($product, 'nome'),
            'quantity_source' => $quantitySource,
            'quantity_ledger' => $metrics['exit_qty'] ?? null,
            'physical_net' => $metrics['physical_net'] ?? null,
            'exit_qty' => $metrics['exit_qty'] ?? null,
            'return_qty' => $metrics['return_qty'] ?? null,
            'movement_ids' => $metrics['movement_ids'] ?? [],
            'movement_id' => ($metrics['movement_ids'] ?? []) !== [] ? (string) ($metrics['movement_ids'][0] ?? '') : null,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'classification_reason' => $extra['classification_reason'] ?? $code,
            ...$extra,
        ];
    }

    private function isB14Correction(Collection $movements): bool
    {
        return $movements->contains(fn (object $movement): bool => str_contains((string) ($movement->notes ?? ''), self::B1_4_CORRECTION_NOTE));
    }

    private function normalizeStatus(mixed $status): string
    {
        return trim(mb_strtolower((string) $status));
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
