<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminLojaProdutoController extends Controller
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function index(Request $request): Response|JsonResponse
    {
        $query = Product::query()
            ->with(['category:id,nome', 'variants'])
            ->allowSale()
            ->ordered();

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->string('categoria_id')->value());
        }

        if ($request->filled('ativo')) {
            $query->where('ativo', $request->boolean('ativo'));
        }

        if ($request->boolean('stock_baixo')) {
            $query->whereRaw('(stock - COALESCE(stock_reservado, 0)) <= stock_minimo');
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('nome', 'like', "%{$search}%")
                    ->orWhere('codigo', 'like', "%{$search}%")
                    ->orWhere('descricao', 'like', "%{$search}%");
            });
        }

        $products = $query->get()->map(fn (Product $produto) => $this->serializeProduct($produto))->values()->all();

        if ($request->is('api/*')) {
            return response()->json($products);
        }

        return Inertia::render('Admin/Store/AdminProductList', [
            'products' => $products,
            'categories' => $this->categoriesPayload(),
            'filters' => $request->only(['search', 'categoria_id', 'ativo', 'stock_baixo']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Store/AdminProductForm', [
            'product' => null,
            'categories' => $this->categoriesPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validatePayload($request);
        $desiredStock = (int) $input['stock_atual'];
        $variants = is_array($request->input('variantes')) ? $request->input('variantes') : [];

        $product = DB::transaction(function () use ($input, $desiredStock, $variants, $request): Product {
            $product = Product::create([
                ...$this->normalizePayload($input),
                'stock' => 0,
                'stock_reservado' => 0,
            ]);
            $hasVariants = $this->syncVariants($product, $variants, (string) $request->user()?->id);

            if (! $hasVariants) {
                $this->stockLedger->adjustProductToStock($product, $desiredStock, $this->catalogAdjustmentContext($product));
            }

            return $product;
        });

        return response()->json($this->serializeProduct($product->fresh(['category', 'variants'])), 201);
    }

    public function show(Request $request, Product $produto): JsonResponse
    {
        return response()->json($this->serializeProduct($produto->load(['category', 'variants'])));
    }

    public function edit(Product $produto): Response
    {
        return Inertia::render('Admin/Store/AdminProductForm', [
            'product' => $this->serializeProduct($produto->load(['category', 'variants'])),
            'categories' => $this->categoriesPayload(),
        ]);
    }

    public function update(Request $request, Product $produto): JsonResponse
    {
        $input = $this->validatePayload($request, $produto);
        $desiredStock = (int) $input['stock_atual'];
        $variants = is_array($request->input('variantes')) ? $request->input('variantes') : [];

        DB::transaction(function () use ($input, $desiredStock, $variants, $produto, $request): void {
            $produto->update($this->normalizePayload($input, $produto));
            $hadVariants = $produto->variants()->exists();

            if ($variants !== [] && ! $hadVariants) {
                $this->stockLedger->adjustProductToStock($produto, 0, $this->catalogAdjustmentContext($produto));
            }

            $hasVariants = $this->syncVariants($produto, $variants, (string) $request->user()?->id);

            if (! $hasVariants) {
                $this->stockLedger->adjustProductToStock($produto, $desiredStock, $this->catalogAdjustmentContext($produto));
            }
        });

        return response()->json($this->serializeProduct($produto->fresh(['category', 'variants'])));
    }

    public function destroy(Product $produto): JsonResponse
    {
        $produto->update([
            'visible_in_store' => false,
            'allow_sale' => false,
            'destaque' => false,
        ]);

        return response()->json(['message' => 'Produto removido da loja com sucesso.']);
    }

    private function validatePayload(Request $request, ?Product $produto = null): array
    {
        return $request->validate([
            'categoria_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
            'codigo' => ['nullable', 'string', 'max:100', Rule::unique('products', 'codigo')->ignore($produto?->id)],
            'nome' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($produto?->id)],
            'descricao' => ['nullable', 'string'],
            'preco' => ['required', 'numeric', 'min:0'],
            'imagem_principal_path' => ['nullable', 'string', 'max:255'],
            'ativo' => ['required', 'boolean'],
            'destaque' => ['required', 'boolean'],
            'gere_stock' => ['required', 'boolean'],
            'stock_atual' => ['required', 'integer', 'min:0'],
            'stock_minimo' => ['nullable', 'integer', 'min:0'],
            'ordem' => ['nullable', 'integer'],
        ]);
    }

    private function normalizePayload(array $validated, ?Product $produto = null): array
    {
        $salePrice = (float) $validated['preco'];

        return [
            'categoria_id' => $validated['categoria_id'] ?? null,
            'codigo' => $validated['codigo'] ?? null,
            'nome' => $validated['nome'],
            'slug' => $this->resolveSlug($validated['slug'] ?? $produto?->slug, $validated['nome']),
            'descricao' => $validated['descricao'] ?? null,
            'preco' => $produto?->preco ?? $salePrice,
            'preco_venda' => $salePrice,
            'stock_minimo' => $validated['stock_minimo'] ?? 0,
            'imagem' => $validated['imagem_principal_path'] ?? null,
            'ativo' => (bool) $validated['ativo'],
            'visible_in_store' => (bool) $validated['ativo'],
            'destaque' => (bool) $validated['destaque'],
            'allow_sale' => true,
            'allow_request' => (bool) ($produto?->allow_request ?? false),
            'allow_loan' => (bool) ($produto?->allow_loan ?? false),
            'track_stock' => (bool) $validated['gere_stock'],
            'ordem' => $validated['ordem'] ?? null,
        ];
    }

    private function syncVariants(Product $produto, array $variantes, ?string $actorId = null): bool
    {
        $existingIds = collect($variantes)->pluck('id')->filter()->all();
        $retired = $produto->variants()
            ->when($existingIds !== [], fn ($query) => $query->whereNotIn('id', $existingIds))
            ->get();

        foreach ($retired as $variant) {
            $this->stockLedger->adjustVariantToStock(
                $produto,
                $variant,
                0,
                $this->catalogAdjustmentContext($produto, $variant, $actorId),
            );
            $variant->update(['ativo' => false]);
        }

        foreach ($variantes as $variant) {
            $payload = validator($variant, [
                'id' => ['nullable', 'uuid'],
                'nome' => ['nullable', 'string', 'max:255'],
                'tamanho' => ['nullable', 'string', 'max:80'],
                'cor' => ['nullable', 'string', 'max:80'],
                'sku' => ['nullable', 'string', 'max:120'],
                'preco_extra' => ['nullable', 'numeric', 'min:0'],
                'stock_atual' => ['nullable', 'integer', 'min:0'],
                'ativo' => ['required', 'boolean'],
            ])->validate();

            $variantId = $payload['id'] ?? null;

            $variantModel = filled($variantId)
                ? $produto->variants()->whereKey($variantId)->firstOrFail()
                : new ProductVariant([
                    'product_id' => $produto->id,
                    'stock' => 0,
                    'stock_reservado' => 0,
                ]);

            $desiredStock = (int) ($payload['stock_atual'] ?? 0);

            $variantModel->fill([
                'nome' => $payload['nome'] ?? null,
                'tamanho' => $payload['tamanho'] ?? null,
                'cor' => $payload['cor'] ?? null,
                'sku' => $payload['sku'] ?? null,
                'preco_extra' => $payload['preco_extra'] ?? 0,
                'ativo' => $payload['ativo'],
            ]);
            $variantModel->product_id = $produto->id;
            $variantModel->save();

            $this->stockLedger->adjustVariantToStock(
                $produto,
                $variantModel,
                $desiredStock,
                $this->catalogAdjustmentContext($produto, $variantModel, $actorId),
            );
        }

        return $variantes !== [];
    }

    /** @return array<string,mixed> */
    private function catalogAdjustmentContext(Product $product, ?ProductVariant $variant = null, ?string $actorId = null): array
    {
        return [
            'source_type' => 'catalog_manual_adjustment',
            'source_id' => $variant?->id ?? $product->id,
            'idempotency_key' => 'catalog-adjustment-'.Str::uuid(),
            'notes' => $variant
                ? 'Ajuste manual de stock de variante no catálogo'
                : 'Ajuste manual de stock de produto no catálogo',
            'created_by' => filled($actorId) ? $actorId : null,
        ];
    }

    private function categoriesPayload(): array
    {
        return ItemCategory::query()
            ->active()
            ->forContext('loja')
            ->orderBy('nome')
            ->get(['id', 'codigo', 'nome', 'contexto'])
            ->toArray();
    }

    private function resolveSlug(?string $slug, string $name): string
    {
        return Str::slug($slug ?: $name);
    }

    private function serializeProduct(Product $produto): array
    {
        return [
            'id' => $produto->id,
            'categoria_id' => $produto->categoria_id,
            'codigo' => $produto->codigo,
            'nome' => $produto->nome,
            'slug' => $produto->slug,
            'descricao' => $produto->descricao,
            'preco' => (float) $produto->sale_price,
            'imagem_principal_path' => $produto->imagem,
            'ativo' => (bool) $produto->ativo,
            'destaque' => (bool) $produto->destaque,
            'gere_stock' => (bool) $produto->tracks_stock,
            'stock_atual' => (int) $produto->stock,
            'stock_minimo' => $produto->stock_minimo,
            'tem_stock_baixo' => $produto->is_low_stock,
            'ordem' => $produto->ordem,
            'categoria' => $produto->category ? [
                'id' => $produto->category->id,
                'nome' => $produto->category->nome,
            ] : null,
            'variantes' => $produto->variants->map(fn ($variante) => [
                'id' => $variante->id,
                'nome' => $variante->nome,
                'tamanho' => $variante->tamanho,
                'cor' => $variante->cor,
                'sku' => $variante->sku,
                'preco_extra' => (float) $variante->preco_extra,
                'stock_atual' => (int) $variante->stock,
                'ativo' => (bool) $variante->ativo,
            ])->values(),
        ];
    }
}
