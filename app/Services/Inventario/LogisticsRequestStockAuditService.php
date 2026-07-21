<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class LogisticsRequestStockAuditService
{
    private const VERSION = 'b4-logistics-request-stock-audit-v1';
    private const SOURCE = 'logistics_request';

    private const OPEN_RESERVED_STATUSES = ['approved', 'invoiced', 'reserved', 'aprovado', 'faturado'];
    private const OPEN_UNRESERVED_STATUSES = ['draft', 'pending', 'rascunho', 'pendente'];
    private const DELIVERED_STATUSES = ['delivered', 'completed', 'entregue', 'concluido', 'concluído'];
    private const CLOSED_WITHOUT_STOCK_STATUSES = ['cancelled', 'canceled', 'rejected', 'cancelado', 'rejeitado'];
    private const RETURNED_STATUSES = ['returned', 'devolvido'];

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

        $requests = $this->requests($filters);
        $items = $this->requestItems($filters, $requests);
        $products = $this->products($filters, $items);
        $movements = $this->stockMovements($filters, $requests);

        $findings = [];

        foreach ($requests as $request) {
            array_push($findings, ...$this->requestFindings($request, $items, $products, $movements));
        }

        array_push($findings, ...$this->sourceReferenceFindings($movements, $requests, $products));
        array_push($findings, ...$this->orphanSourceFindings($movements, $requests, $products));
        array_push($findings, ...$this->legacyUnlinkedMovementFindings($movements, $products));

        $findings = $this->uniqueFindings($findings);

        if ($findings === []) {
            $findings[] = $this->finding('info', 'logistics_request_stock_clean', false, 'no_action_needed_logistics_request_clean');
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($requests, $items, $movements, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{request:?string,material:?string,only_actionable:bool}
     */
    private function filters(array $options): array
    {
        return [
            'request' => $this->stringOrNull($options['request'] ?? null),
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
            'request_table' => Schema::hasTable('logistics_requests') ? 'logistics_requests' : null,
            'request_item_table' => Schema::hasTable('logistics_request_items') ? 'logistics_request_items' : null,
            'stock_movement_table' => Schema::hasTable('stock_movements') ? 'stock_movements' : null,
            'request_fields' => [
                'status' => Schema::hasColumn('logistics_requests', 'status') ? 'status' : null,
                'approved_at' => Schema::hasColumn('logistics_requests', 'approved_at') ? 'approved_at' : null,
                'delivered_at' => Schema::hasColumn('logistics_requests', 'delivered_at') ? 'delivered_at' : null,
                'created_by' => Schema::hasColumn('logistics_requests', 'created_by') ? 'created_by' : null,
            ],
            'request_item_fields' => [
                'product_column' => Schema::hasColumn('logistics_request_items', 'article_id') ? 'article_id' : null,
                'quantity_column' => Schema::hasColumn('logistics_request_items', 'quantity') ? 'quantity' : null,
            ],
            'stock_movement_reference_fields' => [
                'source_type_column' => Schema::hasColumn('stock_movements', 'reference_type') ? 'reference_type' : null,
                'source_id_column' => Schema::hasColumn('stock_movements', 'reference_id') ? 'reference_id' : null,
                'source_id_is_uuid' => true,
            ],
            'source_types' => [self::SOURCE],
            'status_semantics' => [
                'open_unreserved' => self::OPEN_UNRESERVED_STATUSES,
                'open_reserved' => self::OPEN_RESERVED_STATUSES,
                'delivered' => self::DELIVERED_STATUSES,
                'closed_without_stock' => self::CLOSED_WITHOUT_STOCK_STATUSES,
                'returned' => self::RETURNED_STATUSES,
            ],
            'soft_delete_supported' => [
                'logistics_requests' => Schema::hasColumn('logistics_requests', 'deleted_at'),
                'logistics_request_items' => Schema::hasColumn('logistics_request_items', 'deleted_at'),
                'stock_movements' => Schema::hasColumn('stock_movements', 'deleted_at'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function requests(array $filters): Collection
    {
        if (! Schema::hasTable('logistics_requests')) {
            return collect();
        }

        $query = DB::table('logistics_requests')->orderBy('created_at')->orderBy('id');

        if ($filters['request']) {
            $query->where('id', $filters['request']);
        }

        if ($filters['material'] && Schema::hasTable('logistics_request_items')) {
            $query->whereExists(function (Builder $exists) use ($filters): void {
                $exists->selectRaw('1')
                    ->from('logistics_request_items')
                    ->whereColumn('logistics_request_items.logistics_request_id', 'logistics_requests.id')
                    ->where('logistics_request_items.article_id', $filters['material']);
            });
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $requests
     * @return Collection<int,object>
     */
    private function requestItems(array $filters, Collection $requests): Collection
    {
        if (! Schema::hasTable('logistics_request_items')) {
            return collect();
        }

        $requestIds = $requests->pluck('id')->filter()->values()->all();
        if ($requestIds === []) {
            return collect();
        }

        $query = DB::table('logistics_request_items')->whereIn('logistics_request_id', $requestIds)->orderBy('created_at')->orderBy('id');

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
     * @param Collection<int,object> $requests
     * @return Collection<int,object>
     */
    private function stockMovements(array $filters, Collection $requests): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        }

        if ($filters['request']) {
            $query->where('reference_type', self::SOURCE)->where('reference_id', $filters['request']);

            return $query->get();
        }

        $requestIds = $requests->pluck('id')->filter()->map('strval')->values()->all();

        $query->where(function (Builder $query) use ($requestIds): void {
            $query->where('reference_type', self::SOURCE);

            if ($requestIds !== []) {
                $query->orWhere(function (Builder $linked) use ($requestIds): void {
                    $linked->where('reference_type', self::SOURCE)->whereIn('reference_id', $requestIds);
                });
            }

            $query->orWhere(function (Builder $legacy): void {
                $legacy->where(function (Builder $source): void {
                    $source->whereNull('reference_type')->orWhere('reference_type', '');
                })->where(function (Builder $notes): void {
                    $notes->where('notes', 'like', '%requisi%')
                        ->orWhere('notes', 'like', '%logistic%')
                        ->orWhere('notes', 'like', '%reserva%');
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
    private function requestFindings(object $request, Collection $items, Collection $products, Collection $movements): array
    {
        $findings = [];
        $requestItems = $items->where('logistics_request_id', $request->id)->values();

        if ($requestItems->isEmpty()) {
            return [$this->finding('warning', 'logistics_request_invalid_quantity', true, 'inspect_quantity_mismatch', request: $request)];
        }

        $itemsByMaterial = $requestItems->groupBy(fn (object $item): string => (string) ($item->article_id ?? ''));
        foreach ($itemsByMaterial as $materialId => $materialItems) {
            $product = $products->get((string) $materialId);
            $quantity = (int) $materialItems->sum(fn (object $item): int => (int) ($item->quantity ?? 0));
            $materialMovements = $this->movementsFor($movements, (string) $request->id, (string) $materialId);
            $metrics = $this->movementMetrics($materialMovements);
            $status = $this->normalizeStatus($request->status ?? null);

            if ($this->blank($materialId) || $product === null) {
                $findings[] = $this->finding('critical', 'logistics_request_invalid_product', true, 'inspect_quantity_mismatch', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
            }

            if ($quantity <= 0) {
                $findings[] = $this->finding('warning', 'logistics_request_invalid_quantity', true, 'inspect_quantity_mismatch', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
            }

            array_push($findings, ...$this->duplicateActionFindings($request, $product, $materialMovements, $quantity, $metrics));

            if (in_array($status, self::OPEN_RESERVED_STATUSES, true)) {
                if ($metrics['reserved_net'] <= 0 && $quantity > 0) {
                    $findings[] = $this->finding('warning', 'logistics_request_missing_reservation', true, 'create_missing_reservation', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif ($metrics['reserved_net'] !== $quantity) {
                    $findings[] = $this->finding('warning', 'logistics_request_quantity_mismatch', true, 'inspect_quantity_mismatch', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif ($metrics['physical_net'] === 0) {
                    $findings[] = $this->finding('info', 'logistics_request_stock_clean', false, 'no_action_needed_logistics_request_clean', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }
            } elseif (in_array($status, self::DELIVERED_STATUSES, true)) {
                if ($metrics['reserved_net'] > 0) {
                    $findings[] = $this->finding('warning', 'logistics_request_closed_with_reserved_stock', true, 'release_stale_reservation', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                    $findings[] = $this->finding('warning', 'logistics_request_missing_reservation_release', true, 'release_stale_reservation', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }

                if ($metrics['exit_qty'] <= 0 && $quantity > 0) {
                    $findings[] = $this->finding('critical', 'logistics_request_missing_physical_exit', true, 'create_missing_physical_exit', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif (abs($metrics['physical_net']) !== $quantity) {
                    $code = abs($metrics['physical_net']) > $quantity ? 'logistics_request_missing_return' : 'logistics_request_quantity_mismatch';
                    $recommendation = $code === 'logistics_request_missing_return' ? 'create_missing_return' : 'inspect_quantity_mismatch';
                    $findings[] = $this->finding('warning', $code, true, $recommendation, request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif ($metrics['reserved_net'] === 0) {
                    $findings[] = $this->finding('info', 'logistics_request_reservation_cycle_clean', false, 'no_action_needed_logistics_request_clean', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }
            } elseif (in_array($status, self::RETURNED_STATUSES, true)) {
                if ($metrics['return_qty'] <= 0 && $metrics['exit_qty'] > 0) {
                    $findings[] = $this->finding('warning', 'logistics_request_missing_return', true, 'create_missing_return', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif ($metrics['reserved_net'] === 0 && $metrics['physical_net'] === 0) {
                    $findings[] = $this->finding('info', 'logistics_request_reservation_cycle_clean', false, 'no_action_needed_logistics_request_clean', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }
            } elseif (in_array($status, self::CLOSED_WITHOUT_STOCK_STATUSES, true)) {
                if ($metrics['reserved_net'] > 0) {
                    $findings[] = $this->finding('warning', 'logistics_request_closed_with_reserved_stock', true, 'release_stale_reservation', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                    $findings[] = $this->finding('warning', 'logistics_request_missing_reservation_release', true, 'release_stale_reservation', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                } elseif ($metrics['reserved_net'] === 0 && $metrics['physical_net'] === 0) {
                    $findings[] = $this->finding('info', 'logistics_request_reservation_cycle_clean', false, 'no_action_needed_logistics_request_clean', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }
            } elseif (in_array($status, self::OPEN_UNRESERVED_STATUSES, true)) {
                if ($metrics['reserved_net'] > 0) {
                    $findings[] = $this->finding('info', 'logistics_request_stock_clean', false, 'no_action_needed_logistics_request_clean', request: $request, product: $product, quantityRequest: $quantity, metrics: $metrics);
                }
            }
        }

        return $findings;
    }

    /**
     * @return Collection<int,object>
     */
    private function movementsFor(Collection $movements, string $requestId, string $materialId): Collection
    {
        return $movements
            ->filter(fn (object $movement): bool => (string) ($movement->reference_type ?? '') === self::SOURCE
                && (string) ($movement->reference_id ?? '') === $requestId
                && (string) ($movement->article_id ?? '') === $materialId)
            ->values();
    }

    /**
     * @param Collection<int,object> $movements
     * @return array{reserved_net:int,physical_net:int,reservation_positive_qty:int,reservation_release_qty:int,exit_qty:int,return_qty:int,movement_ids:list<string>}
     */
    private function movementMetrics(Collection $movements): array
    {
        $reserved = 0;
        $physical = 0;
        $reservationPositive = 0;
        $reservationRelease = 0;
        $exit = 0;
        $return = 0;

        foreach ($movements as $movement) {
            $deltas = $this->semantics->deltas($movement);
            $reserved += $deltas['reserved'];
            $physical += $deltas['physical'];

            $type = (string) ($movement->movement_type ?? '');
            $quantity = (int) ($movement->quantity ?? 0);
            if ($type === 'reservation' && $quantity > 0) {
                $reservationPositive += $quantity;
            }
            if (($type === 'reservation' && $quantity < 0) || $type === 'deliver_reservation') {
                $reservationRelease += abs($quantity);
            }
            if (in_array($type, ['exit', 'sale', 'venda'], true)) {
                $exit += abs($quantity);
            }
            if ($type === 'return') {
                $return += abs($quantity);
            }
        }

        return [
            'reserved_net' => $reserved,
            'physical_net' => $physical,
            'reservation_positive_qty' => $reservationPositive,
            'reservation_release_qty' => $reservationRelease,
            'exit_qty' => $exit,
            'return_qty' => $return,
            'movement_ids' => $movements->pluck('id')->map('strval')->values()->all(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function duplicateActionFindings(object $request, ?object $product, Collection $movements, int $quantity, array $metrics): array
    {
        $findings = [];
        $groups = $movements
            ->filter(fn (object $movement): bool => $this->actionSemantics($movement) !== 'release_reservation')
            ->groupBy(fn (object $movement): string => implode('|', [
                $this->actionSemantics($movement),
                (string) abs((int) ($movement->quantity ?? 0)),
            ]));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $findings[] = $this->finding('warning', 'logistics_request_duplicate_stock_action', true, 'inspect_duplicate_stock_action', request: $request, product: $product, quantityRequest: $quantity, metrics: [
                ...$metrics,
                'movement_ids' => $group->pluck('id')->map('strval')->values()->all(),
            ]);
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $requests
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function sourceReferenceFindings(Collection $movements, Collection $requests, Collection $products): array
    {
        $findings = [];
        $requestIds = $requests->pluck('id')->map('strval')->all();

        foreach ($movements->where('reference_type', self::SOURCE) as $movement) {
            $sourceId = $movement->reference_id ?? null;
            if ($sourceId === null || trim((string) $sourceId) === '' || ! Str::isUuid((string) $sourceId)) {
                $findings[] = $this->finding('warning', 'logistics_request_invalid_source_reference', true, 'inspect_quantity_mismatch', product: $products->get((string) ($movement->article_id ?? '')), metrics: $this->movementMetrics(collect([$movement])));

                continue;
            }

            if (! in_array((string) $sourceId, $requestIds, true)) {
                continue;
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $requests
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function orphanSourceFindings(Collection $movements, Collection $requests, Collection $products): array
    {
        $findings = [];
        $requestIds = $requests->pluck('id')->map('strval')->all();

        $groups = $movements
            ->where('reference_type', self::SOURCE)
            ->filter(fn (object $movement): bool => Str::isUuid((string) ($movement->reference_id ?? ''))
                && ! in_array((string) ($movement->reference_id ?? ''), $requestIds, true))
            ->groupBy(fn (object $movement): string => implode('|', [(string) $movement->reference_id, (string) $movement->article_id]));

        foreach ($groups as $group) {
            $metrics = $this->movementMetrics($group->values());
            if ($metrics['reserved_net'] > 0) {
                $findings[] = $this->finding('warning', 'logistics_request_open_reservation_without_open_request', true, 'release_stale_reservation', product: $products->get((string) ($group->first()->article_id ?? '')), metrics: $metrics, extra: [
                    'request_id' => (string) ($group->first()->reference_id ?? ''),
                ]);
            } elseif ($metrics['reserved_net'] === 0 && $metrics['physical_net'] === 0) {
                $findings[] = $this->finding('info', 'logistics_request_reservation_cycle_clean', false, 'no_action_needed_logistics_request_clean', product: $products->get((string) ($group->first()->article_id ?? '')), metrics: $metrics, extra: [
                    'request_id' => (string) ($group->first()->reference_id ?? ''),
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function legacyUnlinkedMovementFindings(Collection $movements, Collection $products): array
    {
        return $movements
            ->filter(fn (object $movement): bool => $this->blank($movement->reference_type ?? null) && $this->looksLikeLogisticsMovement($movement))
            ->map(fn (object $movement): array => $this->finding('info', 'logistics_request_legacy_unlinked_movement', false, 'classify_legacy_logistics_movement', product: $products->get((string) ($movement->article_id ?? '')), metrics: $this->movementMetrics(collect([$movement]))))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $requests
     * @param Collection<int,object> $items
     * @param Collection<int,object> $movements
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(Collection $requests, Collection $items, Collection $movements, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_requests_scanned' => $requests->count(),
            'total_request_items_scanned' => $items->count(),
            'total_related_stock_movements' => $movements->count(),
            'clean_cycle_count' => $findingsCollection->whereIn('code', ['logistics_request_stock_clean', 'logistics_request_reservation_cycle_clean'])->count(),
            'missing_reservation_count' => $findingsCollection->where('code', 'logistics_request_missing_reservation')->count(),
            'missing_reservation_release_count' => $findingsCollection->where('code', 'logistics_request_missing_reservation_release')->count(),
            'missing_physical_exit_count' => $findingsCollection->where('code', 'logistics_request_missing_physical_exit')->count(),
            'missing_return_count' => $findingsCollection->where('code', 'logistics_request_missing_return')->count(),
            'duplicate_stock_action_count' => $findingsCollection->where('code', 'logistics_request_duplicate_stock_action')->count(),
            'quantity_mismatch_count' => $findingsCollection->where('code', 'logistics_request_quantity_mismatch')->count(),
            'invalid_product_count' => $findingsCollection->where('code', 'logistics_request_invalid_product')->count(),
            'invalid_quantity_count' => $findingsCollection->where('code', 'logistics_request_invalid_quantity')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'logistics_request_invalid_source_reference')->count(),
            'open_reservation_without_open_request_count' => $findingsCollection->where('code', 'logistics_request_open_reservation_without_open_request')->count(),
            'closed_with_reserved_stock_count' => $findingsCollection->where('code', 'logistics_request_closed_with_reserved_stock')->count(),
            'legacy_unlinked_movement_count' => $findingsCollection->where('code', 'logistics_request_legacy_unlinked_movement')->count(),
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
                (string) ($finding['request_id'] ?? ''),
                (string) ($finding['material_id'] ?? ''),
                implode(',', array_map('strval', $finding['movement_ids'] ?? [])),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        bool $actionable,
        string $recommendation,
        ?object $request = null,
        ?object $product = null,
        ?int $quantityRequest = null,
        array $metrics = [],
        array $extra = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'request_id' => $this->prop($request, 'id') ? (string) $this->prop($request, 'id') : null,
            'status' => $this->prop($request, 'status'),
            'material_id' => $this->prop($product, 'id') ? (string) $this->prop($product, 'id') : null,
            'material_name' => $this->prop($product, 'nome'),
            'quantity_request' => $quantityRequest,
            'reserved_net' => $metrics['reserved_net'] ?? null,
            'physical_net' => $metrics['physical_net'] ?? null,
            'reservation_positive_qty' => $metrics['reservation_positive_qty'] ?? null,
            'reservation_release_qty' => $metrics['reservation_release_qty'] ?? null,
            'exit_qty' => $metrics['exit_qty'] ?? null,
            'return_qty' => $metrics['return_qty'] ?? null,
            'movement_ids' => $metrics['movement_ids'] ?? [],
            'classification_reason' => $extra['classification_reason'] ?? $code,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            ...$extra,
        ];
    }

    private function actionSemantics(object $movement): string
    {
        $type = (string) ($movement->movement_type ?? '');
        $quantity = (int) ($movement->quantity ?? 0);

        return match ($type) {
            'reservation' => $quantity > 0 ? 'reserve' : ($quantity < 0 ? 'release_reservation' : 'unknown'),
            'deliver_reservation' => 'release_reservation',
            'exit', 'sale', 'venda' => 'exit',
            'return' => 'return',
            'cancel_reservation' => 'release_reservation',
            default => 'unknown',
        };
    }

    private function normalizeStatus(mixed $status): string
    {
        return trim(mb_strtolower((string) $status));
    }

    private function looksLikeLogisticsMovement(object $movement): bool
    {
        $notes = mb_strtolower((string) ($movement->notes ?? ''));

        return str_contains($notes, 'requisi')
            || str_contains($notes, 'logistic')
            || str_contains($notes, 'reserva');
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
