<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StockIntegrityAuditService
{
    private const VERSION = 'b1-stock-integrity-audit-v1';
    private const STOCK_MISMATCH_CRITICAL_THRESHOLD = 10;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $schema = $this->schemaDetected();

        $materials = $this->materials($filters);
        $movements = $this->stockMovements($filters, $materials);
        $loans = $this->loans($filters, $materials);
        $requests = $this->requests($filters, $materials);
        $salesOrInvoiceItems = $this->salesOrInvoiceItems($filters, $materials);

        $findings = [];

        if ($this->hasNoInventoryRecords($materials, $movements, $loans, $requests, $salesOrInvoiceItems)) {
            $findings[] = $this->finding('info', 'no_inventory_records', false, 'no_action_needed_no_inventory_records_yet', 'no_inventory_records_found');
        }

        foreach ($materials as $material) {
            array_push($findings, ...$this->materialFindings($material, $movements, $loans, $requests, $salesOrInvoiceItems, $filters));
        }

        array_push($findings, ...$this->orphanMovementFindings($movements, $materials));
        array_push($findings, ...$this->loanFindings($loans, $materials));
        array_push($findings, ...$this->requestFindings($requests, $materials, $movements));
        array_push($findings, ...$this->salesInvoiceFindings($salesOrInvoiceItems, $materials, $movements));

        $findings = $this->uniqueFindings($findings);

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($materials, $movements, $loans, $requests, $salesOrInvoiceItems, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'material' => $this->stringOrNull($options['material'] ?? null),
            'category' => $this->stringOrNull($options['category'] ?? null),
            'location' => $this->stringOrNull($options['location'] ?? null),
            'include_zero' => (bool) ($options['include_zero'] ?? false),
            'include_inactive' => (bool) ($options['include_inactive'] ?? false),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
            'include_clean' => (bool) ($options['include_clean'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        return [
            'material_tables' => array_values(array_filter(['products', 'product_variants'], static fn (string $table): bool => Schema::hasTable($table))),
            'category_tables' => array_values(array_filter(['item_categories'], static fn (string $table): bool => Schema::hasTable($table))),
            'location_tables' => Schema::hasTable('products') && Schema::hasColumn('products', 'area_armazenamento') ? ['products.area_armazenamento'] : [],
            'stock_movement_tables' => array_values(array_filter(['stock_movements'], static fn (string $table): bool => Schema::hasTable($table))),
            'loan_tables' => array_values(array_filter(['equipment_loans'], static fn (string $table): bool => Schema::hasTable($table))),
            'request_tables' => array_values(array_filter(['logistics_requests', 'logistics_request_items'], static fn (string $table): bool => Schema::hasTable($table))),
            'sales_tables' => array_values(array_filter(['sales', 'loja_encomendas', 'loja_encomenda_itens'], static fn (string $table): bool => Schema::hasTable($table))),
            'invoice_item_links' => Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'produto_id') ? ['invoice_items.produto_id'] : [],
            'soft_delete_supported' => [
                'products' => Schema::hasColumn('products', 'deleted_at'),
                'stock_movements' => Schema::hasColumn('stock_movements', 'deleted_at'),
                'equipment_loans' => Schema::hasColumn('equipment_loans', 'deleted_at'),
                'logistics_requests' => Schema::hasColumn('logistics_requests', 'deleted_at'),
                'loja_encomendas' => Schema::hasColumn('loja_encomendas', 'deleted_at'),
                'sales' => Schema::hasColumn('sales', 'deleted_at'),
            ],
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

        if (! $filters['include_deleted'] && Schema::hasColumn('products', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (! $filters['include_inactive'] && Schema::hasColumn('products', 'ativo')) {
            $query->where('ativo', true);
        }

        if ($filters['material']) {
            $query->where('id', $filters['material']);
        }

        if ($filters['category']) {
            if (Schema::hasColumn('products', 'categoria_id')) {
                $query->where('categoria_id', $filters['category']);
            } elseif (Schema::hasColumn('products', 'categoria')) {
                $query->where('categoria', $filters['category']);
            }
        }

        if ($filters['location'] && Schema::hasColumn('products', 'area_armazenamento')) {
            $query->where('area_armazenamento', $filters['location']);
        }

        if (! $filters['include_zero'] && Schema::hasColumn('products', 'stock')) {
            $hasRequestItems = Schema::hasTable('logistics_request_items');
            $query->where(function (Builder $query) use ($hasRequestItems): void {
                $query->where('stock', '!=', 0);

                if ($hasRequestItems) {
                    $query->orWhereExists(function (Builder $exists): void {
                        $exists->selectRaw('1')
                            ->from('logistics_request_items')
                            ->whereColumn('logistics_request_items.article_id', 'products.id');
                    });
                }
            });
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $materials
     * @return Collection<int,object>
     */
    private function stockMovements(array $filters, Collection $materials): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');
        $materialIds = $materials->pluck('id')->filter()->values()->all();

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        } elseif ($filters['category'] || $filters['location'] || ! $filters['include_inactive']) {
            if ($materialIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('article_id', $materialIds);
            }
        }

        if (! $filters['include_deleted'] && Schema::hasColumn('stock_movements', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $materials
     * @return Collection<int,object>
     */
    private function loans(array $filters, Collection $materials): Collection
    {
        if (! Schema::hasTable('equipment_loans')) {
            return collect();
        }

        $query = DB::table('equipment_loans')->orderBy('created_at')->orderBy('id');
        $this->applyArticleFilter($query, $filters, $materials);

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $materials
     * @return Collection<int,object>
     */
    private function requests(array $filters, Collection $materials): Collection
    {
        if (! Schema::hasTable('logistics_requests') || ! Schema::hasTable('logistics_request_items')) {
            return collect();
        }

        $query = DB::table('logistics_request_items')
            ->join('logistics_requests', 'logistics_requests.id', '=', 'logistics_request_items.logistics_request_id')
            ->select([
                'logistics_request_items.id as item_id',
                'logistics_request_items.article_id',
                'logistics_request_items.quantity',
                'logistics_requests.id as request_id',
                'logistics_requests.status',
                'logistics_requests.requester_user_id as user_id',
                'logistics_requests.delivered_at',
                'logistics_requests.created_at',
            ])
            ->orderBy('logistics_requests.created_at')
            ->orderBy('logistics_request_items.id');

        $this->applyArticleFilter($query, $filters, $materials, 'logistics_request_items.article_id');

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $materials
     * @return Collection<int,object>
     */
    private function salesOrInvoiceItems(array $filters, Collection $materials): Collection
    {
        $items = collect();

        if (Schema::hasTable('invoice_items')) {
            $query = DB::table('invoice_items')
                ->leftJoin('invoices', 'invoices.id', '=', 'invoice_items.fatura_id')
                ->whereNotNull('invoice_items.produto_id')
                ->select([
                    DB::raw("'invoice_item' as source"),
                    'invoice_items.id as invoice_item_id',
                    'invoice_items.produto_id as article_id',
                    'invoice_items.quantidade as quantity',
                    'invoice_items.fatura_id as invoice_id',
                    'invoices.user_id',
                    'invoices.tipo as invoice_type',
                    'invoices.created_at',
                ]);
            $this->applyArticleFilter($query, $filters, $materials, 'invoice_items.produto_id');
            $items = $items->merge($query->get());
        }

        if (Schema::hasTable('loja_encomenda_itens') && Schema::hasTable('loja_encomendas')) {
            $query = DB::table('loja_encomenda_itens')
                ->join('loja_encomendas', 'loja_encomendas.id', '=', 'loja_encomenda_itens.loja_encomenda_id')
                ->whereIn('loja_encomendas.estado', ['preparado', 'entregue'])
                ->select([
                    DB::raw("'store_order_item' as source"),
                    'loja_encomenda_itens.id as sale_id',
                    'loja_encomenda_itens.article_id',
                    'loja_encomenda_itens.quantidade as quantity',
                    'loja_encomendas.fatura_id as invoice_id',
                    'loja_encomendas.user_id',
                    'loja_encomendas.estado as status',
                    'loja_encomendas.created_at',
                ]);
            $this->applyArticleFilter($query, $filters, $materials, 'loja_encomenda_itens.article_id');
            $items = $items->merge($query->get());
        }

        if (Schema::hasTable('sales')) {
            $query = DB::table('sales')
                ->select([
                    DB::raw("'legacy_sale' as source"),
                    'sales.id as sale_id',
                    'sales.produto_id as article_id',
                    'sales.quantidade as quantity',
                    'sales.cliente_id as user_id',
                    'sales.created_at',
                ]);
            $this->applyArticleFilter($query, $filters, $materials, 'sales.produto_id');
            $items = $items->merge($query->get());
        }

        return $items->values();
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $loans
     * @param Collection<int,object> $requests
     * @param Collection<int,object> $salesOrInvoiceItems
     * @return list<array<string,mixed>>
     */
    private function materialFindings(object $material, Collection $movements, Collection $loans, Collection $requests, Collection $salesOrInvoiceItems, array $filters): array
    {
        $findings = [];
        $stock = $this->int($material->stock ?? 0);
        $calculated = $this->calculatedStock($movements->where('article_id', $material->id));
        $difference = $stock - $calculated;
        $activeLoansQuantity = $loans
            ->where('article_id', $material->id)
            ->filter(fn (object $loan): bool => in_array((string) ($loan->status ?? ''), ['active', 'overdue'], true))
            ->sum(fn (object $loan): int => $this->int($loan->quantity ?? 0));

        if ($this->blank($material->categoria_id ?? null) && $this->blank($material->categoria ?? null)) {
            $findings[] = $this->finding('info', 'material_without_category', false, 'review_material_category', 'material_has_no_category', material: $material);
        }

        if ($stock > 0 && property_exists($material, 'area_armazenamento') && $this->blank($material->area_armazenamento ?? null)) {
            $findings[] = $this->finding('warning', 'material_without_location', true, 'review_material_location', 'material_has_stock_without_storage_area', material: $material, stockCurrent: $stock, stockCalculated: $calculated, stockDifference: $difference);
        }

        if ($stock < 0) {
            $findings[] = $this->finding('critical', 'negative_stock', true, 'fix_negative_stock_after_manual_review', 'stored_stock_is_negative', material: $material, stockCurrent: $stock, stockCalculated: $calculated, stockDifference: $difference);
        }

        if ($movements->where('article_id', $material->id)->isNotEmpty() && $difference !== 0) {
            $findings[] = $this->finding(abs($difference) >= self::STOCK_MISMATCH_CRITICAL_THRESHOLD ? 'critical' : 'warning', 'stock_quantity_mismatch', true, 'reconcile_stock_quantity', 'stored_stock_differs_from_stock_movements', material: $material, stockCurrent: $stock, stockCalculated: $calculated, stockDifference: $difference);
        }

        if ($activeLoansQuantity > max(0, $stock)) {
            $findings[] = $this->finding($activeLoansQuantity > max(0, $stock) + self::STOCK_MISMATCH_CRITICAL_THRESHOLD ? 'critical' : 'warning', 'active_loan_exceeds_available_stock', true, 'review_active_loans_against_available_stock', 'active_loan_quantity_exceeds_available_stock', material: $material, stockCurrent: $stock, quantity: $activeLoansQuantity);
        }

        if (! (bool) ($material->ativo ?? true) && $stock > 0) {
            $findings[] = $this->finding('warning', 'inactive_material_with_stock', true, 'review_inactive_material_with_stock', 'inactive_material_still_has_stock', material: $material, stockCurrent: $stock);
        }

        if ((bool) ($material->ativo ?? true) && $stock === 0 && $filters['include_zero']) {
            $findings[] = $this->finding('info', 'zero_stock_active_material', false, 'no_action_needed_zero_stock_active_material', 'active_material_has_zero_stock', material: $material, stockCurrent: $stock);
        }

        if ($filters['include_clean'] && $findings === []) {
            $findings[] = $this->finding('info', 'clean_stock_item', false, 'no_action_needed_clean_stock_item', 'stock_item_has_no_detected_findings', material: $material, stockCurrent: $stock, stockCalculated: $calculated, stockDifference: $difference);
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $materials
     * @return list<array<string,mixed>>
     */
    private function orphanMovementFindings(Collection $movements, Collection $materials): array
    {
        $materialIds = $materials->pluck('id')->map('strval')->all();

        return $movements
            ->filter(fn (object $movement): bool => ! in_array((string) ($movement->article_id ?? ''), $materialIds, true))
            ->map(fn (object $movement): array => $this->finding('warning', 'stock_movement_without_material', true, 'review_orphan_stock_movement', 'stock_movement_article_missing', movement: $movement, quantity: $this->int($movement->quantity ?? 0), status: $movement->movement_type ?? null))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $loans
     * @param Collection<int,object> $materials
     * @return list<array<string,mixed>>
     */
    private function loanFindings(Collection $loans, Collection $materials): array
    {
        $findings = [];
        $materialIds = $materials->pluck('id')->map('strval')->all();

        foreach ($loans as $loan) {
            if (! in_array((string) ($loan->article_id ?? ''), $materialIds, true)) {
                $findings[] = $this->finding('warning', 'loan_without_material', true, 'review_loan_without_material', 'loan_article_missing', loan: $loan, quantity: $this->int($loan->quantity ?? 0), status: $loan->status ?? null);
            }

            if (in_array((string) ($loan->status ?? ''), ['active', 'overdue'], true) && $loan->due_date && Carbon::parse((string) $loan->due_date)->lt(Carbon::today())) {
                $findings[] = $this->finding('warning', 'overdue_loan', true, 'review_overdue_loan', 'active_loan_due_date_passed', loan: $loan, quantity: $this->int($loan->quantity ?? 0), status: $loan->status ?? null, dueDate: $loan->due_date);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $requests
     * @param Collection<int,object> $materials
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function requestFindings(Collection $requests, Collection $materials, Collection $movements): array
    {
        $findings = [];

        foreach ($requests as $request) {
            $material = $materials->firstWhere('id', $request->article_id);
            $quantity = $this->int($request->quantity ?? 0);
            $stock = $material ? $this->int($material->stock ?? 0) : 0;

            if (in_array((string) ($request->status ?? ''), ['draft', 'pending', 'pendente', 'approved', 'aprovado'], true) && $quantity > $stock) {
                $findings[] = $this->finding('warning', 'pending_request_without_stock', true, 'review_pending_request_stock', 'pending_request_quantity_exceeds_stock', material: $material, request: $request, stockCurrent: $stock, quantity: $quantity, status: $request->status ?? null);
            }

            if (in_array((string) ($request->status ?? ''), ['delivered', 'entregue'], true)) {
                $hasMovement = $movements->contains(fn (object $movement): bool => (string) ($movement->reference_type ?? '') === 'logistics_request'
                    && (string) ($movement->reference_id ?? '') === (string) $request->request_id
                    && in_array((string) ($movement->movement_type ?? ''), ['deliver_reservation', 'exit'], true));

                if (! $hasMovement) {
                    $findings[] = $this->finding('warning', 'delivered_request_without_stock_movement', true, 'review_delivered_request_stock_movement', 'delivered_request_has_no_stock_output_trace', material: $material, request: $request, stockCurrent: $stock, quantity: $quantity, status: $request->status ?? null);
                }
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $items
     * @param Collection<int,object> $materials
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function salesInvoiceFindings(Collection $items, Collection $materials, Collection $movements): array
    {
        $findings = [];

        foreach ($items as $item) {
            $material = $materials->firstWhere('id', $item->article_id);
            if (! $material || ! (bool) ($material->track_stock ?? true)) {
                continue;
            }

            $quantity = $this->int($item->quantity ?? 0);
            $hasDecrease = $movements->contains(fn (object $movement): bool => (string) ($movement->article_id ?? '') === (string) $item->article_id
                && in_array((string) ($movement->movement_type ?? ''), ['exit', 'reservation', 'deliver_reservation'], true)
                && $this->int($movement->quantity ?? 0) >= $quantity);

            if (! $hasDecrease) {
                $findings[] = $this->finding('warning', 'sale_or_invoice_item_without_stock_decrease', true, 'review_sale_or_invoice_stock_decrease', 'sale_or_invoice_item_has_no_stock_decrease_trace', material: $material, saleOrInvoice: $item, quantity: $quantity, status: $item->status ?? $item->invoice_type ?? null);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     */
    private function calculatedStock(Collection $movements): int
    {
        return (int) $movements->sum(function (object $movement): int {
            $quantity = $this->int($movement->quantity ?? 0);

            return match ((string) ($movement->movement_type ?? '')) {
                'entry', 'return', 'cancel_reservation' => $quantity,
                'exit', 'reservation' => -$quantity,
                'deliver_reservation' => 0,
                default => 0,
            };
        });
    }

    private function applyArticleFilter(Builder $query, array $filters, Collection $materials, string $column = 'article_id'): void
    {
        if ($filters['material']) {
            $query->where($column, $filters['material']);

            return;
        }

        if ($filters['category'] || $filters['location'] || ! $filters['include_inactive']) {
            $ids = $materials->pluck('id')->filter()->values()->all();
            if ($ids === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn($column, $ids);
        }
    }

    /**
     * @param Collection<int,object> $materials
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $loans
     * @param Collection<int,object> $requests
     * @param Collection<int,object> $salesOrInvoiceItems
     */
    private function hasNoInventoryRecords(Collection $materials, Collection $movements, Collection $loans, Collection $requests, Collection $salesOrInvoiceItems): bool
    {
        return $materials->isEmpty()
            && $movements->isEmpty()
            && $loans->isEmpty()
            && $requests->isEmpty()
            && $salesOrInvoiceItems->isEmpty();
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                $finding['code'] ?? '',
                $finding['material_id'] ?? '',
                $finding['movement_id'] ?? '',
                $finding['loan_id'] ?? '',
                $finding['request_id'] ?? '',
                $finding['sale_id'] ?? '',
                $finding['invoice_item_id'] ?? '',
            ]))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $materials
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $loans
     * @param Collection<int,object> $requests
     * @param Collection<int,object> $salesOrInvoiceItems
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $materials, Collection $movements, Collection $loans, Collection $requests, Collection $salesOrInvoiceItems, array $findings): array
    {
        return [
            'total_materials_scanned' => $materials->count(),
            'total_locations_scanned' => $materials->pluck('area_armazenamento')->filter()->unique()->count(),
            'total_stock_movements_scanned' => $movements->count(),
            'total_loans_scanned' => $loans->count(),
            'total_requests_scanned' => $requests->pluck('request_id')->filter()->unique()->count(),
            'total_sales_or_invoice_items_scanned' => $salesOrInvoiceItems->count(),
            'materials_without_stock_count' => $materials->filter(fn (object $material): bool => $this->int($material->stock ?? 0) === 0)->count(),
            'negative_stock_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'negative_stock')),
            'stock_mismatch_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'stock_quantity_mismatch')),
            'active_loans_count' => $loans->filter(fn (object $loan): bool => in_array((string) ($loan->status ?? ''), ['active', 'overdue'], true))->count(),
            'overdue_loans_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'overdue_loan')),
            'pending_requests_count' => $requests->filter(fn (object $request): bool => in_array((string) ($request->status ?? ''), ['draft', 'pending', 'pendente', 'approved', 'aprovado'], true))->pluck('request_id')->unique()->count(),
            'orphan_stock_movement_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'stock_movement_without_material')),
            'financial_stock_link_warning_count' => count(array_filter($findings, static fn (array $finding): bool => in_array($finding['code'], ['sale_or_invoice_item_without_stock_decrease', 'stock_decrease_without_financial_trace'], true))),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'actionable_count' => count(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, bool $actionable, string $recommendation, string $reason, ?object $material = null, ?object $movement = null, ?object $loan = null, ?object $request = null, ?object $saleOrInvoice = null, ?int $stockCurrent = null, ?int $stockCalculated = null, ?int $stockDifference = null, ?int $quantity = null, ?string $status = null, mixed $dueDate = null): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'material_id' => $this->prop($material, 'id') ? (string) $this->prop($material, 'id') : ($this->prop($movement, 'article_id') ? (string) $this->prop($movement, 'article_id') : ($this->prop($loan, 'article_id') ? (string) $this->prop($loan, 'article_id') : ($this->prop($request, 'article_id') ? (string) $this->prop($request, 'article_id') : ($this->prop($saleOrInvoice, 'article_id') ? (string) $this->prop($saleOrInvoice, 'article_id') : null)))),
            'material_name' => $this->prop($material, 'nome') ?? $this->prop($loan, 'article_name_snapshot'),
            'category_id' => $this->prop($material, 'categoria_id') ?? $this->prop($material, 'categoria'),
            'location_id' => $this->prop($material, 'area_armazenamento'),
            'stock_current' => $stockCurrent,
            'stock_calculated' => $stockCalculated,
            'stock_difference' => $stockDifference,
            'movement_id' => $this->prop($movement, 'id') ? (string) $this->prop($movement, 'id') : null,
            'loan_id' => $this->prop($loan, 'id') ? (string) $this->prop($loan, 'id') : null,
            'request_id' => $this->prop($request, 'request_id') ? (string) $this->prop($request, 'request_id') : null,
            'sale_id' => $this->prop($saleOrInvoice, 'sale_id') ? (string) $this->prop($saleOrInvoice, 'sale_id') : null,
            'invoice_id' => $this->prop($saleOrInvoice, 'invoice_id') ? (string) $this->prop($saleOrInvoice, 'invoice_id') : null,
            'invoice_item_id' => $this->prop($saleOrInvoice, 'invoice_item_id') ? (string) $this->prop($saleOrInvoice, 'invoice_item_id') : null,
            'user_id' => $this->prop($request, 'user_id') ? (string) $this->prop($request, 'user_id') : ($this->prop($saleOrInvoice, 'user_id') ? (string) $this->prop($saleOrInvoice, 'user_id') : null),
            'quantity' => $quantity,
            'status' => $status,
            'due_date' => $dueDate ? (string) $dueDate : null,
            'created_at' => $this->prop($movement, 'created_at') ?? $this->prop($loan, 'created_at') ?? $this->prop($request, 'created_at') ?? $this->prop($saleOrInvoice, 'created_at'),
            'deleted_at' => $this->prop($material, 'deleted_at') ?? $this->prop($movement, 'deleted_at'),
            'classification_reason' => $reason,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
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
