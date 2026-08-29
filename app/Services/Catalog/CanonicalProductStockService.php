<?php

namespace App\Services\Catalog;

use App\Exceptions\Inventario\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Validation\ValidationException;

class CanonicalProductStockService
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

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
        $this->ensureEligibleForStore($product, $variant, $quantity);

        if (! $product->tracks_stock) {
            return;
        }

        if ($quantity > $this->availableStock($product, $variant)) {
            throw ValidationException::withMessages([
                'quantidade' => 'Quantidade pedida superior ao stock disponível.',
            ]);
        }
    }

    private function ensureEligibleForStore(Product $product, ?ProductVariant $variant, int $quantity): void
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

    /**
     * @param array<string,mixed> $context
     */
    public function decrementOnSale(Product $product, ?ProductVariant $variant, int $quantity, array $context = []): void
    {
        $this->ensureEligibleForStore($product, $variant, $quantity);

        if (! $product->tracks_stock) {
            return;
        }

        try {
            $this->stockLedger->registerExit($product, $quantity, [
                'product_variant_id' => $variant?->id,
                'source_type' => $context['source_type'] ?? 'store_order_item',
                'source_id' => $context['source_id'] ?? null,
                'idempotency_key' => $context['idempotency_key'] ?? null,
                'notes' => $context['notes'] ?? 'Saída de stock por encomenda da loja',
                'created_by' => $context['created_by'] ?? null,
                'occurred_at' => $context['occurred_at'] ?? now(),
            ]);
        } catch (InsufficientStockException) {
            throw ValidationException::withMessages([
                'quantidade' => 'Quantidade pedida superior ao stock disponível.',
            ]);
        }
    }
}
