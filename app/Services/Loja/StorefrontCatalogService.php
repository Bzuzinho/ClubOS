<?php

namespace App\Services\Loja;

use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StorefrontCatalogService
{
    public function categoriesPayload(): array
    {
        return ItemCategory::query()
            ->active()
            ->forContext('loja')
            ->whereHas('products', function (Builder $query) {
                $query->active()->visibleInStore();
            })
            ->orderBy('nome')
            ->get()
            ->map(fn (ItemCategory $category) => [
                'id' => $category->id,
                'codigo' => $category->codigo,
                'nome' => $category->nome,
                'contexto' => $category->contexto,
            ])
            ->values()
            ->all();
    }

    public function productsPayload(string $search = '', mixed $categoryId = null, bool $onlyFeatured = false): array
    {
        $query = Product::query()
            ->with([
                'category:id,nome',
                'variants' => fn ($variantQuery) => $variantQuery->active()->orderBy('nome')->orderBy('tamanho')->orderBy('cor'),
            ])
            ->active()
            ->visibleInStore()
            ->ordered();

        if ($onlyFeatured) {
            $query->featured();
        }

        if (filled($categoryId)) {
            $query->where('categoria_id', (string) $categoryId);
        }

        if ($search !== '') {
            $query->where(function (Builder $subQuery) use ($search) {
                $subQuery->where('nome', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%")
                    ->orWhere('descricao', 'like', "%{$search}%");
            });
        }

        return $query->get()->map(fn (Product $product) => $this->serializeProduct($product))->values()->all();
    }

    public function productDetailPayload(string $slug): array
    {
        $product = Product::query()
            ->with([
                'category:id,nome',
                'variants' => fn ($variantQuery) => $variantQuery->active()->orderBy('nome')->orderBy('tamanho')->orderBy('cor'),
            ])
            ->active()
            ->visibleInStore()
            ->where('slug', $slug)
            ->first();

        if (! $product || ! $product->ativo || ! $product->visible_in_store) {
            throw (new ModelNotFoundException())->setModel(Product::class, [$slug]);
        }

        return $this->serializeProduct($product);
    }

    public function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'categoria_id' => $product->categoria_id,
            'codigo' => $product->codigo,
            'nome' => $product->nome,
            'slug' => $product->slug,
            'descricao' => $product->descricao,
            'preco' => (float) $product->sale_price,
            'imagem_principal_path' => $product->imagem,
            'ativo' => (bool) $product->ativo,
            'destaque' => (bool) ($product->destaque ?? false),
            'gere_stock' => (bool) $product->tracks_stock,
            'stock_atual' => (int) $product->available_stock,
            'stock_minimo' => $product->stock_minimo,
            'tem_stock_baixo' => $product->is_low_stock,
            'categoria' => $product->category ? [
                'id' => $product->category->id,
                'nome' => $product->category->nome,
            ] : null,
            'variantes' => $product->variants->map(fn (ProductVariant $variant) => $this->serializeVariant($variant))->values(),
        ];
    }

    private function serializeVariant(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'nome' => $variant->nome,
            'tamanho' => $variant->tamanho,
            'cor' => $variant->cor,
            'sku' => $variant->sku,
            'preco_extra' => (float) $variant->preco_extra,
            'stock_atual' => (int) $variant->available_stock,
            'etiqueta' => $variant->label,
        ];
    }
}