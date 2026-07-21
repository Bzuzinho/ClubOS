<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OrphanStockMovementResolutionService
{
    public const REVIEW_NOTE_MARKER = 'Movimento órfão revisto e aceite como movimento manual histórico';
    private const VERSION = 'b1-6-orphan-stock-movement-resolution-v1';
    private const RESOLUTION_REFERENCE_TYPE = 'audit_orphan_resolution';
    private const RELEASE_NOTE = 'Libertação de reserva órfã após revisão de auditoria B1.6';
    private const PHYSICAL_ADJUSTMENT_NOTE = 'Ajuste físico após revisão de auditoria B1.6 para alinhar stock calculado ao stock guardado';
    private const SMALL_PHYSICAL_ADJUSTMENT_LIMIT = 10;

    public function __construct(
        private readonly OrphanStockMovementInspectionService $inspectionService,
        private readonly StockMovementSemantics $stockSemantics = new StockMovementSemantics(),
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
        $items = [];

        foreach ($this->materials($filters) as $material) {
            $plan = $this->planMaterial($material, $filters, $dryRun, $blocked);

            if ($apply && $blocked === null) {
                $plan = $this->applyMaterialPlan($material, $plan, $filters);
            }

            $items[] = $plan;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'dry_run' => $dryRun,
            'apply' => $apply,
            'confirmed_orphan_resolution' => $filters['confirmed_orphan_resolution'],
            'strategy' => $filters['strategy'],
            'filters' => $filters,
            'summary' => $this->summary($items),
            'items' => $items,
            'read_only_when_dry_run' => $dryRun,
            'blocked' => $blocked !== null,
            'block_reason' => $blocked,
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
            'strategy' => $this->strategy($options['strategy'] ?? null),
            'target_physical_stock' => $this->nullableInt($options['target_physical_stock'] ?? null),
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'apply' => (bool) ($options['apply'] ?? false),
            'confirmed_orphan_resolution' => (bool) ($options['confirm_orphan_resolution'] ?? false),
            'fail_on_unsafe' => (bool) ($options['fail_on_unsafe'] ?? false),
        ];
    }

    private function strategy(mixed $value): ?string
    {
        $strategy = is_string($value) ? trim($value) : '';

        return in_array($strategy, ['accept_manual', 'release_orphan_reservation', 'physical_adjustment', 'full_safe_resolution'], true)
            ? $strategy
            : null;
    }

    private function blockReason(array $filters): ?string
    {
        if ($filters['strategy'] === null) {
            return 'strategy_required';
        }

        if ($filters['apply'] && ! $filters['confirmed_orphan_resolution']) {
            return 'confirm_orphan_resolution_required';
        }

        if ($filters['apply'] && $filters['material'] === [] && $filters['movement'] === []) {
            return 'material_or_movement_filter_required_for_apply';
        }

        return null;
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

        $inspection = $this->inspectionService->inspect([
            'material' => $filters['material'],
            'movement' => $filters['movement'],
        ]);

        $materialIds = collect($inspection['items'] ?? [])
            ->pluck('material.id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($materialIds === []) {
            return collect();
        }

        return DB::table('products')
            ->whereIn('id', $materialIds)
            ->orderBy('nome')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function planMaterial(object $material, array $filters, bool $dryRun, ?string $blocked): array
    {
        $current = $this->stockState((string) $material->id);
        $actions = [];

        if (in_array($filters['strategy'], ['accept_manual', 'full_safe_resolution'], true)) {
            array_push($actions, ...$this->acceptManualActions($material, $filters));
        }

        if (in_array($filters['strategy'], ['release_orphan_reservation', 'full_safe_resolution'], true)) {
            $actions[] = $this->releaseReservationAction($material, $current);
        }

        if (in_array($filters['strategy'], ['physical_adjustment', 'full_safe_resolution'], true)) {
            $actions[] = $this->physicalAdjustmentAction($material, $current, $filters);
        }

        $actions = array_values(array_filter($actions));
        if ($blocked !== null) {
            $actions = array_map(fn (array $action): array => [
                ...$action,
                'safe_to_apply' => false,
                'reason' => $blocked,
            ], $actions);
        }

        return [
            'material_id' => (string) $material->id,
            'material_name' => $material->nome ?? null,
            'strategy' => $filters['strategy'],
            'current_state' => $current,
            'proposed_actions' => $actions,
            'expected_after' => $this->expectedAfter($current, $actions),
            'applied' => false,
            'skipped' => $dryRun,
            'errors' => [],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function stockState(string $materialId): array
    {
        $material = DB::table('products')->where('id', $materialId)->first();
        $physical = 0;
        $reserved = 0;

        foreach ($this->movements($materialId) as $movement) {
            $deltas = $this->stockSemantics->deltas($movement);
            $physical += $deltas['physical'];
            $reserved += $deltas['reserved'];
        }

        $storedStock = (int) ($material->stock ?? 0);
        $storedReserved = (int) ($material->stock_reservado ?? 0);

        return [
            'stored_stock' => $storedStock,
            'stored_reserved_stock' => $storedReserved,
            'stored_available_stock' => $storedStock - $storedReserved,
            'calculated_physical_stock' => $physical,
            'calculated_reserved_stock' => $reserved,
            'calculated_available_stock' => $physical - $reserved,
            'physical_difference' => $storedStock - $physical,
            'available_difference' => ($storedStock - $storedReserved) - ($physical - $reserved),
        ];
    }

    /**
     * @return Collection<int,object>
     */
    private function movements(string $materialId): Collection
    {
        return DB::table('stock_movements')
            ->where('article_id', $materialId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function acceptManualActions(object $material, array $filters): array
    {
        return $this->orphanMovements((string) $material->id, $filters)
            ->map(function (object $movement): array {
                $alreadyReviewed = $this->isReviewedOrphan($movement);
                $canMark = Schema::hasColumn('stock_movements', 'notes') && $this->blank($movement->notes ?? null);

                return [
                    'action_type' => 'accept_manual',
                    'movement_id' => (string) $movement->id,
                    'quantity' => 0,
                    'safe_to_apply' => $alreadyReviewed || $canMark,
                    'applied' => false,
                    'skipped' => $alreadyReviewed,
                    'reason' => $alreadyReviewed ? 'already_reviewed' : ($canMark ? 'orphan_can_be_marked_reviewed_in_notes' : 'unsupported_without_schema_change'),
                    'stock_movement_id' => null,
                    'product_updated' => false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,object>
     */
    private function orphanMovements(string $materialId, array $filters): Collection
    {
        $query = DB::table('stock_movements')
            ->where('article_id', $materialId)
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', ''))
            ->whereNull('reference_id')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['movement'] !== []) {
            $query->whereIn('id', $filters['movement']);
        }

        return $query->get();
    }

    /**
     * @param array<string,int> $current
     * @return array<string,mixed>|null
     */
    private function releaseReservationAction(object $material, array $current): ?array
    {
        $quantity = min((int) $current['stored_reserved_stock'], (int) $current['calculated_reserved_stock']);
        if ($quantity <= 0) {
            return [
                'action_type' => 'release_orphan_reservation',
                'quantity' => 0,
                'safe_to_apply' => false,
                'applied' => false,
                'skipped' => true,
                'reason' => 'no_reserved_stock_to_release',
                'stock_movement_id' => null,
                'product_updated' => false,
            ];
        }

        $blockers = $this->reservationBlockers((string) $material->id);
        if ($quantity !== (int) $current['calculated_reserved_stock']) {
            $blockers[] = 'reservation_release_does_not_resolve_calculated_balance';
        }

        return [
            'action_type' => 'release_orphan_reservation',
            'movement_type' => 'reservation',
            'quantity' => -$quantity,
            'physical_delta' => 0,
            'reserved_delta' => -$quantity,
            'safe_to_apply' => $blockers === [] && ! $this->existingResolutionMovement((string) $material->id, 'reservation', -$quantity, self::RELEASE_NOTE),
            'applied' => false,
            'skipped' => $this->existingResolutionMovement((string) $material->id, 'reservation', -$quantity, self::RELEASE_NOTE) !== null,
            'reason' => $blockers === [] ? 'release_resolves_orphan_reservation_balance' : implode(',', $blockers),
            'stock_movement_id' => null,
            'product_updated' => Schema::hasColumn('products', 'stock_reservado'),
            'expected_product_stock_reservado_after' => max(0, (int) $current['stored_reserved_stock'] - $quantity),
        ];
    }

    /**
     * @return list<string>
     */
    private function reservationBlockers(string $materialId): array
    {
        $blockers = [];

        if (Schema::hasTable('logistics_requests') && Schema::hasTable('logistics_request_items')) {
            $hasActiveRequest = DB::table('logistics_request_items')
                ->join('logistics_requests', 'logistics_requests.id', '=', 'logistics_request_items.logistics_request_id')
                ->where('logistics_request_items.article_id', $materialId)
                ->whereIn('logistics_requests.status', ['draft', 'pending', 'pendente', 'approved', 'aprovado', 'active', 'ativo'])
                ->exists();
            if ($hasActiveRequest) {
                $blockers[] = 'active_or_pending_logistics_request_present';
            }
        }

        if (Schema::hasTable('equipment_loans')) {
            $hasActiveLoan = DB::table('equipment_loans')
                ->where('article_id', $materialId)
                ->whereIn('status', ['active', 'overdue', 'ativo'])
                ->exists();
            if ($hasActiveLoan) {
                $blockers[] = 'active_loan_present';
            }
        }

        return $blockers;
    }

    /**
     * @param array<string,int> $current
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function physicalAdjustmentAction(object $material, array $current, array $filters): array
    {
        $target = $filters['target_physical_stock'] ?? (int) $current['stored_stock'];
        $difference = $target - (int) $current['calculated_physical_stock'];
        $movementType = $difference >= 0 ? 'entry' : 'exit';
        $quantity = abs($difference);
        $existing = $quantity > 0 ? $this->existingResolutionMovement((string) $material->id, $movementType, $movementType === 'entry' ? $quantity : -$quantity, self::PHYSICAL_ADJUSTMENT_NOTE) : null;
        $blockers = $this->physicalAdjustmentBlockers((string) $material->id, $quantity);

        return [
            'action_type' => 'physical_adjustment',
            'movement_type' => $movementType,
            'quantity' => $movementType === 'entry' ? $quantity : -$quantity,
            'target_physical_stock' => $target,
            'physical_delta' => $difference,
            'reserved_delta' => 0,
            'safe_to_apply' => $quantity > 0 && $existing === null && $blockers === [],
            'applied' => false,
            'skipped' => $quantity === 0 || $existing !== null,
            'reason' => $quantity === 0 ? 'physical_stock_already_aligned' : ($existing !== null ? 'already_resolved' : ($blockers === [] ? 'physical_adjustment_aligns_calculated_to_target' : implode(',', $blockers))),
            'stock_movement_id' => null,
            'product_updated' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function physicalAdjustmentBlockers(string $materialId, int $quantity): array
    {
        $blockers = [];

        if ($quantity > self::SMALL_PHYSICAL_ADJUSTMENT_LIMIT) {
            $blockers[] = 'physical_adjustment_difference_too_large';
        }

        if ($this->hasMissingSaleStockDecrease($materialId)) {
            $blockers[] = 'traceable_missing_sale_stock_decrease_present';
        }

        return $blockers;
    }

    private function hasMissingSaleStockDecrease(string $materialId): bool
    {
        if (! Schema::hasTable('loja_encomenda_itens') || ! Schema::hasTable('loja_encomendas')) {
            return false;
        }

        $items = DB::table('loja_encomenda_itens')
            ->join('loja_encomendas', 'loja_encomendas.id', '=', 'loja_encomenda_itens.loja_encomenda_id')
            ->where('loja_encomenda_itens.article_id', $materialId)
            ->whereIn('loja_encomendas.estado', ['preparado', 'entregue'])
            ->select(['loja_encomenda_itens.id', 'loja_encomenda_itens.quantidade'])
            ->get();

        foreach ($items as $item) {
            $hasDecrease = DB::table('stock_movements')
                ->where('article_id', $materialId)
                ->whereIn('movement_type', ['exit', 'sale', 'venda'])
                ->where('quantity', '>=', (int) $item->quantidade)
                ->exists();
            if (! $hasDecrease) {
                return true;
            }
        }

        return false;
    }

    private function existingResolutionMovement(string $materialId, string $movementType, int $quantity, string $note): ?object
    {
        $query = DB::table('stock_movements')
            ->where('article_id', $materialId)
            ->where('movement_type', $movementType)
            ->where('quantity', $quantity)
            ->where('notes', $note);

        if (Schema::hasColumn('stock_movements', 'reference_type')) {
            $query->where(function ($query): void {
                $query->where('reference_type', self::RESOLUTION_REFERENCE_TYPE)
                    ->orWhereNull('reference_type');
            });
        }

        return $query->first();
    }

    /**
     * @param list<array<string,mixed>> $actions
     * @return array<string,int>
     */
    private function expectedAfter(array $current, array $actions): array
    {
        $physical = (int) $current['calculated_physical_stock'];
        $reserved = (int) $current['calculated_reserved_stock'];
        $storedStock = (int) $current['stored_stock'];
        $storedReserved = (int) $current['stored_reserved_stock'];

        foreach ($actions as $action) {
            if (! (bool) ($action['safe_to_apply'] ?? false) && ($action['reason'] ?? null) !== 'already_resolved') {
                continue;
            }

            $physical += (int) ($action['physical_delta'] ?? 0);
            $reserved += (int) ($action['reserved_delta'] ?? 0);

            if (($action['action_type'] ?? null) === 'release_orphan_reservation') {
                $storedReserved = (int) ($action['expected_product_stock_reservado_after'] ?? $storedReserved);
            }
        }

        return [
            'stored_stock' => $storedStock,
            'stored_reserved_stock' => $storedReserved,
            'stored_available_stock' => $storedStock - $storedReserved,
            'calculated_physical_stock' => $physical,
            'calculated_reserved_stock' => $reserved,
            'calculated_available_stock' => $physical - $reserved,
            'physical_difference' => $storedStock - $physical,
            'available_difference' => ($storedStock - $storedReserved) - ($physical - $reserved),
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function applyMaterialPlan(object $material, array $plan, array $filters): array
    {
        return DB::transaction(function () use ($material, $plan, $filters): array {
            $product = Product::query()->whereKey((string) $material->id)->lockForUpdate()->first();
            if (! $product) {
                return [...$plan, 'errors' => ['product_not_found'], 'skipped' => true];
            }

            $applied = false;
            $errors = [];
            $actions = [];

            foreach ($plan['proposed_actions'] as $action) {
                if (! (bool) ($action['safe_to_apply'] ?? false)) {
                    $actions[] = [...$action, 'skipped' => true];
                    continue;
                }

                try {
                    $actions[] = $this->applyAction($product, $action, $filters);
                    $applied = $applied || (bool) end($actions)['applied'];
                } catch (\Throwable $exception) {
                    $errors[] = $exception->getMessage();
                    $actions[] = [...$action, 'failed' => true, 'applied' => false, 'skipped' => false, 'reason' => $exception->getMessage()];
                    throw $exception;
                }
            }

            $current = $this->stockState((string) $product->id);

            return [
                ...$plan,
                'current_state' => $current,
                'proposed_actions' => $actions,
                'expected_after' => $current,
                'applied' => $applied,
                'skipped' => ! $applied,
                'errors' => $errors,
            ];
        });
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function applyAction(Product $product, array $action, array $filters): array
    {
        return match ($action['action_type']) {
            'accept_manual' => $this->applyAcceptManual($action),
            'release_orphan_reservation' => $this->applyReleaseReservation($product, $action),
            'physical_adjustment' => $this->applyPhysicalAdjustment($product, $action),
            default => [...$action, 'skipped' => true, 'reason' => 'unsupported_action'],
        };
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyAcceptManual(array $action): array
    {
        if (($action['reason'] ?? null) === 'already_reviewed') {
            return [...$action, 'applied' => false, 'skipped' => true];
        }

        StockMovement::query()
            ->whereKey((string) $action['movement_id'])
            ->whereNull('reference_id')
            ->update([
                'notes' => self::REVIEW_NOTE_MARKER.' em '.Carbon::now()->toDateString(),
            ]);

        return [...$action, 'applied' => true, 'skipped' => false, 'orphan_movement_marked_reviewed' => true];
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyReleaseReservation(Product $product, array $action): array
    {
        if (($action['skipped'] ?? false) && ($action['reason'] ?? null) === 'already_resolved') {
            return [...$action, 'applied' => false, 'skipped' => true];
        }

        $movement = $this->createResolutionMovement($product, 'reservation', (int) $action['quantity'], self::RELEASE_NOTE);
        if (Schema::hasColumn('products', 'stock_reservado')) {
            $product->stock_reservado = max(0, (int) $product->stock_reservado + (int) $action['quantity']);
            $product->save();
        }

        return [
            ...$action,
            'applied' => true,
            'skipped' => false,
            'stock_movement_id' => (string) $movement->id,
            'product_updated' => Schema::hasColumn('products', 'stock_reservado'),
        ];
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyPhysicalAdjustment(Product $product, array $action): array
    {
        if (($action['skipped'] ?? false) && ($action['reason'] ?? null) === 'already_resolved') {
            return [...$action, 'applied' => false, 'skipped' => true];
        }

        $quantity = (int) $action['quantity'];
        $movementType = (string) $action['movement_type'];
        $movement = $this->createResolutionMovement($product, $movementType, abs($quantity), self::PHYSICAL_ADJUSTMENT_NOTE);

        return [...$action, 'quantity' => $movementType === 'entry' ? abs($quantity) : -abs($quantity), 'applied' => true, 'skipped' => false, 'stock_movement_id' => (string) $movement->id];
    }

    private function createResolutionMovement(Product $product, string $movementType, int $quantity, string $notes): StockMovement
    {
        $movement = new StockMovement();
        $movement->article_id = $product->id;
        $movement->movement_type = $movementType;
        $movement->quantity = $quantity;
        $movement->reference_type = self::RESOLUTION_REFERENCE_TYPE;
        $movement->reference_id = null;
        $movement->notes = $notes;
        $movement->created_at = Carbon::now();
        $movement->updated_at = Carbon::now();
        $movement->save();

        return $movement;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $actions = collect($items)->flatMap(fn (array $item): array => $item['proposed_actions'] ?? []);

        return [
            'total_materials_evaluated' => count($items),
            'proposed_action_count' => $actions->count(),
            'safe_action_count' => $actions->filter(fn (array $action): bool => (bool) ($action['safe_to_apply'] ?? false))->count(),
            'unsafe_action_count' => $actions->filter(fn (array $action): bool => ! (bool) ($action['safe_to_apply'] ?? false) && ! (bool) ($action['skipped'] ?? false))->count(),
            'applied_count' => $actions->filter(fn (array $action): bool => (bool) ($action['applied'] ?? false))->count(),
            'skipped_count' => $actions->filter(fn (array $action): bool => (bool) ($action['skipped'] ?? false))->count(),
            'failed_count' => $actions->filter(fn (array $action): bool => (bool) ($action['failed'] ?? false))->count(),
            'stock_movements_created_count' => $actions->filter(fn (array $action): bool => filled($action['stock_movement_id'] ?? null))->count(),
            'products_updated_count' => $actions->filter(fn (array $action): bool => (bool) ($action['product_updated'] ?? false) && (bool) ($action['applied'] ?? false))->count(),
            'orphan_movements_marked_reviewed_count' => $actions->filter(fn (array $action): bool => (bool) ($action['orphan_movement_marked_reviewed'] ?? false))->count(),
        ];
    }

    private function isReviewedOrphan(object $movement): bool
    {
        return str_contains((string) ($movement->notes ?? ''), self::REVIEW_NOTE_MARKER);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
