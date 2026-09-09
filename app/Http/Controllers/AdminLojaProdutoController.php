<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminLojaProdutoController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $query = Product::query()
            ->with(['category:id,nome', 'variants'])
            ->ordered();

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->string('categoria_id')->value());
        }

        if ($request->filled('publicado')) {
            $published = $request->boolean('publicado');
            if ($published) {
                $query->where('ativo', true)->where('visible_in_store', true)->where('allow_sale', true);
            } else {
                $query->where(function ($subQuery) {
                    $subQuery->where('ativo', false)
                        ->orWhere('visible_in_store', false)
                        ->orWhere('allow_sale', false);
                });
            }
        }

        if ($request->boolean('stock_baixo')) {
            $query->active()->lowStock();
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
            'filters' => $request->only(['search', 'categoria_id', 'publicado', 'stock_baixo']),
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
        $variants = is_array($request->input('variantes')) ? $request->input('variantes') : [];

        $product = DB::transaction(function () use ($input, $variants): Product {
            $product = Product::create([
                ...$this->normalizePayload($input),
                'stock' => 0,
                'stock_reservado' => 0,
            ]);
            $this->syncVariants($product, $variants);

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
        $variants = is_array($request->input('variantes')) ? $request->input('variantes') : [];
        $shouldSyncVariants = $request->has('variantes');

        if ((bool) $input['publicado'] && ! $produto->ativo) {
            throw ValidationException::withMessages([
                'publicado' => 'O artigo está globalmente inativo. Ative-o primeiro no catálogo da Logística.',
            ]);
        }

        DB::transaction(function () use ($input, $variants, $produto, $shouldSyncVariants): void {
            $produto->update($this->normalizePayload($input, $produto));
            if ($shouldSyncVariants) {
                $this->syncVariants($produto, $variants);
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
            'publicado' => ['required', 'boolean'],
            'ordem' => ['nullable', 'integer'],
            'variantes' => ['sometimes', 'array'],
        ]);
    }

    private function normalizePayload(array $validated, ?Product $produto = null): array
    {
        $salePrice = (float) $validated['preco'];
        $published = (bool) $validated['publicado'];

        return [
            'categoria_id' => $produto?->categoria_id ?? ($validated['categoria_id'] ?? null),
            'codigo' => $produto?->codigo ?? ($validated['codigo'] ?? null),
            'nome' => $produto?->nome ?? $validated['nome'],
            'slug' => $this->resolveSlug($validated['slug'] ?? $produto?->slug, $validated['nome']),
            'descricao' => $validated['descricao'] ?? null,
            'preco' => $produto?->preco ?? $salePrice,
            'preco_venda' => $salePrice,
            'imagem' => $validated['imagem_principal_path'] ?? null,
            'visible_in_store' => $published,
            'allow_sale' => $published,
            'ordem' => $validated['ordem'] ?? null,
            ...($produto ? [] : [
                'ativo' => true,
                'destaque' => false,
                'allow_request' => false,
                'allow_loan' => false,
                'track_stock' => true,
                'stock_minimo' => 0,
            ]),
        ];
    }

    private function syncVariants(Product $produto, array $variantes): void
    {
        $createsFirstVariant = collect($variantes)->contains(fn ($variant) => blank($variant['id'] ?? null))
            && ! $produto->variants()->exists();

        if ($createsFirstVariant && ((int) $produto->stock !== 0 || (int) $produto->stock_reservado !== 0)) {
            throw ValidationException::withMessages([
                'variantes' => 'Antes de criar variantes, regularize o stock agregado deste artigo para zero na Logística.',
            ]);
        }

        $existingIds = collect($variantes)->pluck('id')->filter()->all();
        $retired = $produto->variants()
            ->when($existingIds !== [], fn ($query) => $query->whereNotIn('id', $existingIds))
            ->get();

        foreach ($retired as $variant) {
            if ((int) $variant->stock !== 0 || (int) $variant->stock_reservado !== 0) {
                throw ValidationException::withMessages([
                    'variantes' => "A variante {$variant->label} ainda tem stock. Regularize-o na Logística antes de a remover.",
                ]);
            }

            $variant->update(['ativo' => false]);
        }

        foreach ($variantes as $variant) {
            $variantId = $variant['id'] ?? null;
            $payload = validator($variant, [
                'id' => ['nullable', 'uuid'],
                'nome' => ['nullable', 'string', 'max:255'],
                'tamanho' => ['nullable', 'string', 'max:80'],
                'cor' => ['nullable', 'string', 'max:80'],
                'sku' => ['nullable', 'string', 'max:120', Rule::unique('product_variants', 'sku')->ignore($variantId)],
                'preco_extra' => ['nullable', 'numeric', 'min:0'],
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

            if (! (bool) $payload['ativo']
                && ((int) $variantModel->stock !== 0 || (int) $variantModel->stock_reservado !== 0)) {
                throw ValidationException::withMessages([
                    'variantes' => "A variante {$variantModel->label} ainda tem stock. Regularize-o na Logística antes de a desativar.",
                ]);
            }

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
        }
    }

    private function categoriesPayload(): array
    {
        return ItemCategory::query()
            ->active()
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
            'publicado' => (bool) ($produto->ativo && $produto->visible_in_store && $produto->allow_sale),
            'gere_stock' => (bool) $produto->tracks_stock,
            'stock_atual' => (int) $produto->available_stock,
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
                'stock_disponivel' => (int) $variante->available_stock,
                'ativo' => (bool) $variante->ativo,
            ])->values(),
        ];
    }
}
