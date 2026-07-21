<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Services\Inventario\EquipmentLoanStockResolutionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EquipmentLoanStockAuditService
{
    private const VERSION = 'b5-equipment-loan-stock-audit-v1';

    private const LOAN_SOURCE = 'equipment_loan';
    private const UPDATE_SOURCE = 'equipment_loan_update';
    private const DELETE_SOURCE = 'equipment_loan_delete';
    private const RETURN_SOURCE = 'equipment_loan_return';
    private const DELETED_SOURCE = 'equipment_loan_deleted';

    private const ACTIVE_STATUSES = ['active', 'overdue', 'ativo', 'atrasado'];
    private const RETURNED_STATUSES = ['returned', 'devolvido'];
    private const CLOSED_STATUSES = ['cancelled', 'canceled', 'deleted', 'voided', 'cancelado', 'eliminado', 'anulado'];

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

        $loans = $this->loans($filters);
        $products = $this->products($filters, $loans);
        $movements = $this->stockMovements($filters, $loans);

        $findings = [];

        foreach ($loans as $loan) {
            array_push($findings, ...$this->loanFindings($loan, $products, $movements));
        }

        array_push($findings, ...$this->sourceReferenceFindings($movements, $loans, $products));
        array_push($findings, ...$this->deletedLoanMovementFindings($movements, $loans, $products));
        array_push($findings, ...$this->legacyUnlinkedMovementFindings($movements, $products));

        $findings = $this->uniqueFindings($findings);

        if ($findings === []) {
            $findings[] = $this->finding('info', 'equipment_loan_stock_clean', false, 'no_action_needed_equipment_loan_clean');
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $schema,
            'summary' => $this->summary($loans, $movements, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{loan:?string,material:?string,only_actionable:bool}
     */
    private function filters(array $options): array
    {
        return [
            'loan' => $this->stringOrNull($options['loan'] ?? null),
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
            'loan_table' => Schema::hasTable('equipment_loans') ? 'equipment_loans' : null,
            'stock_movement_table' => Schema::hasTable('stock_movements') ? 'stock_movements' : null,
            'product_table' => Schema::hasTable('products') ? 'products' : null,
            'loan_fields' => [
                'product_column' => Schema::hasColumn('equipment_loans', 'article_id') ? 'article_id' : null,
                'quantity_column' => Schema::hasColumn('equipment_loans', 'quantity') ? 'quantity' : null,
                'status' => Schema::hasColumn('equipment_loans', 'status') ? 'status' : null,
                'loaned_at' => Schema::hasColumn('equipment_loans', 'loan_date') ? 'loan_date' : null,
                'borrowed_at' => Schema::hasColumn('equipment_loans', 'borrowed_at') ? 'borrowed_at' : null,
                'created_at' => Schema::hasColumn('equipment_loans', 'created_at') ? 'created_at' : null,
                'returned_at' => Schema::hasColumn('equipment_loans', 'return_date') ? 'return_date' : null,
                'due_date' => Schema::hasColumn('equipment_loans', 'due_date') ? 'due_date' : null,
                'borrower_user_id' => Schema::hasColumn('equipment_loans', 'borrower_user_id') ? 'borrower_user_id' : null,
                'created_by' => Schema::hasColumn('equipment_loans', 'created_by') ? 'created_by' : null,
                'updated_by' => Schema::hasColumn('equipment_loans', 'updated_by') ? 'updated_by' : null,
            ],
            'stock_movement_reference_fields' => [
                'source_type_column' => Schema::hasColumn('stock_movements', 'reference_type') ? 'reference_type' : null,
                'source_id_column' => Schema::hasColumn('stock_movements', 'reference_id') ? 'reference_id' : null,
                'source_id_is_uuid' => true,
            ],
            'source_types' => $this->sourceTypes(),
            'status_semantics' => [
                'active' => self::ACTIVE_STATUSES,
                'returned' => self::RETURNED_STATUSES,
                'closed' => self::CLOSED_STATUSES,
            ],
            'soft_delete_supported' => [
                'equipment_loans' => Schema::hasColumn('equipment_loans', 'deleted_at'),
                'stock_movements' => Schema::hasColumn('stock_movements', 'deleted_at'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function loans(array $filters): Collection
    {
        if (! Schema::hasTable('equipment_loans')) {
            return collect();
        }

        $query = DB::table('equipment_loans')->orderBy('created_at')->orderBy('id');

        if ($filters['loan']) {
            $query->where('id', $filters['loan']);
        }

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,object> $loans
     * @return Collection<string,object>
     */
    private function products(array $filters, Collection $loans): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        $ids = $loans->pluck('article_id')->filter()->map('strval')->unique()->values()->all();
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
     * @param Collection<int,object> $loans
     * @return Collection<int,object>
     */
    private function stockMovements(array $filters, Collection $loans): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');

        if ($filters['material']) {
            $query->where('article_id', $filters['material']);
        }

        if ($filters['loan']) {
            $query->whereIn('reference_type', $this->sourceTypes())->where('reference_id', $filters['loan']);

            return $query->get();
        }

        $loanIds = $loans->pluck('id')->filter()->map('strval')->values()->all();

        $query->where(function (Builder $query) use ($loanIds): void {
            $query->whereIn('reference_type', $this->sourceTypes());

            if ($loanIds !== []) {
                $query->orWhere(function (Builder $linked) use ($loanIds): void {
                    $linked->whereIn('reference_type', $this->sourceTypes())->whereIn('reference_id', $loanIds);
                });
            }

            $query->orWhere(function (Builder $legacy): void {
                $legacy->where(function (Builder $source): void {
                    $source->whereNull('reference_type')->orWhere('reference_type', '');
                })->where(function (Builder $notes): void {
                    $notes->where('notes', 'like', '%empr%')
                        ->orWhere('notes', 'like', '%loan%')
                        ->orWhere('notes', 'like', '%devolu%');
                });
            });
        });

        return $query->get();
    }

    /**
     * @param Collection<string,object> $products
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function loanFindings(object $loan, Collection $products, Collection $movements): array
    {
        $findings = [];
        $materialId = (string) ($loan->article_id ?? '');
        $product = $products->get($materialId);
        $quantity = (int) ($loan->quantity ?? 0);
        $loanMovements = $this->movementsFor($movements, (string) $loan->id, $materialId);
        $metrics = $this->movementMetrics($loanMovements);
        $status = $this->normalizeStatus($loan->status ?? null);

        if ($this->blank($materialId) || $product === null) {
            $findings[] = $this->finding('critical', 'equipment_loan_invalid_product', true, 'inspect_loan_quantity_mismatch', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
        }

        if ($quantity <= 0) {
            $findings[] = $this->finding('warning', 'equipment_loan_invalid_quantity', true, 'inspect_loan_quantity_mismatch', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
        }

        array_push($findings, ...$this->duplicateActionFindings($loan, $product, $loanMovements, $quantity, $metrics));

        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            if ($this->isOverdue($loan)) {
                $findings[] = $this->finding('warning', 'equipment_loan_overdue_active', true, 'inspect_overdue_active_loan', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            }

            if ($metrics['exit_qty'] <= 0 && $quantity > 0) {
                $findings[] = $this->finding('critical', 'equipment_loan_missing_physical_exit', true, 'create_missing_loan_physical_exit', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } elseif ($metrics['physical_net'] !== -abs($quantity)) {
                $findings[] = $this->finding('warning', 'equipment_loan_quantity_mismatch', true, 'inspect_loan_quantity_mismatch', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } else {
                $findings[] = $this->finding('info', 'equipment_loan_active_clean', false, 'no_action_needed_equipment_loan_clean', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            }
        } elseif (in_array($status, self::RETURNED_STATUSES, true)) {
            if ($metrics['exit_qty'] <= 0 && $quantity > 0) {
                $findings[] = $this->finding('critical', 'equipment_loan_missing_physical_exit', true, 'create_missing_loan_physical_exit', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } elseif ($metrics['return_qty'] <= 0 && $metrics['exit_qty'] > 0) {
                $findings[] = $this->finding('warning', 'equipment_loan_missing_return', true, 'create_missing_loan_return', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } elseif ($metrics['physical_net'] !== 0) {
                $findings[] = $this->finding('warning', 'equipment_loan_closed_with_physical_out', true, 'inspect_closed_loan_with_physical_out', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } else {
                $findings[] = $this->finding('info', 'equipment_loan_return_cycle_clean', false, 'no_action_needed_equipment_loan_clean', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            }
        } elseif (in_array($status, self::CLOSED_STATUSES, true)) {
            if ($metrics['physical_net'] !== 0) {
                $findings[] = $this->finding('warning', 'equipment_loan_closed_with_physical_out', true, 'inspect_closed_loan_with_physical_out', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            } else {
                $findings[] = $this->finding('info', 'equipment_loan_return_cycle_clean', false, 'no_action_needed_equipment_loan_clean', loan: $loan, product: $product, quantityLoan: $quantity, metrics: $metrics);
            }
        }

        return $findings;
    }

    /**
     * @return Collection<int,object>
     */
    private function movementsFor(Collection $movements, string $loanId, string $materialId): Collection
    {
        return $movements
            ->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $this->sourceTypes(), true)
                && (string) ($movement->reference_id ?? '') === $loanId
                && (string) ($movement->article_id ?? '') === $materialId)
            ->values();
    }

    /**
     * @param Collection<int,object> $movements
     * @return array{physical_net:int,exit_qty:int,return_qty:int,movement_ids:list<string>}
     */
    private function movementMetrics(Collection $movements): array
    {
        $physical = 0;
        $exit = 0;
        $return = 0;

        foreach ($movements as $movement) {
            $physical += $this->semantics->deltas($movement)['physical'];

            $type = (string) ($movement->movement_type ?? '');
            if (in_array($type, ['exit', 'sale', 'venda'], true)) {
                $exit += abs((int) ($movement->quantity ?? 0));
            }
            if ($type === 'return') {
                $return += abs((int) ($movement->quantity ?? 0));
            }
        }

        return [
            'physical_net' => $physical,
            'exit_qty' => $exit,
            'return_qty' => $return,
            'movement_ids' => $movements->pluck('id')->map('strval')->values()->all(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function duplicateActionFindings(object $loan, ?object $product, Collection $movements, int $quantity, array $metrics): array
    {
        $findings = [];
        $groups = $movements
            ->filter(fn (object $movement): bool => in_array($this->actionSemantics($movement), ['exit', 'return'], true))
            ->groupBy(fn (object $movement): string => implode('|', [
                $this->actionSemantics($movement),
                (string) abs((int) ($movement->quantity ?? 0)),
                (string) ($movement->article_id ?? ''),
            ]));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $findings[] = $this->finding('warning', 'equipment_loan_duplicate_stock_action', true, 'inspect_duplicate_loan_stock_action', loan: $loan, product: $product, quantityLoan: $quantity, metrics: [
                ...$metrics,
                'movement_ids' => $group->pluck('id')->map('strval')->values()->all(),
            ]);
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $loans
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function sourceReferenceFindings(Collection $movements, Collection $loans, Collection $products): array
    {
        $findings = [];
        $loanIds = $loans->pluck('id')->map('strval')->all();

        foreach ($movements->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $this->sourceTypes(), true)) as $movement) {
            $sourceId = $movement->reference_id ?? null;
            if ($sourceId === null || trim((string) $sourceId) === '' || ! Str::isUuid((string) $sourceId)) {
                $findings[] = $this->finding('warning', 'equipment_loan_invalid_source_reference', true, 'inspect_loan_quantity_mismatch', product: $products->get((string) ($movement->article_id ?? '')), metrics: $this->movementMetrics(collect([$movement])));

                continue;
            }

            if (! in_array((string) $sourceId, $loanIds, true)) {
                continue;
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @param Collection<int,object> $loans
     * @param Collection<string,object> $products
     * @return list<array<string,mixed>>
     */
    private function deletedLoanMovementFindings(Collection $movements, Collection $loans, Collection $products): array
    {
        $findings = [];
        $loanIds = $loans->pluck('id')->map('strval')->all();

        $groups = $movements
            ->filter(fn (object $movement): bool => in_array((string) ($movement->reference_type ?? ''), $this->sourceTypes(), true)
                && Str::isUuid((string) ($movement->reference_id ?? ''))
                && ! in_array((string) ($movement->reference_id ?? ''), $loanIds, true))
            ->groupBy(fn (object $movement): string => implode('|', [(string) $movement->reference_id, (string) $movement->article_id]));

        foreach ($groups as $group) {
            $metrics = $this->movementMetrics($group->values());
            if ($this->hasReviewedLegacyExit($group->values())) {
                $findings[] = $this->finding('info', 'equipment_loan_legacy_exit_reviewed', false, 'no_action_needed_equipment_loan_legacy_reviewed', product: $products->get((string) ($group->first()->article_id ?? '')), metrics: $metrics, extra: [
                    'loan_id' => (string) ($group->first()->reference_id ?? ''),
                ]);
            } elseif ($metrics['physical_net'] !== 0) {
                $findings[] = $this->finding('warning', 'equipment_loan_closed_with_physical_out', true, 'inspect_closed_loan_with_physical_out', product: $products->get((string) ($group->first()->article_id ?? '')), metrics: $metrics, extra: [
                    'loan_id' => (string) ($group->first()->reference_id ?? ''),
                ]);
            } else {
                $findings[] = $this->finding('info', 'equipment_loan_return_cycle_clean', false, 'no_action_needed_equipment_loan_clean', product: $products->get((string) ($group->first()->article_id ?? '')), metrics: $metrics, extra: [
                    'loan_id' => (string) ($group->first()->reference_id ?? ''),
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
            ->filter(fn (object $movement): bool => $this->blank($movement->reference_type ?? null) && $this->looksLikeLoanMovement($movement))
            ->map(fn (object $movement): array => $this->finding('info', 'equipment_loan_legacy_unlinked_movement', false, 'classify_legacy_equipment_loan_movement', product: $products->get((string) ($movement->article_id ?? '')), metrics: $this->movementMetrics(collect([$movement]))))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $loans
     * @param Collection<int,object> $movements
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(Collection $loans, Collection $movements, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_loans_scanned' => $loans->count(),
            'total_related_stock_movements' => $movements->count(),
            'active_loan_count' => $loans->filter(fn (object $loan): bool => in_array($this->normalizeStatus($loan->status ?? null), self::ACTIVE_STATUSES, true))->count(),
            'returned_loan_count' => $loans->filter(fn (object $loan): bool => in_array($this->normalizeStatus($loan->status ?? null), self::RETURNED_STATUSES, true))->count(),
            'overdue_active_loan_count' => $findingsCollection->where('code', 'equipment_loan_overdue_active')->count(),
            'clean_cycle_count' => $findingsCollection->whereIn('code', ['equipment_loan_stock_clean', 'equipment_loan_active_clean', 'equipment_loan_return_cycle_clean'])->count(),
            'missing_physical_exit_count' => $findingsCollection->where('code', 'equipment_loan_missing_physical_exit')->count(),
            'missing_return_count' => $findingsCollection->where('code', 'equipment_loan_missing_return')->count(),
            'duplicate_stock_action_count' => $findingsCollection->where('code', 'equipment_loan_duplicate_stock_action')->count(),
            'quantity_mismatch_count' => $findingsCollection->where('code', 'equipment_loan_quantity_mismatch')->count(),
            'invalid_product_count' => $findingsCollection->where('code', 'equipment_loan_invalid_product')->count(),
            'invalid_quantity_count' => $findingsCollection->where('code', 'equipment_loan_invalid_quantity')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'equipment_loan_invalid_source_reference')->count(),
            'closed_with_physical_out_count' => $findingsCollection->where('code', 'equipment_loan_closed_with_physical_out')->count(),
            'legacy_exit_reviewed_count' => $findingsCollection->where('code', 'equipment_loan_legacy_exit_reviewed')->count(),
            'legacy_unlinked_movement_count' => $findingsCollection->where('code', 'equipment_loan_legacy_unlinked_movement')->count(),
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
                (string) ($finding['loan_id'] ?? ''),
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
        ?object $loan = null,
        ?object $product = null,
        ?int $quantityLoan = null,
        array $metrics = [],
        array $extra = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'loan_id' => $this->prop($loan, 'id') ? (string) $this->prop($loan, 'id') : null,
            'status' => $this->prop($loan, 'status'),
            'material_id' => $this->prop($product, 'id') ? (string) $this->prop($product, 'id') : null,
            'material_name' => $this->prop($product, 'nome'),
            'quantity_loan' => $quantityLoan,
            'physical_net' => $metrics['physical_net'] ?? null,
            'exit_qty' => $metrics['exit_qty'] ?? null,
            'return_qty' => $metrics['return_qty'] ?? null,
            'movement_ids' => $metrics['movement_ids'] ?? [],
            'due_date' => $this->prop($loan, 'due_date'),
            'classification_reason' => $extra['classification_reason'] ?? $code,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            ...$extra,
        ];
    }

    private function actionSemantics(object $movement): string
    {
        $type = (string) ($movement->movement_type ?? '');

        return match ($type) {
            'exit', 'sale', 'venda' => 'exit',
            'return' => 'return',
            default => 'unknown',
        };
    }

    private function isOverdue(object $loan): bool
    {
        $dueDate = $loan->due_date ?? null;
        if ($dueDate === null || trim((string) $dueDate) === '') {
            return false;
        }

        return Carbon::parse((string) $dueDate)->lt(Carbon::today());
    }

    /**
     * @return list<string>
     */
    private function sourceTypes(): array
    {
        return [
            self::LOAN_SOURCE,
            self::UPDATE_SOURCE,
            self::DELETE_SOURCE,
            self::RETURN_SOURCE,
            self::DELETED_SOURCE,
        ];
    }

    private function normalizeStatus(mixed $status): string
    {
        return trim(mb_strtolower((string) $status));
    }

    private function looksLikeLoanMovement(object $movement): bool
    {
        $notes = mb_strtolower((string) ($movement->notes ?? ''));

        return str_contains($notes, 'empr')
            || str_contains($notes, 'loan')
            || str_contains($notes, 'devolu');
    }

    private function hasReviewedLegacyExit(Collection $movements): bool
    {
        return $movements->contains(fn (object $movement): bool => (string) ($movement->movement_type ?? '') === 'exit'
            && str_contains((string) ($movement->notes ?? ''), EquipmentLoanStockResolutionService::REVIEW_NOTE_MARKER));
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
