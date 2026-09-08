<?php

namespace App\Services\Logistica;

use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseDeletionAudit;
use App\Models\User;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteSupplierPurchaseAction
{
    public function __construct(
        private readonly SupplierPurchaseFinancialGuardService $financialGuardService,
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(SupplierPurchase $purchase, ?User $actor = null, ?string $reason = null): void
    {
        if (!$actor) {
            $authenticatedUser = auth()->user();
            $actor = $authenticatedUser instanceof User ? $authenticatedUser : null;
        }

        DB::transaction(function () use ($purchase, $actor, $reason): void {
            $purchase = SupplierPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            $blockingReasons = $this->financialGuardService->blockingReasons($purchase);
            if ($blockingReasons !== []) {
                throw ValidationException::withMessages([
                    'purchase' => $this->financialGuardService->deletionBlockMessage($blockingReasons),
                ]);
            }

            $movement = $purchase->financial_movement_id
                ? Movement::query()->lockForUpdate()->find($purchase->financial_movement_id)
                : null;

            if (!$movement) {
                throw ValidationException::withMessages([
                    'purchase' => 'A ligação financeira da compra não é válida. A eliminação direta foi bloqueada para evitar perda de histórico.',
                ]);
            }

            $this->assertCurrentStockProvenance($purchase);

            $movementItems = MovementItem::query()
                ->where('movimento_id', $movement->id)
                ->get();
            $movementDocuments = MovementDocument::query()
                ->where('movement_id', $movement->id)
                ->get();
            $storedPaths = $movementDocuments
                ->pluck('stored_path')
                ->filter(fn ($path): bool => filled($path))
                ->map('strval')
                ->unique()
                ->values()
                ->all();

            foreach ($purchase->items as $item) {
                if (empty($item->article_id)) {
                    continue;
                }

                $product = Product::query()->lockForUpdate()->find($item->article_id);
                if (!$product) {
                    continue;
                }

                try {
                    $this->stockLedger->registerExit($product, (int) $item->quantity, [
                        'source_type' => 'supplier_purchase_delete',
                        'source_id' => $item->id,
                        'notes' => 'Reversão de entrada de stock por eliminação de compra a fornecedor',
                        'created_by' => $actor?->id,
                        'occurred_at' => now(),
                        'idempotency_key' => 'supplier-purchase-delete-'.$purchase->id.'-'.$item->id,
                    ]);
                } catch (\App\Exceptions\Inventario\InsufficientStockException) {
                    throw ValidationException::withMessages([
                        'purchase' => 'Não é possível apagar esta compra porque parte do stock já foi consumida, reservada ou movimentada. Neste caso deve ser feita uma anulação/reversão, não uma eliminação.',
                    ]);
                }
            }

            SupplierPurchaseDeletionAudit::query()->create([
                'supplier_purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'supplier_name_snapshot' => $purchase->supplier_name_snapshot,
                'invoice_reference' => $purchase->invoice_reference,
                'invoice_date' => $purchase->invoice_date,
                'total_amount' => $purchase->total_amount,
                'financial_movement_id' => $movement->id,
                'deleted_by' => $actor?->id,
                'deletion_mode' => 'hard_delete_pre_settlement',
                'reason' => $reason ?: 'Eliminação administrativa antes de liquidação/conciliação.',
                'payload' => [
                    'purchase' => $purchase->toArray(),
                    'items' => $purchase->items->toArray(),
                    'financial_movement' => $movement->toArray(),
                    'movement_items' => $movementItems->toArray(),
                    'movement_documents' => $movementDocuments->toArray(),
                ],
                'deleted_at' => now(),
            ]);

            MovementItem::query()->where('movimento_id', $movement->id)->delete();
            Movement::query()->where('id', $movement->id)->delete();

            $purchase->items()->delete();
            $purchase->delete();

            if ($storedPaths !== []) {
                DB::afterCommit(function () use ($storedPaths): void {
                    Storage::disk('public')->delete($storedPaths);
                });
            }
        });
    }

    private function assertCurrentStockProvenance(SupplierPurchase $purchase): void
    {
        $items = $purchase->items
            ->filter(fn ($item): bool => filled($item->article_id))
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $expectedByProduct = $items
            ->groupBy(fn ($item): string => (string) $item->article_id)
            ->map(fn (Collection $productItems): int => (int) $productItems->sum('quantity'));
        $itemIds = $items->pluck('id')->map('strval')->values()->all();

        $currentUpdateEntries = StockMovement::query()
            ->where('reference_type', 'supplier_purchase_update_entry')
            ->whereIn('reference_id', $itemIds)
            ->where('movement_type', 'entry')
            ->get();

        if ($currentUpdateEntries->isNotEmpty()) {
            $actualByProduct = $currentUpdateEntries
                ->groupBy(fn (StockMovement $movement): string => (string) $movement->article_id)
                ->map(fn (Collection $movements): int => (int) $movements->sum('quantity'));
        } else {
            $initialEntries = StockMovement::query()
                ->where('reference_type', 'supplier_purchase')
                ->where('reference_id', $purchase->id)
                ->where('movement_type', 'entry')
                ->get();

            $actualByProduct = $initialEntries
                ->groupBy(fn (StockMovement $movement): string => (string) $movement->article_id)
                ->map(fn (Collection $movements): int => (int) $movements->sum('quantity'));
        }

        $hasExistingDeleteMovement = StockMovement::query()
            ->where('reference_type', 'supplier_purchase_delete')
            ->whereIn('reference_id', $itemIds)
            ->exists();

        if ($hasExistingDeleteMovement || $expectedByProduct->all() !== $actualByProduct->all()) {
            throw ValidationException::withMessages([
                'purchase' => 'O histórico de stock desta compra não corresponde aos artigos atualmente registados. A eliminação foi bloqueada para não criar uma correção de stock incorreta.',
            ]);
        }
    }
}
