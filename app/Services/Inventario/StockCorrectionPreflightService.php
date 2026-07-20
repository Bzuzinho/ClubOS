<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Support\Carbon;

final class StockCorrectionPreflightService
{
    private const VERSION = 'b1-3-stock-correction-preflight-v1';

    public function __construct(
        private readonly StockMismatchInspectionService $inspectionService,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function preflight(array $options = []): array
    {
        $filters = $this->filters($options);
        $inspection = $this->inspectionService->inspect([
            'material' => $filters['material'],
            'only_mismatch' => false,
        ]);

        $items = collect($inspection['items'] ?? [])
            ->map(fn (array $item): array => $this->planForItem($item))
            ->when($filters['only_safe'], fn ($items) => $items
                ->map(fn (array $item): array => [
                    ...$item,
                    'proposed_actions' => array_values(array_filter($item['proposed_actions'], static fn (array $action): bool => (bool) $action['safe_to_apply'])),
                ])
                ->filter(fn (array $item): bool => $item['proposed_actions'] !== []))
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

        return [
            'material' => array_values(array_filter(array_map('strval', is_array($materials) ? $materials : []))),
            'only_safe' => (bool) ($options['only_safe'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $inspectionItem
     * @return array<string,mixed>
     */
    private function planForItem(array $inspectionItem): array
    {
        $actions = [];
        $physicalDifference = $this->int($inspectionItem['physical_difference'] ?? $inspectionItem['difference'] ?? 0);
        $availableDifference = $this->int($inspectionItem['available_difference'] ?? 0);
        $materialId = (string) data_get($inspectionItem, 'material.id', '');

        $orphanPhysicalMovements = $this->orphanPhysicalMovements($inspectionItem);
        if ($orphanPhysicalMovements !== []) {
            $actions[] = $this->orphanPhysicalAction($inspectionItem, $orphanPhysicalMovements);
        }

        $missingSaleItems = $this->missingSaleItems($inspectionItem);
        if ($missingSaleItems !== []) {
            $actions[] = $this->missingSaleAction($inspectionItem, $missingSaleItems);
        }

        $orphanReservationMovements = $this->orphanReservationMovements($inspectionItem);
        if ($orphanReservationMovements !== [] || $this->int($inspectionItem['calculated_available_stock'] ?? 0) < 0 || $this->int($inspectionItem['stored_available_stock'] ?? 0) < 0) {
            $actions[] = $this->orphanReservationAction($inspectionItem, $orphanReservationMovements);
        }

        if ($physicalDifference !== 0 && $orphanPhysicalMovements === [] && $missingSaleItems === []) {
            $actions[] = $this->physicalAdjustmentAction($inspectionItem);
        }

        if ($physicalDifference === 0 && $availableDifference === 0 && $actions === []) {
            $actions[] = [
                'action_type' => 'no_action_needed',
                'safe_to_apply' => false,
                'requires_manual_review' => false,
                'reason' => 'stock_already_coherent',
                'target_table' => 'products',
                'target_id' => $materialId,
                'proposed_stock_movement' => null,
                'expected_after' => $this->expectedAfter($inspectionItem, 0),
            ];
        }

        return [
            'material_id' => $materialId,
            'material_name' => data_get($inspectionItem, 'material.name'),
            'stored_stock' => $this->int($inspectionItem['stored_stock'] ?? 0),
            'stored_reserved_stock' => $this->int($inspectionItem['stored_reserved_stock'] ?? 0),
            'calculated_physical_stock' => $this->int($inspectionItem['calculated_physical_stock'] ?? 0),
            'calculated_reserved_stock' => $this->int($inspectionItem['calculated_reserved_stock'] ?? 0),
            'calculated_available_stock' => $this->int($inspectionItem['calculated_available_stock'] ?? 0),
            'physical_difference' => $physicalDifference,
            'available_difference' => $availableDifference,
            'proposed_actions' => $actions,
            'final_recommendation' => $this->finalRecommendation($actions),
            'read_only' => true,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function orphanPhysicalMovements(array $inspectionItem): array
    {
        return collect($inspectionItem['movements'] ?? [])
            ->filter(fn (array $movement): bool => in_array('orphan_physical_stock_movement', $movement['suspicion_flags'] ?? [], true))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function orphanReservationMovements(array $inspectionItem): array
    {
        return collect($inspectionItem['movements'] ?? [])
            ->filter(fn (array $movement): bool => in_array('orphan_reservation_movement', $movement['suspicion_flags'] ?? [], true))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function missingSaleItems(array $inspectionItem): array
    {
        return collect($inspectionItem['related_sales_or_invoice_items'] ?? [])
            ->filter(fn (array $item): bool => in_array('missing_sale_stock_decrease', $item['suspicion_flags'] ?? [], true))
            ->values()
            ->all();
    }

    /**
     * @param list<array<string,mixed>> $movements
     * @return array<string,mixed>
     */
    private function orphanPhysicalAction(array $inspectionItem, array $movements): array
    {
        $impact = collect($movements)->sum(fn (array $movement): int => $this->int($movement['physical_delta'] ?? 0));

        return [
            'action_type' => 'inspect_orphan_physical_stock_movements',
            'safe_to_apply' => false,
            'requires_manual_review' => true,
            'reason' => 'physical_stock_movements_without_source_require_manual_review',
            'target_table' => 'stock_movements',
            'target_id' => null,
            'orphan_movements' => $movements,
            'orphan_physical_net_impact' => $impact,
            'proposed_stock_movement' => null,
            'expected_after' => $this->expectedAfter($inspectionItem, 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $missingSaleItems
     * @return array<string,mixed>
     */
    private function missingSaleAction(array $inspectionItem, array $missingSaleItems): array
    {
        $quantity = collect($missingSaleItems)->sum(fn (array $item): int => $this->int($item['quantity'] ?? 0));
        $single = count($missingSaleItems) === 1;
        $saleItem = $missingSaleItems[0] ?? [];
        $physicalDifference = $this->int($inspectionItem['physical_difference'] ?? 0);
        $safe = $single
            && $quantity > 0
            && (string) data_get($inspectionItem, 'material.id', '') !== ''
            && (string) ($saleItem['status'] ?? '') === 'entregue'
            && $physicalDifference === -$quantity;

        return [
            'action_type' => 'create_missing_sale_stock_decrease',
            'safe_to_apply' => $safe,
            'requires_manual_review' => ! $safe,
            'reason' => $safe
                ? 'single_delivered_sale_without_physical_exit_resolves_mismatch_exactly'
                : 'delivered_sale_without_physical_exit_requires_manual_review_or_does_not_resolve_mismatch_exactly',
            'target_table' => $this->targetTableForSaleItem($saleItem),
            'target_id' => $this->targetIdForSaleItem($saleItem),
            'proposed_stock_movement' => [
                'material_id' => data_get($inspectionItem, 'material.id'),
                'type' => 'exit',
                'quantity' => $quantity,
                'source_type' => $this->sourceTypeForSaleItem($saleItem),
                'source_id' => $this->targetIdForSaleItem($saleItem),
                'notes' => 'Baixa de stock por venda/encomenda entregue registada retroativamente',
            ],
            'expected_after' => $this->expectedAfter($inspectionItem, -$quantity),
        ];
    }

    /**
     * @param list<array<string,mixed>> $movements
     * @return array<string,mixed>
     */
    private function orphanReservationAction(array $inspectionItem, array $movements): array
    {
        return [
            'action_type' => 'inspect_orphan_reservation_balance',
            'safe_to_apply' => false,
            'requires_manual_review' => true,
            'reason' => 'reservation_balance_or_available_stock_requires_manual_review',
            'target_table' => 'stock_movements',
            'target_id' => null,
            'orphan_reservation_movements' => $movements,
            'proposed_stock_movement' => null,
            'expected_after' => $this->expectedAfter($inspectionItem, 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function physicalAdjustmentAction(array $inspectionItem): array
    {
        $physicalDifference = $this->int($inspectionItem['physical_difference'] ?? 0);

        return [
            'action_type' => 'create_physical_stock_adjustment',
            'safe_to_apply' => false,
            'requires_manual_review' => true,
            'reason' => 'residual_physical_difference_requires_explicit_manual_review_before_adjustment',
            'target_table' => 'products',
            'target_id' => data_get($inspectionItem, 'material.id'),
            'proposed_stock_movement' => [
                'material_id' => data_get($inspectionItem, 'material.id'),
                'type' => 'adjustment',
                'quantity' => $physicalDifference,
                'source_type' => 'stock_correction_preflight',
                'source_id' => null,
                'notes' => 'Ajuste de stock físico sujeito a revisão manual',
            ],
            'expected_after' => $this->expectedAfter($inspectionItem, $physicalDifference),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function expectedAfter(array $inspectionItem, int $physicalDelta): array
    {
        $physicalAfter = $this->int($inspectionItem['calculated_physical_stock'] ?? 0) + $physicalDelta;
        $reservedAfter = $this->int($inspectionItem['calculated_reserved_stock'] ?? 0);
        $availableAfter = $physicalAfter - $reservedAfter;

        return [
            'expected_physical_after' => $physicalAfter,
            'expected_reserved_after' => $reservedAfter,
            'expected_available_after' => $availableAfter,
            'expected_physical_difference_after' => $this->int($inspectionItem['stored_stock'] ?? 0) - $physicalAfter,
        ];
    }

    /**
     * @param list<array<string,mixed>> $actions
     */
    private function finalRecommendation(array $actions): string
    {
        $priority = [
            'inspect_orphan_physical_stock_movements',
            'create_missing_sale_stock_decrease',
            'inspect_orphan_reservation_balance',
            'create_physical_stock_adjustment',
            'no_action_needed',
        ];

        foreach ($priority as $actionType) {
            if (collect($actions)->contains(fn (array $action): bool => $action['action_type'] === $actionType)) {
                return $actionType;
            }
        }

        return 'no_action_needed';
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $actions = collect($items)->pluck('proposed_actions')->flatten(1);

        return [
            'total_materials_evaluated' => count($items),
            'safe_action_count' => $actions->filter(fn (array $action): bool => (bool) $action['safe_to_apply'])->count(),
            'unsafe_action_count' => $actions->filter(fn (array $action): bool => ! (bool) $action['safe_to_apply'] && $action['action_type'] !== 'no_action_needed')->count(),
            'manual_review_count' => $actions->filter(fn (array $action): bool => (bool) $action['requires_manual_review'])->count(),
            'missing_sale_stock_decrease_action_count' => $actions->filter(fn (array $action): bool => $action['action_type'] === 'create_missing_sale_stock_decrease')->count(),
            'orphan_physical_review_count' => $actions->filter(fn (array $action): bool => $action['action_type'] === 'inspect_orphan_physical_stock_movements')->count(),
            'stock_adjustment_action_count' => $actions->filter(fn (array $action): bool => $action['action_type'] === 'create_physical_stock_adjustment')->count(),
            'no_action_needed_count' => $actions->filter(fn (array $action): bool => $action['action_type'] === 'no_action_needed')->count(),
        ];
    }

    private function sourceTypeForSaleItem(array $item): string
    {
        return match ((string) ($item['source'] ?? '')) {
            'store_order_item' => 'store_order_item',
            'legacy_sale' => 'legacy_sale',
            'invoice_item' => 'invoice_item',
            default => 'sale',
        };
    }

    private function targetTableForSaleItem(array $item): string
    {
        return match ((string) ($item['source'] ?? '')) {
            'store_order_item' => 'loja_encomenda_itens',
            'legacy_sale' => 'sales',
            'invoice_item' => 'invoice_items',
            default => 'sales',
        };
    }

    private function targetIdForSaleItem(array $item): ?string
    {
        return $this->stringOrNull($item['sale_id'] ?? null)
            ?? $this->stringOrNull($item['invoice_item_id'] ?? null)
            ?? $this->stringOrNull($item['invoice_id'] ?? null);
    }

    private function int(mixed $value): int
    {
        return (int) ($value ?? 0);
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
