<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StockCorrectionApplicationService
{
    private const VERSION = 'b1-4-stock-correction-application-v1';
    private const NOTES_MISSING_SALE_EXIT = 'Baixa de stock por venda/encomenda entregue registada retroativamente';

    public function __construct(
        private readonly StockCorrectionPreflightService $preflightService,
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $filters = $this->filters($options);
        $preflight = $this->preflightService->preflight([
            'material' => $filters['material'],
            'only_safe' => $filters['only_safe'],
        ]);

        $blockedByConfirmation = $filters['apply'] && ! $filters['confirmed_stock_correction'];
        $blockedByUnsafe = $filters['apply']
            && $filters['fail_on_unsafe']
            && (int) data_get($preflight, 'summary.unsafe_action_count', 0) > 0;
        $dryRun = ! $filters['apply'] || $blockedByConfirmation || $blockedByUnsafe;

        $results = [];
        foreach ($preflight['items'] ?? [] as $item) {
            foreach ($item['proposed_actions'] ?? [] as $action) {
                if ($filters['action'] !== null && $filters['action'] !== (string) ($action['action_type'] ?? '')) {
                    continue;
                }

                $results[] = $this->evaluateAction($item, $action, $dryRun, $blockedByConfirmation, $blockedByUnsafe);
            }
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'dry_run' => $dryRun,
            'apply' => $filters['apply'],
            'confirmed_stock_correction' => $filters['confirmed_stock_correction'],
            'filters' => $filters,
            'summary' => $this->summary($preflight, $results),
            'items' => $results,
            'read_only_when_dry_run' => $dryRun,
            'blocked' => $blockedByConfirmation || $blockedByUnsafe,
            'block_reason' => $blockedByConfirmation
                ? 'confirm_stock_correction_required'
                : ($blockedByUnsafe ? 'unsafe_actions_present' : null),
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

        $action = $this->stringOrNull($options['action'] ?? null);

        return [
            'material' => array_values(array_filter(array_map('strval', is_array($materials) ? $materials : []))),
            'action' => $action,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'apply' => (bool) ($options['apply'] ?? false),
            'confirmed_stock_correction' => (bool) ($options['confirm_stock_correction'] ?? false),
            'only_safe' => (bool) ($options['only_safe'] ?? false),
            'fail_on_unsafe' => (bool) ($options['fail_on_unsafe'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function evaluateAction(array $item, array $action, bool $dryRun, bool $blockedByConfirmation, bool $blockedByUnsafe): array
    {
        $result = $this->baseResult($item, $action);

        if ($blockedByConfirmation) {
            return [
                ...$result,
                'skipped' => true,
                'status' => 'apply_blocked_confirmation_required',
                'error' => 'confirm_stock_correction_required',
            ];
        }

        if ($blockedByUnsafe) {
            return [
                ...$result,
                'skipped' => true,
                'status' => 'apply_blocked_unsafe_actions_present',
                'error' => 'unsafe_actions_present',
            ];
        }

        if ($dryRun) {
            return [
                ...$result,
                'status' => (bool) ($action['safe_to_apply'] ?? false) ? 'dry_run_ready' : 'dry_run_blocked',
                'skipped' => ! (bool) ($action['safe_to_apply'] ?? false),
                'error' => (bool) ($action['safe_to_apply'] ?? false) ? null : 'unsafe_action_requires_manual_review',
            ];
        }

        if (! (bool) ($action['safe_to_apply'] ?? false)) {
            return [
                ...$result,
                'status' => (string) ($action['action_type'] ?? '') === 'no_action_needed' ? 'no_action_needed' : 'unsafe_action_not_applied',
                'skipped' => true,
                'error' => (string) ($action['action_type'] ?? '') === 'no_action_needed' ? null : 'unsafe_action_requires_manual_review',
            ];
        }

        if ((string) ($action['action_type'] ?? '') !== 'create_missing_sale_stock_decrease') {
            return [
                ...$result,
                'status' => 'unsupported_safe_action',
                'skipped' => true,
                'error' => 'unsupported_safe_action',
            ];
        }

        try {
            return [
                ...$result,
                ...$this->applyMissingSaleStockDecrease($item, $action),
            ];
        } catch (Throwable $exception) {
            return [
                ...$result,
                'status' => 'failed',
                'failed' => true,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function baseResult(array $item, array $action): array
    {
        return [
            'material_id' => (string) ($item['material_id'] ?? ''),
            'material_name' => $item['material_name'] ?? null,
            'action_type' => (string) ($action['action_type'] ?? ''),
            'safe_to_apply' => (bool) ($action['safe_to_apply'] ?? false),
            'applied' => false,
            'skipped' => false,
            'failed' => false,
            'stock_movement_id' => null,
            'product_stock_before' => null,
            'product_stock_after' => null,
            'expected_after' => $action['expected_after'] ?? null,
            'error' => null,
            'status' => null,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function applyMissingSaleStockDecrease(array $item, array $action): array
    {
        return DB::transaction(function () use ($item, $action): array {
            $materialId = (string) ($item['material_id'] ?? '');
            $targetId = (string) ($action['target_id'] ?? '');
            $movementPayload = $action['proposed_stock_movement'] ?? [];
            $quantity = (int) ($movementPayload['quantity'] ?? 0);
            $expectedPhysicalAfter = (int) data_get($action, 'expected_after.expected_physical_after');
            $expectedDifferenceAfter = (int) data_get($action, 'expected_after.expected_physical_difference_after');

            if ($quantity <= 0) {
                throw new \RuntimeException('quantity_must_be_positive');
            }

            if ($expectedDifferenceAfter !== 0) {
                throw new \RuntimeException('preflight_action_does_not_resolve_mismatch');
            }

            $product = Product::query()->whereKey($materialId)->lockForUpdate()->first();
            if (! $product) {
                throw new \RuntimeException('product_not_found');
            }

            $stockBefore = (int) $product->stock;
            if ($stockBefore !== (int) ($item['stored_stock'] ?? 0)) {
                throw new \RuntimeException('product_stock_changed_since_preflight');
            }

            $orderItem = LojaEncomendaItem::query()->whereKey($targetId)->lockForUpdate()->first();
            if (! $orderItem) {
                throw new \RuntimeException('store_order_item_not_found');
            }

            if ((string) $orderItem->article_id !== $materialId) {
                throw new \RuntimeException('store_order_item_material_mismatch');
            }

            if ((int) $orderItem->quantidade !== $quantity) {
                throw new \RuntimeException('store_order_item_quantity_mismatch');
            }

            $order = LojaEncomenda::query()->whereKey($orderItem->loja_encomenda_id)->lockForUpdate()->first();
            if (! $order || $order->estado !== LojaEncomenda::ESTADO_ENTREGUE) {
                throw new \RuntimeException('store_order_not_delivered');
            }

            $existingMovement = $this->existingSourceExit($materialId, $targetId);
            if ($existingMovement) {
                return [
                    'status' => 'already_applied',
                    'skipped' => true,
                    'stock_movement_id' => (string) $existingMovement->id,
                    'product_stock_before' => $stockBefore,
                    'product_stock_after' => $stockBefore,
                    'error' => null,
                ];
            }

            $movement = $this->stockLedger->registerExit($product, $quantity, [
                'source_type' => 'store_order_item',
                'source_id' => $orderItem->id,
                'notes' => self::NOTES_MISSING_SALE_EXIT,
                'occurred_at' => $this->businessDateForOrderItem($order, $orderItem),
                'idempotency_key' => 'b1-4-missing-sale-stock-decrease-'.$orderItem->id,
            ]);

            return [
                'status' => 'applied',
                'applied' => true,
                'stock_movement_id' => (string) $movement->id,
                'product_stock_before' => $stockBefore,
                'product_stock_after' => (int) $product->fresh()->stock,
                'error' => null,
            ];
        });
    }

    private function existingSourceExit(string $materialId, string $sourceId): ?StockMovement
    {
        if (trim($sourceId) === '') {
            return null;
        }

        return StockMovement::query()
            ->where('article_id', $materialId)
            ->whereIn('movement_type', ['exit', 'sale', 'venda'])
            ->whereIn('reference_type', ['store_order_item', 'loja_encomenda_item'])
            ->where('reference_id', $sourceId)
            ->orderBy('created_at')
            ->first();
    }

    private function businessDateForOrderItem(LojaEncomenda $order, LojaEncomendaItem $item): Carbon
    {
        return Carbon::parse($order->updated_at ?? $order->created_at ?? $item->created_at ?? Carbon::now());
    }

    /**
     * @param array<string,mixed> $preflight
     * @param list<array<string,mixed>> $results
     * @return array<string,int>
     */
    private function summary(array $preflight, array $results): array
    {
        $rows = collect($results);

        return [
            'total_materials_evaluated' => (int) data_get($preflight, 'summary.total_materials_evaluated', 0),
            'safe_action_count' => $rows->filter(fn (array $row): bool => (bool) $row['safe_to_apply'])->count(),
            'unsafe_action_count' => $rows->filter(fn (array $row): bool => ! (bool) $row['safe_to_apply'] && $row['action_type'] !== 'no_action_needed')->count(),
            'applied_count' => $rows->filter(fn (array $row): bool => (bool) ($row['applied'] ?? false))->count(),
            'skipped_count' => $rows->filter(fn (array $row): bool => (bool) ($row['skipped'] ?? false))->count(),
            'failed_count' => $rows->filter(fn (array $row): bool => (bool) ($row['failed'] ?? false))->count(),
            'stock_movements_created_count' => $rows->filter(fn (array $row): bool => (bool) ($row['applied'] ?? false) && filled($row['stock_movement_id'] ?? null))->count(),
            'products_updated_count' => $rows->filter(fn (array $row): bool => ($row['product_stock_before'] ?? null) !== null
                && ($row['product_stock_after'] ?? null) !== null
                && (int) $row['product_stock_before'] !== (int) $row['product_stock_after'])->count(),
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
