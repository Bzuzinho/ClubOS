<?php

namespace App\Services\Logistica;

use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupplierPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteSupplierPurchaseAction
{
    public function __construct(
        private readonly SupplierPurchaseFinancialGuardService $financialGuardService,
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

                $newStock = (int) $product->stock - (int) $item->quantity;
                if ($newStock < 0) {
                    throw ValidationException::withMessages([
                        'purchase' => 'Não é possível apagar: o stock atual ficaria negativo.',
                    ]);
                }

                $product->stock = $newStock;
                $product->save();
            }

            StockMovement::query()
                ->where('reference_type', 'supplier_purchase')
                ->where('reference_id', $purchase->id)
                ->delete();

            if ($purchase->financial_movement_id) {
                MovementItem::query()->where('movimento_id', $purchase->financial_movement_id)->delete();
                Movement::query()->where('id', $purchase->financial_movement_id)->delete();
            }

            $purchase->items()->delete();
            $purchase->delete();
        });
    }
}
