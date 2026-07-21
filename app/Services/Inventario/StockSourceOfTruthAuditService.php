<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class StockSourceOfTruthAuditService
{
    private const VERSION = 'b2-stock-source-of-truth-audit-v1';

    private const KNOWN_MOVEMENT_TYPES = [
        'entry',
        'return',
        'cancel_reservation',
        'adjustment',
        'ajuste',
        'correction',
        'correcao',
        'import',
        'importacao',
        'exit',
        'sale',
        'venda',
        'reservation',
        'deliver_reservation',
    ];

    private const ACCEPTED_NULL_REFERENCE_TYPES = [
        'audit_orphan_resolution',
        'ledger_opening_snapshot',
    ];

    private const DUPLICATE_EXEMPT_SOURCE_TYPES = [
        'audit_orphan_resolution',
    ];

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
        $filters = [
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
            'include_info' => (bool) ($options['include_info'] ?? false),
        ];

        $products = $this->products();
        $movements = $this->movements();
        $findings = [];

        foreach ($movements as $movement) {
            array_push($findings, ...$this->movementFindings($movement));
        }

        array_push($findings, ...$this->snapshotFindings($products, $movements));
        array_push($findings, ...$this->duplicateFindings($movements));
        array_push($findings, ...$this->directStockWriteFindings());

        $findings = $this->uniqueFindings($findings);
        $summary = $this->summary($products, $movements, $findings);

        if ($findings === []) {
            $findings[] = $this->finding('info', 'stock_source_of_truth_clean', false, 'no_action_needed_stock_source_of_truth_clean');
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        } elseif (! $filters['include_info']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => ($finding['code'] ?? null) !== 'stock_source_cycle_detected'));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'source_of_truth' => [
                'ledger_table' => 'stock_movements',
                'physical_snapshot' => 'products.stock',
                'reserved_snapshot' => 'products.stock_reservado',
                'available_stock_formula' => 'products.stock - products.stock_reservado',
            ],
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * @return Collection<int,object>
     */
    private function products(): Collection
    {
        if (! Schema::hasTable('products')) {
            return collect();
        }

        return DB::table('products')->orderBy('nome')->orderBy('id')->get();
    }

    /**
     * @return Collection<int,object>
     */
    private function movements(): Collection
    {
        if (! Schema::hasTable('stock_movements')) {
            return collect();
        }

        return DB::table('stock_movements')->orderBy('created_at')->orderBy('id')->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function movementFindings(object $movement): array
    {
        $findings = [];
        $type = (string) ($movement->movement_type ?? '');
        $quantity = (int) ($movement->quantity ?? 0);
        $actionSemantics = $this->actionSemantics($movement);
        $deltas = $this->semantics->deltas($movement);

        if ($type === 'reservation' && $quantity === 0) {
            $findings[] = $this->finding('warning', 'invalid_zero_quantity_movement', true, 'review_zero_quantity_stock_movement', $movement, [
                'action_semantics' => $actionSemantics,
                'quantity' => $quantity,
            ]);
        }

        $isZeroReservation = $type === 'reservation' && $quantity === 0;
        if (! $isZeroReservation && ($actionSemantics === 'unknown' || (! in_array($type, self::KNOWN_MOVEMENT_TYPES, true) && $deltas['physical'] === 0 && $deltas['reserved'] === 0))) {
            $findings[] = $this->finding('warning', 'unknown_stock_movement_type', true, 'review_stock_movement_type_mapping', $movement, [
                'action_semantics' => $actionSemantics,
                'quantity' => $quantity,
            ]);
        }

        $referenceId = $movement->reference_id ?? null;
        if ($referenceId !== null && (trim((string) $referenceId) === '' || ! Str::isUuid((string) $referenceId))) {
            $findings[] = $this->finding('warning', 'invalid_uuid_source_reference', true, 'normalize_stock_movement_reference_id_to_null_or_valid_uuid', $movement);
        }

        $referenceType = trim((string) ($movement->reference_type ?? ''));
        if ($referenceType !== '' && $referenceId === null && ! in_array($referenceType, self::ACCEPTED_NULL_REFERENCE_TYPES, true)) {
            $findings[] = $this->finding('info', 'non_idempotent_source_candidate', false, 'consider_reference_id_or_idempotency_key_for_future_stock_writes', $movement);
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $products
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function snapshotFindings(Collection $products, Collection $movements): array
    {
        $findings = [];
        $movementsByProduct = $movements->groupBy(fn (object $movement): string => (string) ($movement->article_id ?? ''));

        foreach ($products as $product) {
            $physical = 0;
            $reserved = 0;

            foreach ($movementsByProduct->get((string) $product->id, collect()) as $movement) {
                $deltas = $this->semantics->deltas($movement);
                $physical += $deltas['physical'];
                $reserved += $deltas['reserved'];
            }

            $storedStock = (int) ($product->stock ?? 0);
            $storedReserved = (int) ($product->stock_reservado ?? 0);
            $available = $storedStock - $storedReserved;

            if ($storedStock !== $physical || $storedReserved !== $reserved) {
                $findings[] = $this->finding('warning', 'stock_snapshot_mismatch', true, 'recalculate_product_snapshot_from_stock_movements', null, [
                    'material_id' => (string) $product->id,
                    'material_name' => $product->nome ?? null,
                    'stored_stock' => $storedStock,
                    'stored_reserved_stock' => $storedReserved,
                    'calculated_physical_stock' => $physical,
                    'calculated_reserved_stock' => $reserved,
                    'physical_difference' => $storedStock - $physical,
                    'reserved_difference' => $storedReserved - $reserved,
                ]);
            }

            if ($available < 0) {
                $findings[] = $this->finding('warning', 'negative_available_stock', true, 'review_reservations_and_physical_stock_snapshot', null, [
                    'material_id' => (string) $product->id,
                    'material_name' => $product->nome ?? null,
                    'stored_stock' => $storedStock,
                    'stored_reserved_stock' => $storedReserved,
                    'stored_available_stock' => $available,
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     * @return list<array<string,mixed>>
     */
    private function duplicateFindings(Collection $movements): array
    {
        $findings = [];
        $sourceGroups = $movements
            ->filter(fn (object $movement): bool => filled($movement->reference_type ?? null)
                && filled($movement->reference_id ?? null)
                && Str::isUuid((string) $movement->reference_id))
            ->groupBy(fn (object $movement): string => implode('|', [
                (string) $movement->article_id,
                (string) $movement->reference_type,
                (string) $movement->reference_id,
            ]));

        foreach ($sourceGroups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $referenceType = (string) ($group->first()->reference_type ?? '');
            if (in_array($referenceType, self::DUPLICATE_EXEMPT_SOURCE_TYPES, true)) {
                continue;
            }

            array_push($findings, ...$this->stockSourceCycleFindings($group));

            $duplicateGroups = $group->groupBy(fn (object $movement): string => implode('|', [
                $this->actionSemantics($movement),
                (string) abs((int) ($movement->quantity ?? 0)),
            ]));

            foreach ($duplicateGroups as $duplicateGroup) {
                if ($duplicateGroup->count() < 2) {
                    continue;
                }

                $first = $duplicateGroup->first();
                if ($this->actionSemantics($first) === 'unknown') {
                    continue;
                }

                if ($this->hasDifferentiatingActionKeys($duplicateGroup)) {
                    continue;
                }

                $findings[] = $this->finding('warning', 'duplicate_source_stock_movement', true, 'deduplicate_stock_source_or_add_idempotency_key', $first, [
                    'action_semantics' => $this->actionSemantics($first),
                    'quantity' => (int) ($first->quantity ?? 0),
                    'duplicate_count' => $duplicateGroup->count(),
                    'movement_ids' => $duplicateGroup->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                    'opposite_or_compensating_movement_ids' => [],
                    'cycle_classification_reason' => null,
                ]);
            }

            if ($this->sourceHasMultipleLegitimateActionsWithoutActionKey($group)) {
                $findings[] = $this->finding('info', 'non_idempotent_source_candidate', false, 'consider_action_key_for_multi_action_stock_source', $group->first(), [
                    'action_semantics' => 'multiple',
                    'quantity' => null,
                    'movement_ids' => $group->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                    'opposite_or_compensating_movement_ids' => [],
                    'cycle_classification_reason' => 'same_source_has_multiple_legitimate_actions_without_explicit_action_key',
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $group
     * @return list<array<string,mixed>>
     */
    private function stockSourceCycleFindings(Collection $group): array
    {
        $findings = [];
        $reserves = $group->filter(fn (object $movement): bool => $this->actionSemantics($movement) === 'reserve');
        $releases = $group->filter(fn (object $movement): bool => $this->actionSemantics($movement) === 'release_reservation');

        foreach ($reserves as $reserve) {
            $matchingReleases = $releases
                ->filter(fn (object $release): bool => abs((int) ($release->quantity ?? 0)) === abs((int) ($reserve->quantity ?? 0)))
                ->values();

            if ($matchingReleases->isEmpty()) {
                continue;
            }

            $findings[] = $this->finding('info', 'stock_source_cycle_detected', false, 'no_action_needed_valid_reservation_release_cycle', $reserve, [
                'action_semantics' => 'reserve',
                'quantity' => (int) ($reserve->quantity ?? 0),
                'movement_ids' => [(string) ($reserve->id ?? '')],
                'opposite_or_compensating_movement_ids' => $matchingReleases->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                'cycle_classification_reason' => 'reservation_and_release_reservation_same_source_same_absolute_quantity',
            ]);
        }

        return $findings;
    }

    /**
     * @param Collection<int,object> $movements
     */
    private function hasDifferentiatingActionKeys(Collection $movements): bool
    {
        $keys = $movements
            ->map(fn (object $movement): ?string => $this->actionKey($movement))
            ->filter()
            ->values();

        return $keys->count() === $movements->count() && $keys->unique()->count() === $movements->count();
    }

    /**
     * @param Collection<int,object> $group
     */
    private function sourceHasMultipleLegitimateActionsWithoutActionKey(Collection $group): bool
    {
        $knownActions = $group
            ->map(fn (object $movement): string => $this->actionSemantics($movement))
            ->reject(fn (string $action): bool => $action === 'unknown')
            ->unique()
            ->values();

        if ($knownActions->count() < 2) {
            return false;
        }

        if ($group->contains(fn (object $movement): bool => $this->actionKey($movement) !== null)) {
            return false;
        }

        return true;
    }

    private function actionSemantics(object $movement): string
    {
        $type = (string) ($movement->movement_type ?? '');
        $quantity = (int) ($movement->quantity ?? 0);

        return match ($type) {
            'entry' => 'entry',
            'exit', 'sale', 'venda' => 'exit',
            'return' => 'return',
            'reservation' => $quantity > 0 ? 'reserve' : ($quantity < 0 ? 'release_reservation' : 'unknown'),
            'deliver_reservation' => 'release_reservation',
            'adjustment', 'ajuste', 'correction', 'correcao', 'import', 'importacao', 'cancel_reservation' => 'adjustment',
            default => 'unknown',
        };
    }

    private function actionKey(object $movement): ?string
    {
        $notes = (string) ($movement->notes ?? '');
        if (preg_match('/\b(?:idempotency_key|action_key):([^\s;]+)/', $notes, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]) !== '' ? trim($matches[1]) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function directStockWriteFindings(): array
    {
        $root = app_path();
        $patterns = [
            '/->stock\s*=/',
            '/->stock_reservado\s*=/',
            '/StockMovement::(?:query\(\)->)?create\s*\(/',
            '/new\s+StockMovement\s*\(/',
            '/increment\s*\(\s*[\'"]stock/',
            '/decrement\s*\(\s*[\'"]stock/',
        ];
        $excluded = [
            DIRECTORY_SEPARATOR.'StockLedgerService.php',
            DIRECTORY_SEPARATOR.'StockSourceOfTruthAuditService.php',
            DIRECTORY_SEPARATOR.'StockMovementSemantics.php',
            DIRECTORY_SEPARATOR.'StockIntegrityAuditService.php',
            DIRECTORY_SEPARATOR.'StockMismatchInspectionService.php',
            DIRECTORY_SEPARATOR.'StockCorrectionPreflightService.php',
            DIRECTORY_SEPARATOR.'OrphanStockMovementInspectionService.php',
        ];
        $findings = [];

        foreach (File::allFiles($root) as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            if (collect($excluded)->contains(fn (string $segment): bool => str_contains($path, $segment))) {
                continue;
            }

            $lines = preg_split('/\R/', (string) File::get($path)) ?: [];
            foreach ($lines as $index => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line) !== 1) {
                        continue;
                    }

                    $findings[] = $this->finding('warning', 'direct_stock_write_candidate', true, 'route_stock_writes_through_stock_ledger_service', null, [
                        'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                        'line' => $index + 1,
                        'snippet' => trim($line),
                    ]);
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, bool $actionable, string $recommendation, ?object $movement = null, array $extra = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'material_id' => $movement ? (string) ($movement->article_id ?? '') : ($extra['material_id'] ?? null),
            'movement_id' => $movement ? (string) ($movement->id ?? '') : null,
            'movement_type' => $movement ? (string) ($movement->movement_type ?? '') : null,
            'source_type' => $movement ? ($movement->reference_type ?? null) : null,
            'source_id' => $movement ? ($movement->reference_id ?? null) : null,
            'action_semantics' => $movement ? $this->actionSemantics($movement) : ($extra['action_semantics'] ?? null),
            'quantity' => $movement ? (int) ($movement->quantity ?? 0) : ($extra['quantity'] ?? null),
            'opposite_or_compensating_movement_ids' => $extra['opposite_or_compensating_movement_ids'] ?? [],
            'cycle_classification_reason' => $extra['cycle_classification_reason'] ?? null,
            ...$extra,
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
                (string) ($finding['material_id'] ?? ''),
                (string) ($finding['movement_id'] ?? ''),
                (string) ($finding['file'] ?? ''),
                (string) ($finding['line'] ?? ''),
                implode(',', array_map('strval', $finding['movement_ids'] ?? [])),
                implode(',', array_map('strval', $finding['opposite_or_compensating_movement_ids'] ?? [])),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,object> $products
     * @param Collection<int,object> $movements
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(Collection $products, Collection $movements, array $findings): array
    {
        $findingsCollection = collect($findings);

        return [
            'total_products_scanned' => $products->count(),
            'total_movements_scanned' => $movements->count(),
            'snapshot_mismatch_count' => $findingsCollection->where('code', 'stock_snapshot_mismatch')->count(),
            'invalid_source_reference_count' => $findingsCollection->where('code', 'invalid_uuid_source_reference')->count(),
            'duplicate_source_movement_count' => $findingsCollection->where('code', 'duplicate_source_stock_movement')->count(),
            'stock_source_cycle_count' => $findingsCollection->where('code', 'stock_source_cycle_detected')->count(),
            'invalid_zero_quantity_movement_count' => $findingsCollection->where('code', 'invalid_zero_quantity_movement')->count(),
            'unknown_stock_movement_type_count' => $findingsCollection->where('code', 'unknown_stock_movement_type')->count(),
            'non_idempotent_source_candidate_count' => $findingsCollection->where('code', 'non_idempotent_source_candidate')->count(),
            'negative_available_count' => $findingsCollection->where('code', 'negative_available_stock')->count(),
            'direct_stock_write_candidate_count' => $findingsCollection->where('code', 'direct_stock_write_candidate')->count(),
            'total_findings' => $findingsCollection->count(),
            'critical_count' => $findingsCollection->where('severity', 'critical')->count(),
            'warning_count' => $findingsCollection->where('severity', 'warning')->count(),
            'info_count' => $findingsCollection->where('severity', 'info')->count(),
            'actionable_count' => $findingsCollection->where('actionable', true)->count(),
        ];
    }
}
