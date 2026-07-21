<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EquipmentLoanStockInspectionService
{
    private const VERSION = 'b5-1-equipment-loan-stock-inspection-v1';

    private const LOAN_SOURCE_TYPES = [
        'equipment_loan',
        'equipment_loan_update',
        'equipment_loan_delete',
        'equipment_loan_return',
        'equipment_loan_deleted',
    ];

    private const CONTEXT_SOURCE_TYPES = [
        'audit_orphan_resolution',
        'logistics_request',
    ];

    public function __construct(
        private readonly StockMovementSemantics $semantics = new StockMovementSemantics(),
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function inspect(array $options = []): array
    {
        $filters = $this->filters($options);
        $primaryMovements = $this->primaryMovements($filters);
        $items = $primaryMovements
            ->map(fn (object $movement): array => $this->inspectMovement($movement))
            ->values()
            ->all();

        if ($primaryMovements->isEmpty() && $filters['loan'] !== null) {
            $items[] = $this->inspectMissingMovementLoan($filters['loan']);
        }

        if ($filters['only_actionable']) {
            $items = array_values(array_filter($items, static fn (array $item): bool => (bool) ($item['actionable'] ?? false)));
        }

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
     * @return array{loan:?string,movement:?string,material:?string,only_actionable:bool}
     */
    private function filters(array $options): array
    {
        return [
            'loan' => $this->stringOrNull($options['loan'] ?? null),
            'movement' => $this->stringOrNull($options['movement'] ?? null),
            'material' => $this->stringOrNull($options['material'] ?? null),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function primaryMovements(array $filters): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $query = DB::table('stock_movements')->orderBy('created_at')->orderBy('id');

        if ($filters['movement'] !== null) {
            return $query->where('id', $filters['movement'])->get();
        }

        $query->whereIn('reference_type', self::LOAN_SOURCE_TYPES);

        if ($filters['loan'] !== null) {
            $query->where('reference_id', $filters['loan']);
        }

        if ($filters['material'] !== null) {
            $query->where('article_id', $filters['material']);
        }

        return $query->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectMovement(object $movement): array
    {
        $loanId = $this->stringOrNull($movement->reference_id ?? null);
        $materialId = $this->stringOrNull($movement->article_id ?? null);
        $loan = $loanId !== null ? $this->loanRecord($loanId) : null;
        $material = $materialId !== null ? $this->material($materialId) : null;
        $related = $loanId !== null ? $this->relatedMovements($loanId, $materialId) : collect([$movement]);
        $nearby = $this->nearbyContext($movement);
        $impact = $this->impactBySource($movement, $related, $nearby);
        $globalStock = $materialId !== null ? $this->globalStockState($materialId, $material) : $this->emptyGlobalStockState();
        $classification = $this->classification($loan, $movement, $related, $nearby, $impact, $globalStock);

        return [
            'loan_id' => $loanId,
            'loan_record_found' => $loan !== null,
            'loan_record' => $this->normalizeRecord($loan),
            'primary_movement' => $this->movementPayload($movement),
            'material' => $this->normalizeRecord($material),
            'related_movements' => $related->map(fn (object $row): array => $this->movementPayload($row))->values()->all(),
            'nearby_context' => $nearby->map(fn (object $row): array => $this->movementPayload($row))->values()->all(),
            'impact_by_source' => $impact,
            'global_stock_state' => $globalStock,
            'classification' => $classification['code'],
            'severity' => $classification['severity'],
            'recommendation' => $classification['recommendation'],
            'actionable' => $classification['actionable'],
            'read_only' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectMissingMovementLoan(string $loanId): array
    {
        $loan = $this->loanRecord($loanId);
        $material = $loan !== null && $this->stringOrNull($loan->article_id ?? null) !== null
            ? $this->material((string) $loan->article_id)
            : null;

        return [
            'loan_id' => $loanId,
            'loan_record_found' => $loan !== null,
            'loan_record' => $this->normalizeRecord($loan),
            'primary_movement' => null,
            'material' => $this->normalizeRecord($material),
            'related_movements' => [],
            'nearby_context' => [],
            'impact_by_source' => $this->emptyImpact(),
            'global_stock_state' => $loan !== null && $this->stringOrNull($loan->article_id ?? null) !== null
                ? $this->globalStockState((string) $loan->article_id, $material)
                : $this->emptyGlobalStockState(),
            'classification' => 'equipment_loan_requires_manual_review',
            'severity' => 'warning',
            'recommendation' => 'manual_review_required',
            'actionable' => true,
            'read_only' => true,
        ];
    }

    private function loanRecord(string $loanId): ?object
    {
        if (! Schema::hasTable('equipment_loans')) {
            return null;
        }

        return DB::table('equipment_loans')->where('id', $loanId)->first();
    }

    private function material(string $materialId): ?object
    {
        if (! Schema::hasTable('products')) {
            return null;
        }

        return DB::table('products')->where('id', $materialId)->first();
    }

    /**
     * @return Collection<int,object>
     */
    private function relatedMovements(string $loanId, ?string $materialId): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        return DB::table('stock_movements')
            ->whereIn('reference_type', self::LOAN_SOURCE_TYPES)
            ->where('reference_id', $loanId)
            ->when($materialId !== null, fn (Builder $query): Builder => $query->where('article_id', $materialId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,object>
     */
    private function nearbyContext(object $movement): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        $createdAt = Carbon::parse((string) ($movement->created_at ?? now()));
        $materialId = (string) ($movement->article_id ?? '');
        $createdBy = $this->stringOrNull($movement->created_by ?? null);

        return DB::table('stock_movements')
            ->where('id', '!=', (string) ($movement->id ?? ''))
            ->where('article_id', $materialId)
            ->whereBetween('created_at', [$createdAt->copy()->subMinutes(30), $createdAt->copy()->addMinutes(30)])
            ->where(function (Builder $query) use ($createdBy): void {
                $query->whereIn('movement_type', ['return', 'entry'])
                    ->orWhereIn('reference_type', self::CONTEXT_SOURCE_TYPES);

                if ($createdBy !== null) {
                    $query->orWhere('created_by', $createdBy);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function impactBySource(object $movement, Collection $related, Collection $nearby): array
    {
        return [
            'equipment_loan' => $this->impact($related),
            'logistics_request' => $this->impact($nearby->where('reference_type', 'logistics_request')->values()),
            'audit_orphan_resolution' => $this->impact($nearby->where('reference_type', 'audit_orphan_resolution')->values()),
            'total_material_period' => $this->impact($nearby->merge([$movement])->values()),
        ];
    }

    /**
     * @param Collection<int,object> $movements
     * @return array{physical_net:int,exit_qty:int,return_qty:int,entry_qty:int,movement_ids:list<string>}
     */
    private function impact(Collection $movements): array
    {
        $physical = 0;
        $exit = 0;
        $return = 0;
        $entry = 0;

        foreach ($movements as $movement) {
            $physical += $this->semantics->deltas($movement)['physical'];
            $type = (string) ($movement->movement_type ?? '');
            $quantity = abs((int) ($movement->quantity ?? 0));

            if ($type === 'exit') {
                $exit += $quantity;
            } elseif ($type === 'return') {
                $return += $quantity;
            } elseif ($type === 'entry') {
                $entry += $quantity;
            }
        }

        return [
            'physical_net' => $physical,
            'exit_qty' => $exit,
            'return_qty' => $return,
            'entry_qty' => $entry,
            'movement_ids' => $movements->pluck('id')->map('strval')->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function globalStockState(string $materialId, ?object $material): array
    {
        $movements = Schema::hasTable('stock_movements')
            ? DB::table('stock_movements')->where('article_id', $materialId)->get()
            : collect();

        $physical = 0;
        $reserved = 0;
        foreach ($movements as $movement) {
            $deltas = $this->semantics->deltas($movement);
            $physical += $deltas['physical'];
            $reserved += $deltas['reserved'];
        }

        $storedPhysical = $material !== null && property_exists($material, 'stock') ? (int) $material->stock : null;
        $storedReserved = $material !== null && property_exists($material, 'stock_reservado') ? (int) $material->stock_reservado : null;

        return [
            'material_id' => $materialId,
            'stored_physical_stock' => $storedPhysical,
            'ledger_physical_stock' => $physical,
            'stored_reserved_stock' => $storedReserved,
            'ledger_reserved_stock' => $reserved,
            'matches_ledger' => $storedPhysical === $physical && ($storedReserved === null || $storedReserved === $reserved),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyGlobalStockState(): array
    {
        return [
            'material_id' => null,
            'stored_physical_stock' => null,
            'ledger_physical_stock' => null,
            'stored_reserved_stock' => null,
            'ledger_reserved_stock' => null,
            'matches_ledger' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyImpact(): array
    {
        return [
            'equipment_loan' => $this->impact(collect()),
            'logistics_request' => $this->impact(collect()),
            'audit_orphan_resolution' => $this->impact(collect()),
            'total_material_period' => $this->impact(collect()),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $impact
     * @param array<string,mixed> $globalStock
     * @return array{code:string,severity:string,recommendation:string,actionable:bool}
     */
    private function classification(?object $loan, object $movement, Collection $related, Collection $nearby, array $impact, array $globalStock): array
    {
        if (! (bool) ($globalStock['matches_ledger'] ?? false)) {
            return [
                'code' => 'equipment_loan_requires_manual_review',
                'severity' => 'critical',
                'recommendation' => 'manual_review_required',
                'actionable' => true,
            ];
        }

        $loanNet = (int) $impact['equipment_loan']['physical_net'];
        $nearbyNet = (int) $impact['total_material_period']['physical_net'];
        $hasExit = (int) $impact['equipment_loan']['exit_qty'] > 0;
        $hasReturn = (int) $impact['equipment_loan']['return_qty'] > 0;
        $hasDifferentSourceReturn = $nearby
            ->filter(fn (object $row): bool => (string) ($row->movement_type ?? '') === 'return'
                && ! in_array((string) ($row->reference_type ?? ''), self::LOAN_SOURCE_TYPES, true))
            ->isNotEmpty();

        if ($loan !== null) {
            $status = trim(mb_strtolower((string) ($loan->status ?? '')));
            if (in_array($status, ['returned', 'devolvido'], true) && $loanNet === 0 && $hasExit && $hasReturn) {
                return [
                    'code' => 'equipment_loan_inspection_clean',
                    'severity' => 'info',
                    'recommendation' => 'no_action_needed_globally_compensated',
                    'actionable' => false,
                ];
            }

            if (in_array($status, ['active', 'overdue', 'ativo', 'atrasado'], true) && $hasExit && $loanNet < 0) {
                return [
                    'code' => 'equipment_loan_inspection_clean',
                    'severity' => 'info',
                    'recommendation' => 'no_action_needed_globally_compensated',
                    'actionable' => false,
                ];
            }
        }

        if ($loan === null && $hasDifferentSourceReturn) {
            return [
                'code' => 'equipment_loan_return_recorded_under_different_source',
                'severity' => 'info',
                'recommendation' => 'no_action_needed_globally_compensated',
                'actionable' => false,
            ];
        }

        if ($loan === null && $hasExit && $loanNet === 0 && $hasReturn) {
            return [
                'code' => 'equipment_loan_missing_record_but_globally_compensated',
                'severity' => 'info',
                'recommendation' => 'no_action_needed_globally_compensated',
                'actionable' => false,
            ];
        }

        if ($loan === null && $hasExit && $loanNet < 0 && $nearbyNet === 0) {
            return [
                'code' => 'equipment_loan_missing_record_but_globally_compensated',
                'severity' => 'info',
                'recommendation' => 'no_action_needed_globally_compensated',
                'actionable' => false,
            ];
        }

        if ($loan === null && $hasExit && $loanNet < 0) {
            return [
                'code' => 'equipment_loan_missing_record_with_uncompensated_exit',
                'severity' => 'warning',
                'recommendation' => 'create_missing_loan_return_after_review',
                'actionable' => true,
            ];
        }

        if ($loan === null && $loanNet === 0 && $related->isNotEmpty()) {
            return [
                'code' => 'equipment_loan_deleted_historical_clean',
                'severity' => 'info',
                'recommendation' => 'no_action_needed_globally_compensated',
                'actionable' => false,
            ];
        }

        if ($loan === null && $hasExit) {
            return [
                'code' => 'equipment_loan_legacy_manual_exit_accepted_candidate',
                'severity' => 'info',
                'recommendation' => 'classify_legacy_equipment_loan_exit',
                'actionable' => false,
            ];
        }

        return [
            'code' => 'equipment_loan_requires_manual_review',
            'severity' => 'warning',
            'recommendation' => 'manual_review_required',
            'actionable' => true,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $collection = collect($items);

        return [
            'inspected_loans' => $collection->pluck('loan_id')->filter()->unique()->count(),
            'inspected_movements' => $collection->whereNotNull('primary_movement')->count(),
            'loan_record_found_count' => $collection->where('loan_record_found', true)->count(),
            'missing_loan_record_count' => $collection->where('loan_record_found', false)->count(),
            'globally_compensated_count' => $collection->whereIn('classification', [
                'equipment_loan_missing_record_but_globally_compensated',
                'equipment_loan_deleted_historical_clean',
                'equipment_loan_return_recorded_under_different_source',
                'equipment_loan_inspection_clean',
            ])->count(),
            'uncompensated_exit_count' => $collection->where('classification', 'equipment_loan_missing_record_with_uncompensated_exit')->count(),
            'manual_review_count' => $collection->where('classification', 'equipment_loan_requires_manual_review')->count(),
            'critical_count' => $collection->where('severity', 'critical')->count(),
            'warning_count' => $collection->where('severity', 'warning')->count(),
            'info_count' => $collection->where('severity', 'info')->count(),
            'actionable_count' => $collection->where('actionable', true)->count(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizeRecord(?object $record): ?array
    {
        if ($record === null) {
            return null;
        }

        return json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function movementPayload(object $movement): array
    {
        return [
            'id' => (string) ($movement->id ?? ''),
            'article_id' => $movement->article_id ?? null,
            'movement_type' => $movement->movement_type ?? null,
            'quantity' => isset($movement->quantity) ? (int) $movement->quantity : null,
            'unit_cost' => $movement->unit_cost ?? null,
            'reference_type' => $movement->reference_type ?? null,
            'source_type' => $movement->reference_type ?? null,
            'reference_id' => $movement->reference_id ?? null,
            'source_id' => $movement->reference_id ?? null,
            'notes' => $movement->notes ?? null,
            'created_by' => $movement->created_by ?? null,
            'created_at' => $movement->created_at ?? null,
        ];
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
