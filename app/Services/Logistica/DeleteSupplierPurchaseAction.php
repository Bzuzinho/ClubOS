<?php

namespace App\Services\Logistica;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\SupplierPurchase;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteSupplierPurchaseAction
{
    public function __construct(
        private readonly SupplierPurchaseFinancialGuardService $financialGuardService,
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(SupplierPurchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $purchase->refresh()->load('items');

            if (!$this->financialGuardService->canDelete($purchase)) {
                throw ValidationException::withMessages([
                    'purchase' => 'Esta compra já possui liquidação, conciliação ou documento financeiro associado e não pode ser alterada diretamente.',
                ]);
            }

            $movement = $purchase->financial_movement_id
                ? Movement::query()->find($purchase->financial_movement_id)
                : null;

            if (!$movement) {
                throw ValidationException::withMessages([
                    'purchase' => 'Esta compra já possui liquidação, conciliação ou documento financeiro associado e não pode ser alterada diretamente.',
                ]);
            }

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
                        'occurred_at' => now(),
                        'idempotency_key' => 'supplier-purchase-delete-'.$purchase->id.'-'.$item->id,
                    ]);
                } catch (\App\Exceptions\Inventario\InsufficientStockException) {
                    throw ValidationException::withMessages([
                        'purchase' => 'Não é possível apagar: o stock atual ficaria negativo.',
                    ]);
                }
            }

            if ($purchase->financial_movement_id) {
                MovementItem::query()->where('movimento_id', $purchase->financial_movement_id)->delete();
                Movement::query()->where('id', $purchase->financial_movement_id)->delete();
            }

            $purchase->items()->delete();
            $purchase->delete();
        });
    }
}
