<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\FiscalDocumentRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class StoreLogisticsStockAuditService
{
    private const VERSION = 'h5d-store-return-reversal-audit-v5';

    private const STORE_SOURCES = ['store_order_item', 'loja_encomenda_item'];
    private const INVOICE_SOURCES = ['invoice_item', 'manual_invoice_item', 'manual_invoice_create', 'manual_invoice_update_exit'];
    private const RETURN_INVOICE_SOURCES = ['manual_invoice_update_reversal', 'manual_invoice_delete'];
    private const LOGISTICS_SOURCE = 'logistics_request';
    private const CORRECTION_SOURCE = 'audit_orphan_resolution';
    private const B1_4_CORRECTION_NOTE = 'Baixa de stock por venda/encomenda entregue registada retroativamente';

    private const STOCK_EXIT_TYPES = ['exit', 'sale', 'venda'];
    private const ACTIVE_STORE_STATUSES = ['pendente', 'aprovado', 'preparado', 'entregue', 'pending', 'approved', 'pago', 'concluido', 'concluído', 'completed', 'delivered', 'paid'];
    private const CANCELLED_STORE_STATUSES = ['cancelado', 'cancelled', 'canceled', 'anulado', 'voided'];
    private const RETURNED_STORE_STATUSES = ['devolvido', 'returned', 'refunded'];

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

        $storeOrders = $this->storeOrders($filters);
        $storeItems = $this->storeOrderItems($filters);
        $invoiceItems = $this->invoiceItems($filters);
        $logisticsItems = $this->logisticsItems($filters);
        $products = $this->products($filters, $storeItems, $invoiceItems, $logisticsItems);
        $movements = $this->stockMovements($filters, $storeItems, $invoiceItems, $logisticsItems);
        $storeReturns = $this->storeReturns($filters);

        $findings = [
            ...$this->storeInvoiceLinkFindings($storeOrders, $storeItems, $invoiceItems),
            ...$this->storeFindings($storeItems, $products, $movements),
            ...$this->storeReturnFindings($storeReturns),
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
            'read_only' => true,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($storeOrders, $storeItems, $invoiceItems, $logisticsItems, $movements, $storeReturns, $findings),
            'interpretation' => [
                'cancelled_order_is_balanced_when_exit_and_return_match' => true,
                'canonical_store_invoice_contract_active' => true,
                'store_financial_state_is_derived_from_invoice' => true,
                'paid_store_invoice_requires_manual_wintouch_request' => true,
                'delivered_return_requires_financial_and_fiscal_reversal_before_stock' => true,
                'payment_reversal_history_is_preserved' => true,
                'legacy_orders_without_invoice_are_reported_without_backfill' => true,
                'no_data_changed' => true,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function storeOrders(array $filters): Collection
    {
        if (! Schema::hasTable('loja_encomendas')) {
            return collect();
        }

        $query = DB::table('loja_encomendas')
            ->select([
                'id',
                'numero',
                'user_id',
                'target_user_id',
                'estado as status',
                'total',
                'fatura_id as invoice_id',
                'created_at',
            ])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['order'] !== null) {
            $query->where('id', $filters['order']);
        }

        if ($filters['invoice'] !== null) {
            $query->where('fatura_id', $filters['invoice']);
        }

        if ($filters['material'] !== null && Schema::hasTable('loja_encomenda_itens')) {
            $query->whereExists(function (Builder $items) use ($filters): void {
                $items->selectRaw('1')
                    ->from('loja_encomenda_itens')
                    ->whereColumn('loja_encomenda_itens.loja_encomenda_id', 'loja_encomendas.id')
                    ->where('loja_encomenda_itens.article_id', $filters['material']);
            });
        }

        return $query->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storeInvoiceLinkFindings(Collection $storeOrders, Collection $storeItems, Collection $invoiceItems): array
    {
        if ($storeOrders->isEmpty() || ! Schema::hasTable('invoices')) {
            return [];
        }

        $linkedInvoiceIds = $storeOrders->pluck('invoice_id')->filter()->map('strval')->unique()->values();
        $originOrderIds = $storeOrders->pluck('id')->filter()->map('strval')->unique()->values();

        $invoices = DB::table('invoices')
            ->where(function (Builder $query) use ($linkedInvoiceIds, $originOrderIds): void {
                if ($linkedInvoiceIds->isNotEmpty()) {
                    $query->whereIn('id', $linkedInvoiceIds->all());
                }

                if ($originOrderIds->isNotEmpty()) {
                    $method = $linkedInvoiceIds->isNotEmpty() ? 'orWhere' : 'where';
                    $query->{$method}(function (Builder $origin) use ($originOrderIds): void {
                        $origin->where('origem_tipo', 'store_order')->whereIn('origem_id', $originOrderIds->all());
                    });
                }
            })
            ->get()
            ->keyBy(fn (object $invoice): string => (string) $invoice->id);

        $invoiceIds = $invoices->keys()->map('strval')->values();
        $confirmedAllocationTotals = collect();
        if ($invoiceIds->isNotEmpty() && Schema::hasTable('payment_allocations') && Schema::hasTable('payments')) {
            $confirmedAllocationTotals = DB::table('payment_allocations')
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->whereIn('payment_allocations.invoice_id', $invoiceIds->all())
                ->where('payment_allocations.status', 'confirmed')
                ->where('payments.status', 'confirmed')
                ->selectRaw('payment_allocations.invoice_id as invoice_id, SUM(payment_allocations.amount) as confirmed_amount')
                ->groupBy('payment_allocations.invoice_id')
                ->pluck('confirmed_amount', 'invoice_id');
        }

        $fiscalRequests = collect();
        if ($invoiceIds->isNotEmpty() && Schema::hasTable('fiscal_document_requests')) {
            $fiscalRequests = DB::table('fiscal_document_requests')
                ->whereIn('invoice_id', $invoiceIds->all())
                ->when(Schema::hasColumn('fiscal_document_requests', 'deleted_at'), fn (Builder $query) => $query->whereNull('deleted_at'))
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->get()
                ->groupBy(fn (object $request): string => (string) $request->invoice_id);
        }

        $findings = [];

        foreach ($storeOrders as $order) {
            $orderId = (string) $order->id;
            $invoiceId = (string) ($order->invoice_id ?? '');
            $canonicalByOrigin = $invoices->first(fn (object $invoice): bool => (string) ($invoice->origem_tipo ?? '') === 'store_order'
                && (string) ($invoice->origem_id ?? '') === $orderId);

            if ($invoiceId === '') {
                $findings[] = $this->finding(
                    'info',
                    $canonicalByOrigin ? 'store_order_invoice_link_missing' : 'store_order_legacy_without_invoice',
                    'store_order',
                    $canonicalByOrigin !== null,
                    $canonicalByOrigin ? 'relink_existing_store_invoice' : 'no_automatic_backfill_for_legacy_order',
                    extra: ['order_id' => $orderId, 'invoice_id' => $canonicalByOrigin?->id],
                );
                continue;
            }

            $invoice = $invoices->get($invoiceId);
            if (! $invoice) {
                $findings[] = $this->finding('critical', 'store_order_invoice_reference_invalid', 'store_order', true, 'inspect_store_invoice_link', extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId]);
                continue;
            }

            $expectedUserId = (string) (($order->target_user_id ?? null) ?: $order->user_id);
            $contractMatches = (string) ($invoice->origem_tipo ?? '') === 'store_order'
                && (string) ($invoice->origem_id ?? '') === $orderId
                && (string) ($invoice->user_id ?? '') === $expectedUserId
                && abs((float) ($invoice->valor_total ?? 0) - (float) $order->total) <= 0.009;

            if (! $contractMatches) {
                $findings[] = $this->finding('critical', 'store_order_invoice_contract_mismatch', 'store_order', true, 'inspect_store_invoice_contract', extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId]);
                continue;
            }

            $orderLines = $storeItems->where('order_id', $orderId);
            $invoiceLines = $invoiceItems->where('invoice_id', $invoiceId);
            $lineContractMatches = $orderLines->count() === $invoiceLines->count()
                && abs((float) $orderLines->sum(fn (object $item): float => (float) ($item->total_linha ?? 0))
                    - (float) $invoiceLines->sum(fn (object $item): float => (float) ($item->total_linha ?? 0))) <= 0.009;

            $findings[] = $this->finding(
                $lineContractMatches ? 'info' : 'critical',
                $lineContractMatches ? 'store_order_invoice_contract_clean' : 'store_order_invoice_items_mismatch',
                'store_order',
                ! $lineContractMatches,
                $lineContractMatches ? 'no_action_needed_store_invoice_clean' : 'inspect_store_invoice_items',
                extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId],
            );

            array_push($findings, ...$this->storeFinancialProjectionFindings(
                $order,
                $invoice,
                (float) ($confirmedAllocationTotals->get($invoiceId) ?? 0),
                $fiscalRequests->get($invoiceId, collect()),
            ));
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storeFinancialProjectionFindings(object $order, object $invoice, float $confirmedAllocated, Collection $requests): array
    {
        $invoiceId = (string) $invoice->id;
        $orderId = (string) $order->id;
        $total = round((float) ($invoice->valor_total ?? 0), 2);
        $paid = round((float) ($invoice->valor_pago ?? 0), 2);
        $open = round((float) ($invoice->valor_em_aberto ?? 0), 2);
        $confirmedAllocated = round($confirmedAllocated, 2);
        $status = (string) ($invoice->estado_pagamento ?? '');
        $expectedOpen = round(max($total - $confirmedAllocated, 0), 2);

        $expectedStatusMatches = match (true) {
            $status === 'cancelado' => $confirmedAllocated <= 0.009,
            $expectedOpen <= 0.009 => $status === 'pago',
            $confirmedAllocated > 0.009 => $status === 'parcial',
            default => in_array($status, ['pendente', 'vencido'], true),
        };
        $projectionMatches = abs($paid - $confirmedAllocated) <= 0.009
            && abs($open - ($status === 'cancelado' ? 0 : $expectedOpen)) <= 0.009
            && $expectedStatusMatches;

        $findings = [
            $this->finding(
                $projectionMatches ? 'info' : 'critical',
                $projectionMatches ? 'store_order_payment_projection_clean' : 'store_order_payment_projection_mismatch',
                'store_order',
                ! $projectionMatches,
                $projectionMatches ? 'no_action_needed_store_payment_projection_clean' : 'reconcile_store_invoice_with_confirmed_allocations',
                extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId],
            ),
        ];

        if (in_array($this->normalizeStatus($order->status ?? null), self::RETURNED_STORE_STATUSES, true)) {
            $creditNote = $requests->first(fn (object $request): bool => (string) $request->document_type === FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE);
            $registeredOriginals = $requests->filter(fn (object $request): bool => (string) $request->document_type !== FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE
                && (filled($request->external_document_number ?? null) || filled($request->external_document_id ?? null)));
            $fiscalReversalClean = $registeredOriginals->isEmpty() || (
                $creditNote
                && (string) $creditNote->status === FiscalDocumentRequest::STATUS_ISSUED
                && (filled($creditNote->external_document_number ?? null) || filled($creditNote->external_document_id ?? null))
                && $registeredOriginals->every(fn (object $request): bool => (string) $request->status === FiscalDocumentRequest::STATUS_CANCELLED)
            );
            $findings[] = $this->finding(
                $fiscalReversalClean ? 'info' : 'critical',
                $fiscalReversalClean ? 'store_order_return_fiscal_reversal_clean' : 'store_order_return_fiscal_reversal_incomplete',
                'store_order',
                ! $fiscalReversalClean,
                $fiscalReversalClean ? 'no_action_needed_store_return_fiscal_closed' : 'complete_store_return_fiscal_reversal',
                extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId],
            );

            return $findings;
        }

        $currentRequest = $requests
            ->first(fn (object $request): bool => ! in_array((string) $request->status, [
                FiscalDocumentRequest::STATUS_CANCELLED,
                FiscalDocumentRequest::STATUS_NOT_APPLICABLE,
            ], true));

        if ($status === 'pago') {
            if (! $currentRequest) {
                $findings[] = $this->finding('critical', 'store_order_paid_fiscal_request_missing', 'store_order', true, 'create_missing_store_fiscal_request_via_canonical_service', extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId]);

                return $findings;
            }

            $fiscalContractMatches = (string) $currentRequest->provider === FiscalDocumentRequest::PROVIDER_WINTOUCH
                && (string) $currentRequest->document_type === FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT;
            $findings[] = $this->finding(
                $fiscalContractMatches ? 'info' : 'critical',
                $fiscalContractMatches ? 'store_order_paid_fiscal_request_created' : 'store_order_fiscal_request_contract_mismatch',
                'store_order',
                ! $fiscalContractMatches,
                $fiscalContractMatches ? 'issue_manually_in_wintouch_and_record_external_number' : 'inspect_store_fiscal_request_contract',
                extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId],
            );

            return $findings;
        }

        if ($currentRequest) {
            $findings[] = $this->finding('warning', 'store_order_fiscal_request_before_full_payment', 'store_order', true, 'inspect_premature_store_fiscal_request', extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId]);
        } else {
            $findings[] = $this->finding('info', 'store_order_fiscal_projection_not_due', 'store_order', false, 'no_action_needed_until_store_invoice_is_paid', extra: ['order_id' => $orderId, 'invoice_id' => $invoiceId]);
        }

        return $findings;
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
                'payments' => Schema::hasTable('payments'),
                'payment_allocations' => Schema::hasTable('payment_allocations'),
                'payment_reversals' => Schema::hasTable('payment_reversals'),
                'fiscal_document_requests' => Schema::hasTable('fiscal_document_requests'),
                'loja_encomenda_devolucoes' => Schema::hasTable('loja_encomenda_devolucoes'),
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
                'loja_encomenda_itens.total_linha',
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
                'invoice_items.total_linha',
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
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function storeReturns(array $filters): Collection
    {
        if (! Schema::hasTable('loja_encomenda_devolucoes')) {
            return collect();
        }

        return DB::table('loja_encomenda_devolucoes as returns')
            ->join('loja_encomendas as orders', 'orders.id', '=', 'returns.loja_encomenda_id')
            ->leftJoin('invoices', 'invoices.id', '=', 'returns.fatura_id')
            ->leftJoin('fiscal_document_requests as credit_notes', 'credit_notes.id', '=', 'returns.fiscal_document_request_id')
            ->select([
                'returns.id',
                'returns.loja_encomenda_id as order_id',
                'returns.fatura_id as invoice_id',
                'returns.estado as return_status',
                'returns.reversao_financeira_em',
                'returns.stock_reposto_em',
                'returns.concluida_em',
                'orders.estado as order_status',
                'invoices.estado_pagamento as invoice_status',
                'credit_notes.document_type as credit_note_type',
                'credit_notes.status as credit_note_status',
                'credit_notes.external_document_number as credit_note_number',
                'credit_notes.external_document_id as credit_note_external_id',
            ])
            ->when($filters['order'] !== null, fn (Builder $query) => $query->where('orders.id', $filters['order']))
            ->when($filters['invoice'] !== null, fn (Builder $query) => $query->where('returns.fatura_id', $filters['invoice']))
            ->orderBy('returns.created_at')
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function storeReturnFindings(Collection $storeReturns): array
    {
        $findings = [];

        foreach ($storeReturns as $return) {
            $returnId = (string) $return->id;
            $reversalCount = Schema::hasTable('payment_reversals')
                ? DB::table('payment_reversals')->where('source_type', 'store_order_return')->where('source_id', $returnId)->count()
                : 0;
            $cancelledAllocationCount = Schema::hasTable('payment_allocations')
                ? DB::table('payment_allocations')
                    ->where('invoice_id', $return->invoice_id)
                    ->where('status', 'cancelled')
                    ->where('metadata->reversal_source_id', $returnId)
                    ->count()
                : 0;
            $registeredOriginals = Schema::hasTable('fiscal_document_requests')
                ? DB::table('fiscal_document_requests')
                    ->where('invoice_id', $return->invoice_id)
                    ->where('document_type', '!=', FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE)
                    ->where(function (Builder $query): void {
                        $query->whereNotNull('external_document_number')->where('external_document_number', '!=', '')
                            ->orWhereNotNull('external_document_id')->where('external_document_id', '!=', '');
                    })
                    ->get()
                : collect();

            if ((string) $return->return_status === 'aguarda_nota_credito') {
                $clean = (string) $return->order_status === 'entregue'
                    && (string) $return->credit_note_type === FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE
                    && (string) $return->credit_note_status !== FiscalDocumentRequest::STATUS_ISSUED
                    && $return->reversao_financeira_em === null
                    && $return->stock_reposto_em === null
                    && $reversalCount === 0;
                $findings[] = $this->finding(
                    $clean ? 'info' : 'critical',
                    $clean ? 'store_order_return_awaiting_credit_note_clean' : 'store_order_return_awaiting_credit_note_inconsistent',
                    'store_order_return',
                    ! $clean,
                    $clean ? 'issue_credit_note_manually_before_return_completion' : 'inspect_store_return_sequence',
                    extra: ['order_id' => (string) $return->order_id, 'return_id' => $returnId],
                );
                continue;
            }

            $creditNoteRequired = $registeredOriginals->isNotEmpty();
            $creditNoteClean = ! $creditNoteRequired || (
                (string) $return->credit_note_type === FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE
                && (string) $return->credit_note_status === FiscalDocumentRequest::STATUS_ISSUED
                && (filled($return->credit_note_number) || filled($return->credit_note_external_id))
                && $registeredOriginals->every(fn (object $request): bool => (string) $request->status === FiscalDocumentRequest::STATUS_CANCELLED)
            );
            $financialHistoryClean = $reversalCount === $cancelledAllocationCount;
            $completedClean = (string) $return->return_status === 'concluida'
                && (string) $return->order_status === 'devolvido'
                && (string) $return->invoice_status === 'cancelado'
                && $return->reversao_financeira_em !== null
                && $return->stock_reposto_em !== null
                && $return->concluida_em !== null
                && $creditNoteClean
                && $financialHistoryClean;

            $findings[] = $this->finding(
                $completedClean ? 'info' : 'critical',
                $completedClean ? 'store_order_return_reversal_clean' : 'store_order_return_reversal_incomplete',
                'store_order_return',
                ! $completedClean,
                $completedClean ? 'no_action_needed_store_return_closed' : 'inspect_store_return_sequence',
                extra: [
                    'order_id' => (string) $return->order_id,
                    'return_id' => $returnId,
                    'payment_reversal_count' => $reversalCount,
                    'cancelled_allocation_count' => $cancelledAllocationCount,
                ],
            );
        }

        return $findings;
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

            if (in_array($status, [...self::CANCELLED_STORE_STATUSES, ...self::RETURNED_STORE_STATUSES], true)) {
                $extra = ['order_id' => (string) ($item->order_id ?? ''), 'store_order_item_id' => (string) $item->id, 'status' => $item->status ?? null];
                $isReturn = in_array($status, self::RETURNED_STORE_STATUSES, true);
                $codePrefix = $isReturn ? 'store_order_returned' : 'store_order_cancelled';

                if ($metrics['exit_qty'] === 0 && $metrics['return_qty'] === 0) {
                    $findings[] = $this->finding('info', $codePrefix.'_without_stock_impact', 'store_order_item', false, 'no_action_needed_store_stock_clean', $product, $quantity, $metrics, $extra);
                } elseif ($metrics['exit_count'] === 1
                    && $metrics['exit_qty'] === $quantity
                    && $metrics['return_qty'] === $metrics['exit_qty']
                    && $metrics['physical_net'] === 0) {
                    $findings[] = $this->finding('info', $codePrefix.'_stock_restored', 'store_order_item', false, 'no_action_needed_store_stock_clean', $product, $quantity, $metrics, $extra);
                } elseif ($metrics['return_qty'] > $metrics['exit_qty']) {
                    $findings[] = $this->finding('critical', $codePrefix.'_stock_over_restored', 'store_order_item', true, 'inspect_store_quantity_mismatch', $product, $quantity, $metrics, $extra);
                } else {
                    $findings[] = $this->finding('warning', $codePrefix.'_stock_not_restored', 'store_order_item', true, 'restore_cancelled_store_order_stock', $product, $quantity, $metrics, $extra);
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
    private function summary(Collection $storeOrders, Collection $storeItems, Collection $invoiceItems, Collection $logisticsItems, Collection $movements, Collection $storeReturns, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_store_orders_scanned' => $storeOrders->count(),
            'total_store_order_items_scanned' => $storeItems->count(),
            'canonical_invoice_linked_count' => $findingsCollection->where('code', 'store_order_invoice_contract_clean')->count(),
            'legacy_without_invoice_count' => $findingsCollection->where('code', 'store_order_legacy_without_invoice')->count(),
            'missing_invoice_link_count' => $findingsCollection->where('code', 'store_order_invoice_link_missing')->count(),
            'invalid_invoice_link_count' => $findingsCollection->where('code', 'store_order_invoice_reference_invalid')->count(),
            'invoice_contract_mismatch_count' => $findingsCollection->whereIn('code', ['store_order_invoice_contract_mismatch', 'store_order_invoice_items_mismatch'])->count(),
            'payment_projection_clean_count' => $findingsCollection->where('code', 'store_order_payment_projection_clean')->count(),
            'payment_projection_mismatch_count' => $findingsCollection->where('code', 'store_order_payment_projection_mismatch')->count(),
            'paid_fiscal_request_created_count' => $findingsCollection->where('code', 'store_order_paid_fiscal_request_created')->count(),
            'paid_fiscal_request_missing_count' => $findingsCollection->where('code', 'store_order_paid_fiscal_request_missing')->count(),
            'premature_fiscal_request_count' => $findingsCollection->where('code', 'store_order_fiscal_request_before_full_payment')->count(),
            'fiscal_request_contract_mismatch_count' => $findingsCollection->where('code', 'store_order_fiscal_request_contract_mismatch')->count(),
            'total_store_returns_scanned' => $storeReturns->count(),
            'store_returns_awaiting_credit_note_count' => $findingsCollection->where('code', 'store_order_return_awaiting_credit_note_clean')->count(),
            'store_returns_completed_clean_count' => $findingsCollection->where('code', 'store_order_return_reversal_clean')->count(),
            'store_returns_inconsistent_count' => $findingsCollection->whereIn('code', [
                'store_order_return_awaiting_credit_note_inconsistent',
                'store_order_return_reversal_incomplete',
                'store_order_return_fiscal_reversal_incomplete',
            ])->count(),
            'total_invoice_items_scanned' => $invoiceItems->count(),
            'total_logistics_movements_scanned' => $logisticsItems->pluck('request_id')->unique()->count(),
            'total_related_stock_movements' => $movements->count(),
            'missing_physical_exit_count' => $findingsCollection->whereIn('code', ['store_order_missing_physical_exit', 'invoice_item_missing_physical_exit'])->count(),
            'duplicate_physical_exit_count' => $findingsCollection->whereIn('code', ['store_order_duplicate_physical_exit', 'invoice_item_duplicate_physical_exit'])->count(),
            'cancelled_stock_restored_count' => $findingsCollection->where('code', 'store_order_cancelled_stock_restored')->count(),
            'cancelled_without_stock_impact_count' => $findingsCollection->where('code', 'store_order_cancelled_without_stock_impact')->count(),
            'cancelled_stock_unbalanced_count' => $findingsCollection->whereIn('code', ['store_order_cancelled_stock_not_restored', 'store_order_cancelled_stock_over_restored'])->count(),
            'returned_stock_restored_count' => $findingsCollection->where('code', 'store_order_returned_stock_restored')->count(),
            'returned_stock_unbalanced_count' => $findingsCollection->whereIn('code', ['store_order_returned_stock_not_restored', 'store_order_returned_stock_over_restored'])->count(),
            'invoice_store_duplicate_exit_count' => $findingsCollection->where('code', 'invoice_store_duplicate_stock_exit')->count(),
            'logistics_store_duplicate_exit_count' => $findingsCollection->where('code', 'logistics_store_duplicate_stock_exit')->count(),
            'quantity_mismatch_count' => $findingsCollection->whereIn('code', ['store_order_quantity_mismatch', 'invoice_item_quantity_mismatch'])->count(),
            'invalid_product_count' => $findingsCollection->where('code', 'store_stock_invalid_product')->count(),
            'invalid_quantity_count' => $findingsCollection->where('code', 'store_stock_invalid_quantity')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'store_stock_invalid_source_reference')->count(),
            'legacy_corrected_by_audit_count' => $findingsCollection->where('code', 'store_stock_legacy_corrected_by_audit')->count(),
            'clean_count' => $findingsCollection->whereIn('code', ['store_order_stock_clean', 'store_order_cancelled_stock_restored', 'store_order_cancelled_without_stock_impact', 'store_order_returned_stock_restored', 'store_order_returned_without_stock_impact', 'store_order_return_reversal_clean', 'store_order_return_fiscal_reversal_clean', 'store_order_return_awaiting_credit_note_clean', 'invoice_item_stock_clean', 'store_stock_legacy_corrected_by_audit', 'store_logistics_stock_clean'])->count(),
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
