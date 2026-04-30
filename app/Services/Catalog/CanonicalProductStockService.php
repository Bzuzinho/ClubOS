<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class CanonicalProductStockService
{
    public function ensureAvailable(Product $product, int $quantity, string $errorKey = 'quantity', ?string $message = null): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                $errorKey => 'A quantidade deve ser pelo menos 1.',
            ]);
        }

        if (! $product->tracks_stock) {
            return;
        }

        if ($quantity > $this->availableStock($product)) {
            throw ValidationException::withMessages([
                $errorKey => $message ?? 'Quantidade pedida superior ao stock disponível.',
            ]);
        }
    }

    public function ensureAvailableForStore(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if (! $product->ativo) {
            throw ValidationException::withMessages([
                'article_id' => 'O produto selecionado está inativo.',
            ]);
        }

        if (! $product->visible_in_store) {
            throw ValidationException::withMessages([
                'article_id' => 'O produto selecionado nao esta disponivel na loja.',
            ]);
        }

        if ($variant && ! $variant->ativo) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'A variante selecionada esta inativa.',
            ]);
        }

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantidade' => 'A quantidade deve ser pelo menos 1.',
            ]);
        }

        if (! $product->tracks_stock) {
            return;
        }

        if ($quantity > $this->availableStock($product, $variant)) {
            throw ValidationException::withMessages([
                'quantidade' => 'Quantidade pedida superior ao stock disponível.',
            ]);
        }
    }

    public function availableStock(Product $product, ?ProductVariant $variant = null): int
    {
        if (! $product->tracks_stock) {
            return PHP_INT_MAX;
        }

        if ($variant) {
            return (int) $variant->available_stock;
        }

        return (int) $product->available_stock;
    }

    public function saleUnitPrice(Product $product, ?ProductVariant $variant = null): float
    {
        return (float) $product->sale_price + (float) ($variant?->preco_extra ?? 0);
    }

    public function defaultUnitPrice(Product $product): float
    {
        return (float) ($product->preco ?? $product->sale_price);
    }

    public function decrementOnSale(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if (! $product->tracks_stock) {
            return;
        }

        $this->ensureAvailableForStore($product, $variant, $quantity);

        if ($variant) {
            $variant->decrement('stock', $quantity);
        }

        $product->decrement('stock', $quantity);
    }
}