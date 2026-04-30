<?php

namespace App\Http\Controllers;

use App\Models\LojaCarrinhoItem;
use App\Services\Loja\LojaCarrinhoService;
use App\Services\Loja\LojaEncomendaService;
use App\Services\Loja\StoreProfileResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LojaCarrinhoController extends Controller
{
    public function __construct(
        private readonly LojaCarrinhoService $carrinhoService,
        private readonly LojaEncomendaService $encomendaService,
        private readonly StoreProfileResolver $profileResolver,
    ) {
    }

    public function show(Request $request): Response|JsonResponse
    {
        $cart = $this->carrinhoService->getOpenCart($request->user());
        $payload = $this->serializeCart($cart);

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Store/CartPage', [
            'cart' => $payload,
            'profiles' => $this->profileResolver->allowedProfiles($request->user())
                ->map(fn ($profile) => [
                    'id' => $profile->id,
                    'nome_completo' => $profile->nome_completo,
                    'is_self' => $profile->id === $request->user()->id,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'article_id' => ['required', 'uuid', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'uuid', 'exists:product_variants,id'],
            'quantidade' => ['required', 'integer', 'min:1'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $cart = $this->carrinhoService->addItem($request->user(), $validated);

        return response()->json($this->serializeCart($cart), 201);
    }

    public function update(Request $request, LojaCarrinhoItem $item): JsonResponse
    {
        abort_unless($item->carrinho?->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'product_variant_id' => ['nullable', 'uuid', 'exists:product_variants,id'],
            'quantidade' => ['required', 'integer', 'min:1'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $cart = $this->carrinhoService->updateItem($request->user(), $item, $validated);

        return response()->json($this->serializeCart($cart));
    }

    public function destroy(Request $request, LojaCarrinhoItem $item): JsonResponse
    {
        abort_unless($item->carrinho?->user_id === $request->user()->id, 404);

        $cart = $this->carrinhoService->removeItem($request->user(), $item);

        return response()->json($this->serializeCart($cart));
    }

    public function submeter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'observacoes' => ['nullable', 'string'],
        ]);

        $encomenda = $this->encomendaService->submit($request->user(), $validated);

        return response()->json([
            'message' => 'Encomenda submetida com sucesso.',
            'encomenda_id' => $encomenda->id,
            'numero' => $encomenda->numero,
        ], 201);
    }

    private function serializeCart($cart): array
    {
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
}