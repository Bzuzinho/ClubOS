<?php

namespace App\Services\Logistica;

use App\Models\Product;
use App\Models\SupplierPurchaseItem;

class ProductProcurementCostService
{
    public function refreshFromLatestPurchase(string $productId): void
    {
        $latestItem = SupplierPurchaseItem::query()
            ->select('supplier_purchase_items.*')
            ->join('supplier_purchases', 'supplier_purchases.id', '=', 'supplier_purchase_items.supplier_purchase_id')
            ->where('supplier_purchase_items.article_id', $productId)
            ->orderByDesc('supplier_purchases.invoice_date')
            ->orderByDesc('supplier_purchase_items.created_at')
            ->first();

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->forceFill([
                'ultimo_custo' => $latestItem?->unit_cost,
            ])->save();
        }
    }
}
