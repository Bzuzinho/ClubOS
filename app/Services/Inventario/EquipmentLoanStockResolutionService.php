<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EquipmentLoanStockResolutionService
{
    public const REVIEW_NOTE_MARKER = 'Movimento de emprestimo sem registo ativo revisto e aceite como saida historica B5.2';

    private const VERSION = 'b5-2-equipment-loan-stock-resolution-v1';
    private const RETURN_NOTE = 'Devolucao criada por resolucao auditada B5.2 para emprestimo sem registo ativo';

    public function __construct(
        private readonly EquipmentLoanStockInspectionService $inspectionService,
        private readonly StockLedgerService $stockLedger = new StockLedgerService(),
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $filters = $this->filters($options);
        $apply = $filters['apply'];
        $blocked = $this->blockReason($filters);
        $dryRun = ! $apply || $blocked !== null;

        $items = collect($this->inspectionService->inspect([
            'loan' => $filters['loan'],
            'movement' => $filters['movement'],
        ])['items'] ?? [])
            ->map(fn (array $inspection): array => $this->planItem($inspection, $filters, $dryRun, $blocked))
            ->values()
            ->all();

        if ($apply && $blocked === null) {
            $items = array_map(fn (array $item): array => $this->applyItemPlan($item), $items);
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'dry_run' => $dryRun,
            'apply' => $apply,
            'confirmed_equipment_loan_resolution' => $filters['confirmed_equipment_loan_resolution'],
            'strategy' => $filters['strategy'],
            'filters' => $filters,
            'summary' => $this->summary($items),
            'items' => $items,
            'blocked' => $blocked !== null,
            'block_reason' => $blocked,
            'read_only_when_dry_run' => $dryRun,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{loan:?string,movement:?string,strategy:?string,dry_run:bool,apply:bool,confirmed_equipment_loan_resolution:bool,fail_on_unsafe:bool}
     */
    private function filters(array $options): array
    {
        return [
            'loan' => $this->stringOrNull($options['loan'] ?? null),
            'movement' => $this->stringOrNull($options['movement'] ?? null),
            'strategy' => $this->strategy($options['strategy'] ?? null),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'apply' => (bool) ($options['apply'] ?? false),
            'confirmed_equipment_loan_resolution' => (bool) ($options['confirm_equipment_loan_resolution'] ?? false),
            'fail_on_unsafe' => (bool) ($options['fail_on_unsafe'] ?? false),
        ];
    }

    private function strategy(mixed $value): ?string
    {
        $strategy = is_string($value) ? trim($value) : '';

        return in_array($strategy, ['create_missing_return', 'accept_legacy_exit', 'full_safe_resolution'], true)
            ? $strategy
            : null;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function blockReason(array $filters): ?string
    {
        if ($filters['strategy'] === null) {
            return 'strategy_required';
        }

        if ($filters['apply'] && ! $filters['confirmed_equipment_loan_resolution']) {
            return 'confirm_equipment_loan_resolution_required';
        }

        if ($filters['apply'] && $filters['loan'] === null && $filters['movement'] === null) {
            return 'loan_or_movement_filter_required_for_apply';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $inspection
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function planItem(array $inspection, array $filters, bool $dryRun, ?string $blocked): array
    {
        $actions = match ($filters['strategy']) {
            'accept_legacy_exit' => [$this->acceptLegacyExitAction($inspection)],
            'create_missing_return' => [$this->createMissingReturnAction($inspection)],
            'full_safe_resolution' => [$this->fullSafeResolutionAction($inspection)],
            default => [],
        };

        if ($blocked !== null) {
            $actions = array_map(static fn (array $action): array => [
                ...$action,
                'safe_to_apply' => false,
                'reason' => $blocked,
            ], $actions);
        }

        return [
            'loan_id' => $inspection['loan_id'] ?? null,
            'movement_id' => data_get($inspection, 'primary_movement.id'),
            'material' => $inspection['material'] ?? null,
            'current_state' => $this->currentState($inspection),
            'strategy' => $filters['strategy'],
            'proposed_actions' => $actions,
            'expected_after' => $this->expectedAfter($inspection, $actions),
            'applied' => false,
            'skipped' => $dryRun,
            'errors' => [],
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return array<string,mixed>
     */
    private function fullSafeResolutionAction(array $inspection): array
    {
        $return = $this->createMissingReturnAction($inspection);
        $current = $this->currentState($inspection);
        $stored = $current['stored_physical_stock'];
        $afterPhysical = $current['ledger_physical_stock'] + (int) ($return['physical_delta'] ?? 0);

        if (($current['matches_ledger'] ?? false) === true && $afterPhysical !== $stored) {
            return $this->acceptLegacyExitAction($inspection, 'global_stock_already_matches_ledger_return_would_change_stock');
        }

        if (($current['matches_ledger'] ?? false) === false && $afterPhysical === $stored) {
            return $return;
        }

        return [
            ...$return,
            'safe_to_apply' => false,
            'reason' => 'manual_review_required_unable_to_choose_safe_resolution',
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return array<string,mixed>
     */
    private function acceptLegacyExitAction(array $inspection, ?string $reason = null): array
    {
        $movement = $inspection['primary_movement'] ?? null;
        $alreadyReviewed = $this->isReviewedNotes(data_get($movement, 'notes'));
        $safe = $alreadyReviewed || ($this->baseSafeConditions($inspection) === [] && Schema::hasColumn('stock_movements', 'notes'));

        return [
            'action_type' => 'accept_legacy_exit',
            'movement_id' => data_get($movement, 'id'),
            'quantity' => 0,
            'physical_delta' => 0,
            'reserved_delta' => 0,
            'safe_to_apply' => $safe,
            'applied' => false,
            'skipped' => $alreadyReviewed,
            'reason' => $alreadyReviewed ? 'already_reviewed' : ($safe ? ($reason ?? 'legacy_exit_can_be_marked_reviewed') : implode(',', $this->baseSafeConditions($inspection))),
            'stock_movement_id' => null,
            'product_updated' => false,
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return array<string,mixed>
     */
    private function createMissingReturnAction(array $inspection): array
    {
        $movement = $inspection['primary_movement'] ?? null;
        $quantity = abs((int) data_get($movement, 'quantity', 0));
        $current = $this->currentState($inspection);
        $blockers = $this->baseSafeConditions($inspection);

        if ($this->existingReturn($inspection)) {
            $blockers[] = 'return_already_exists_for_loan_source';
        }

        $expectedPhysical = $current['ledger_physical_stock'] + $quantity;
        if ($expectedPhysical !== $current['stored_physical_stock']) {
            $blockers[] = 'return_would_create_stock_snapshot_mismatch';
        }

        if (($current['ledger_physical_stock'] + $quantity) < 0 || ($current['ledger_reserved_stock'] ?? 0) < 0) {
            $blockers[] = 'return_would_create_negative_stock';
        }

        return [
            'action_type' => 'create_missing_return',
            'movement_type' => 'return',
            'movement_id' => data_get($movement, 'id'),
            'quantity' => $quantity,
            'physical_delta' => $quantity,
            'reserved_delta' => 0,
            'safe_to_apply' => $blockers === [],
            'applied' => false,
            'skipped' => false,
            'reason' => $blockers === [] ? 'return_resolves_stock_mismatch' : implode(',', array_unique($blockers)),
            'stock_movement_id' => null,
            'product_updated' => $blockers === [],
            'idempotency_key' => sprintf('b5_2_missing_loan_return:%s:%s', (string) ($inspection['loan_id'] ?? ''), (string) data_get($movement, 'id', '')),
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @return list<string>
     */
    private function baseSafeConditions(array $inspection): array
    {
        $blockers = [];
        $movement = $inspection['primary_movement'] ?? null;

        if (($inspection['loan_record_found'] ?? true) !== false) {
            $blockers[] = 'loan_record_still_exists';
        }

        if ($movement === null) {
            $blockers[] = 'primary_movement_missing';
        }

        if ((string) data_get($movement, 'movement_type', '') !== 'exit') {
            $blockers[] = 'primary_movement_is_not_exit';
        }

        if (! in_array((string) data_get($movement, 'reference_type', ''), ['equipment_loan'], true)) {
            $blockers[] = 'primary_movement_is_not_equipment_loan_source';
        }

        if (abs((int) data_get($movement, 'quantity', 0)) <= 0) {
            $blockers[] = 'primary_movement_quantity_invalid';
        }

        if (($inspection['material'] ?? null) === null || ! filled(data_get($inspection, 'material.id'))) {
            $blockers[] = 'material_missing';
        }

        if (! (bool) data_get($inspection, 'global_stock_state.matches_ledger', false) && data_get($inspection, 'global_stock_state.stored_physical_stock') === null) {
            $blockers[] = 'global_stock_state_unavailable';
        }

        return array_unique($blockers);
    }

    /**
     * @param array<string,mixed> $inspection
     */
    private function existingReturn(array $inspection): bool
    {
        $loanId = $this->stringOrNull($inspection['loan_id'] ?? null);
        $materialId = $this->stringOrNull(data_get($inspection, 'material.id'));

        if ($loanId === null || $materialId === null || ! Schema::hasTable('stock_movements')) {
            return false;
        }

        return DB::table('stock_movements')
            ->where('article_id', $materialId)
            ->whereIn('reference_type', ['equipment_loan', 'equipment_loan_return'])
            ->where('reference_id', $loanId)
            ->where('movement_type', 'return')
            ->exists();
    }

    /**
     * @param array<string,mixed> $inspection
     * @return array<string,mixed>
     */
    private function currentState(array $inspection): array
    {
        $state = $inspection['global_stock_state'] ?? [];

        return [
            'stored_physical_stock' => $state['stored_physical_stock'] ?? null,
            'ledger_physical_stock' => $state['ledger_physical_stock'] ?? null,
            'stored_reserved_stock' => $state['stored_reserved_stock'] ?? null,
            'ledger_reserved_stock' => $state['ledger_reserved_stock'] ?? null,
            'stored_available_stock' => (($state['stored_physical_stock'] ?? null) !== null && ($state['stored_reserved_stock'] ?? null) !== null)
                ? (int) $state['stored_physical_stock'] - (int) $state['stored_reserved_stock']
                : null,
            'ledger_available_stock' => (($state['ledger_physical_stock'] ?? null) !== null && ($state['ledger_reserved_stock'] ?? null) !== null)
                ? (int) $state['ledger_physical_stock'] - (int) $state['ledger_reserved_stock']
                : null,
            'matches_ledger' => (bool) ($state['matches_ledger'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $inspection
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    private function expectedAfter(array $inspection, array $actions): array
    {
        $current = $this->currentState($inspection);
        $ledgerPhysical = (int) ($current['ledger_physical_stock'] ?? 0);
        $ledgerReserved = (int) ($current['ledger_reserved_stock'] ?? 0);

        foreach ($actions as $action) {
            if (! (bool) ($action['safe_to_apply'] ?? false) && ($action['action_type'] ?? null) !== 'create_missing_return') {
                continue;
            }

            $ledgerPhysical += (int) ($action['physical_delta'] ?? 0);
            $ledgerReserved += (int) ($action['reserved_delta'] ?? 0);
        }

        return [
            'stored_physical_stock' => $current['stored_physical_stock'],
            'ledger_physical_stock' => $ledgerPhysical,
            'stored_reserved_stock' => $current['stored_reserved_stock'],
            'ledger_reserved_stock' => $ledgerReserved,
            'stored_available_stock' => $current['stored_available_stock'],
            'ledger_available_stock' => $ledgerPhysical - $ledgerReserved,
            'matches_ledger' => $current['stored_physical_stock'] === $ledgerPhysical
                && ($current['stored_reserved_stock'] === null || $current['stored_reserved_stock'] === $ledgerReserved),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function applyItemPlan(array $item): array
    {
        return DB::transaction(function () use ($item): array {
            $actions = [];
            $applied = false;
            $errors = [];

            foreach ($item['proposed_actions'] as $action) {
                if (! (bool) ($action['safe_to_apply'] ?? false)) {
                    $actions[] = [...$action, 'skipped' => true];
                    continue;
                }

                try {
                    $appliedAction = match ($action['action_type']) {
                        'accept_legacy_exit' => $this->applyAcceptLegacyExit($action),
                        'create_missing_return' => $this->applyCreateMissingReturn($item, $action),
                        default => [...$action, 'skipped' => true, 'reason' => 'unsupported_action'],
                    };
                    $applied = $applied || (bool) ($appliedAction['applied'] ?? false);
                    $actions[] = $appliedAction;
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                    $actions[] = [...$action, 'failed' => true, 'applied' => false, 'skipped' => false, 'reason' => $exception->getMessage()];
                    throw $exception;
                }
            }

            return [
                ...$item,
                'current_state' => $this->freshCurrentState($item),
                'proposed_actions' => $actions,
                'expected_after' => $this->freshCurrentState($item),
                'applied' => $applied,
                'skipped' => ! $applied,
                'errors' => $errors,
            ];
        });
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyAcceptLegacyExit(array $action): array
    {
        if (($action['reason'] ?? null) === 'already_reviewed') {
            return [...$action, 'applied' => false, 'skipped' => true];
        }

        $movement = StockMovement::query()
            ->whereKey((string) $action['movement_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if ($this->isReviewedNotes($movement->notes)) {
            return [...$action, 'applied' => false, 'skipped' => true, 'reason' => 'already_reviewed'];
        }

        $notes = trim((string) ($movement->notes ?? ''));
        $suffix = self::REVIEW_NOTE_MARKER.' em '.Carbon::now()->toDateString();
        $movement->forceFill([
            'notes' => $notes === '' ? $suffix : $notes.' | '.$suffix,
        ])->save();

        return [...$action, 'applied' => true, 'skipped' => false, 'movement_marked_reviewed' => true];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyCreateMissingReturn(array $item, array $action): array
    {
        $product = Product::query()
            ->whereKey((string) data_get($item, 'material.id'))
            ->lockForUpdate()
            ->firstOrFail();

        $movement = $this->stockLedger->registerReturn($product, (int) $action['quantity'], [
            'source_type' => 'equipment_loan_return',
            'source_id' => $this->stringOrNull($item['loan_id'] ?? null),
            'notes' => self::RETURN_NOTE,
            'idempotency_key' => $action['idempotency_key'] ?? null,
            'created_by' => null,
        ]);

        return [
            ...$action,
            'applied' => true,
            'skipped' => false,
            'stock_movement_id' => (string) $movement->id,
            'product_updated' => true,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function freshCurrentState(array $item): array
    {
        $materialId = $this->stringOrNull(data_get($item, 'material.id'));
        if ($materialId === null) {
            return $item['current_state'];
        }

        $product = DB::table('products')->where('id', $materialId)->first();
        $physical = 0;
        $reserved = 0;

        foreach (DB::table('stock_movements')->where('article_id', $materialId)->get() as $movement) {
            $deltas = (new StockMovementSemantics())->deltas($movement);
            $physical += $deltas['physical'];
            $reserved += $deltas['reserved'];
        }

        $storedPhysical = (int) ($product->stock ?? 0);
        $storedReserved = (int) ($product->stock_reservado ?? 0);

        return [
            'stored_physical_stock' => $storedPhysical,
            'ledger_physical_stock' => $physical,
            'stored_reserved_stock' => $storedReserved,
            'ledger_reserved_stock' => $reserved,
            'stored_available_stock' => $storedPhysical - $storedReserved,
            'ledger_available_stock' => $physical - $reserved,
            'matches_ledger' => $storedPhysical === $physical && $storedReserved === $reserved,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $actions = collect($items)->flatMap(fn (array $item): array => $item['proposed_actions'] ?? []);

        return [
            'inspected_count' => count($items),
            'proposed_action_count' => $actions->count(),
            'safe_action_count' => $actions->filter(fn (array $action): bool => (bool) ($action['safe_to_apply'] ?? false))->count(),
            'unsafe_action_count' => $actions->filter(fn (array $action): bool => ! (bool) ($action['safe_to_apply'] ?? false) && ! (bool) ($action['skipped'] ?? false))->count(),
            'applied_count' => $actions->filter(fn (array $action): bool => (bool) ($action['applied'] ?? false))->count(),
            'skipped_count' => $actions->filter(fn (array $action): bool => (bool) ($action['skipped'] ?? false))->count(),
            'failed_count' => $actions->filter(fn (array $action): bool => (bool) ($action['failed'] ?? false))->count(),
            'stock_movements_created_count' => $actions->filter(fn (array $action): bool => filled($action['stock_movement_id'] ?? null))->count(),
            'movements_marked_reviewed_count' => $actions->filter(fn (array $action): bool => (bool) ($action['movement_marked_reviewed'] ?? false))->count(),
            'products_updated_count' => $actions->filter(fn (array $action): bool => (bool) ($action['product_updated'] ?? false) && (bool) ($action['applied'] ?? false))->count(),
        ];
    }

    private function isReviewedNotes(mixed $notes): bool
    {
        return str_contains((string) $notes, self::REVIEW_NOTE_MARKER);
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
