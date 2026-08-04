<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\User;
use App\Services\Loja\LojaCarrinhoService;
use App\Services\Loja\LojaEncomendaService;
use App\Services\Loja\LojaHeroService;
use App\Services\Loja\StorefrontCatalogService;
use App\Services\Loja\StoreProfileResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LojaController extends Controller
{
    public function __construct(
        private readonly LojaHeroService $heroService,
        private readonly LojaCarrinhoService $carrinhoService,
        private readonly LojaEncomendaService $encomendaService,
        private readonly StoreProfileResolver $profileResolver,
        private readonly StorefrontCatalogService $catalogService,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Store/StoreHomePage', [
            'heroItems' => $this->heroItemsPayload(),
            'categories' => $this->categoriesPayload(),
            'featuredProducts' => $this->highlightedProductsPayload($request),
            'products' => $this->productsPayload($request, false),
            'filters' => [
                'search' => trim((string) $request->query('search', '')),
                'categoria' => $request->query('categoria'),
            ],
            'cart' => $this->cartPayload($user),
            'profiles' => $this->profilesPayload($user),
        ]);
    }

    public function hero(): JsonResponse
    {
        return response()->json($this->heroItemsPayload());
    }

    public function categorias(): JsonResponse
    {
        return response()->json($this->categoriesPayload());
    }

    public function produtos(Request $request): JsonResponse
    {
        return response()->json($this->productsPayload($request, false));
    }

    private function heroItemsPayload(): array
    {
        return $this->heroService->activeItems()
            ->map(function ($item) {
                $product = $item->article && $item->article->ativo && $item->article->visible_in_store
                    ? $item->article
                    : null;

                return [
                    'id' => $item->id,
                    'titulo_curto' => $item->titulo_curto,
                    'titulo_principal' => $item->titulo_principal,
                    'descricao' => $item->descricao,
                    'texto_botao' => $item->texto_botao,
                    'tipo_destino' => $item->tipo_destino,
                    'produto' => $product ? [
                        'id' => $product->id,
                        'slug' => $product->slug,
                        'nome' => $product->nome,
                    ] : null,
                    'categoria' => $item->categoria ? [
                        'id' => $item->categoria->id,
                        'nome' => $item->categoria->nome,
                    ] : null,
                    'url_destino' => $item->url_destino,
                    'imagem_desktop_path' => $item->imagem_desktop_path,
                    'imagem_tablet_path' => $item->imagem_tablet_path,
                    'imagem_mobile_path' => $item->imagem_mobile_path,
                    'cor_fundo' => $item->cor_fundo ?: '#0f4c81',
                ];
            })
            ->values()
            ->all();
    }

    private function categoriesPayload(): array
    {
        return $this->catalogService->categoriesPayload();
    }

    private function productsPayload(Request $request, bool $onlyFeatured): array
    {
        return $this->catalogService->productsPayload(
            trim((string) $request->query('search', '')),
            $request->query('categoria'),
            $onlyFeatured,
        );
    }

    private function highlightedProductsPayload(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->query('categoria');

        return $this->heroService->activeItems()
            ->map(fn ($item) => $item->article)
            ->filter(fn ($product) => $product
                && $product->ativo
                && $product->visible_in_store
                && $product->allow_sale)
            ->when(filled($categoryId), fn ($products) => $products->filter(
                fn ($product) => (string) $product->categoria_id === (string) $categoryId
            ))
            ->when($search !== '', fn ($products) => $products->filter(function ($product) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $product->nome,
                    $product->codigo,
                    $product->descricao,
                ])));

                return str_contains($haystack, mb_strtolower($search));
            }))
            ->unique('id')
            ->map(function ($product): array {
                $product->loadMissing([
                    'category:id,nome',
                    'variants' => fn ($query) => $query->active()->orderBy('nome')->orderBy('tamanho')->orderBy('cor'),
                ]);

                return $this->catalogService->serializeProduct($product);
            })
            ->values()
            ->all();
    }

    private function cartPayload(User $user): array
    {
        $cart = $this->carrinhoService->getOpenCart($user);

        if (! $cart) {
            return [
                'id' => null,
                'estado' => 'aberto',
                'observacoes' => null,
                'items' => [],
                'subtotal' => 0,
                'total' => 0,
                'count' => 0,
            ];
        }

        $items = $cart->itens->map(function ($item) {
            $product = $item->article;
            $variant = $item->productVariant;

            return [
                'id' => $item->id,
                'quantidade' => (int) $item->quantidade,
                'preco_unitario' => (float) $item->preco_unitario,
                'total_linha' => (float) $item->total_linha,
                'produto' => $product ? [
                    'id' => $product->id,
                    'nome' => $product->nome,
                    'slug' => $product->slug,
                    'imagem_principal_path' => $product->imagem ?? $product->imagem_principal_path,
                    'stock_atual' => (int) ($product->available_stock ?? $product->stock_atual ?? 0),
                ] : null,
                'variante' => $variant ? [
                    'id' => $variant->id,
                    'etiqueta' => $variant->label ?? $variant->etiqueta,
                ] : null,
            ];
        })->values();

        return [
            'id' => $cart->id,
            'estado' => $cart->estado,
            'observacoes' => $cart->observacoes,
            'items' => $items,
            'subtotal' => (float) $items->sum('total_linha'),
            'total' => (float) $items->sum('total_linha'),
            'count' => (int) $items->sum('quantidade'),
        ];
    }

    private function profilesPayload(User $user): array
    {
        return $this->profileResolver->allowedProfiles($user)
            ->map(fn (User $profile) => [
                'id' => $profile->id,
                'nome_completo' => $profile->nome_completo,
                'is_self' => $profile->id === $user->id,
            ])
            ->values()
            ->all();
    }
}
