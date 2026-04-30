<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use App\Models\LojaHeroItem;
use App\Models\Product;
use App\Services\Loja\LojaHeroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminLojaHeroController extends Controller
{
    public function __construct(
        private readonly LojaHeroService $heroService,
    ) {
    }

    public function index(Request $request): Response|JsonResponse
    {
        $payload = $this->heroService->adminList()->map(fn (LojaHeroItem $item) => $this->serializeHeroItem($item))->values()->all();

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Admin/Store/AdminHeroList', [
            'items' => $payload,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Store/AdminHeroForm', [
            'item' => null,
            'products' => $this->productsPayload(),
            'categories' => $this->categoriesPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = LojaHeroItem::create($this->normalizePayload($this->validatePayload($request)));

        return response()->json($this->serializeHeroItem($item->fresh(['article', 'categoria'])), 201);
    }

    public function edit(LojaHeroItem $item): Response
    {
        return Inertia::render('Admin/Store/AdminHeroForm', [
            'item' => $this->serializeHeroItem($item->load(['article', 'categoria'])),
            'products' => $this->productsPayload(),
            'categories' => $this->categoriesPayload(),
        ]);
    }

    public function update(Request $request, LojaHeroItem $item): JsonResponse
    {
        $item->update($this->normalizePayload($this->validatePayload($request, $item)));

        return response()->json($this->serializeHeroItem($item->fresh(['article', 'categoria'])));
    }

    public function destroy(LojaHeroItem $item): JsonResponse
    {
        $item->delete();

        return response()->json(['message' => 'Hero removido com sucesso.']);
    }

    public function toggle(LojaHeroItem $item): JsonResponse
    {
        return response()->json($this->serializeHeroItem($this->heroService->toggle($item)));
    }

    public function reordenar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['required', 'uuid', 'exists:loja_hero_items,id'],
        ]);

        $this->heroService->reorder($validated['ids']);

        return response()->json(['message' => 'Hero reordenado com sucesso.']);
    }

    private function validatePayload(Request $request, ?LojaHeroItem $item = null): array
    {
        return $request->validate([
            'titulo_curto' => ['nullable', 'string', 'max:255'],
            'titulo_principal' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'texto_botao' => ['nullable', 'string', 'max:255'],
            'tipo_destino' => ['nullable', Rule::in([
                LojaHeroItem::DESTINO_PRODUTO,
                LojaHeroItem::DESTINO_CATEGORIA,
                LojaHeroItem::DESTINO_URL,
                LojaHeroItem::DESTINO_NENHUM,
            ])],
            'produto_id' => ['nullable', 'uuid', 'exists:products,id'],
            'categoria_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
            'url_destino' => ['nullable', 'url'],
            'imagem_desktop_path' => ['nullable', 'string', 'max:255'],
            'imagem_tablet_path' => ['nullable', 'string', 'max:255'],
            'imagem_mobile_path' => ['nullable', 'string', 'max:255'],
            'cor_fundo' => ['nullable', 'string', 'max:20'],
            'ativo' => ['required', 'boolean'],
            'ordem' => ['nullable', 'integer'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);
    }

    private function normalizePayload(array $validated): array
    {
        $canonicalProductId = $validated['tipo_destino'] === LojaHeroItem::DESTINO_PRODUTO
            ? ($validated['produto_id'] ?? null)
            : null;

        $validated['article_id'] = $canonicalProductId;
        unset($validated['produto_id']);

        return $validated;
    }

    private function serializeHeroItem(LojaHeroItem $item): array
    {
        $product = $item->article;

        return [
            'id' => $item->id,
            'titulo_curto' => $item->titulo_curto,
            'titulo_principal' => $item->titulo_principal,
            'descricao' => $item->descricao,
            'texto_botao' => $item->texto_botao,
            'tipo_destino' => $item->tipo_destino,
            'produto_id' => $product?->id,
            'categoria_id' => $item->categoria_id,
            'url_destino' => $item->url_destino,
            'imagem_desktop_path' => $item->imagem_desktop_path,
            'imagem_tablet_path' => $item->imagem_tablet_path,
            'imagem_mobile_path' => $item->imagem_mobile_path,
            'cor_fundo' => $item->cor_fundo,
            'ativo' => (bool) $item->ativo,
            'ordem' => $item->ordem,
            'data_inicio' => $item->data_inicio?->toDateTimeString(),
            'data_fim' => $item->data_fim?->toDateTimeString(),
            'produto' => $product ? [
                'id' => $product->id,
                'nome' => $product->nome,
            ] : null,
            'categoria' => $item->categoria ? [
                'id' => $item->categoria->id,
                'nome' => $item->categoria->nome,
            ] : null,
        ];
    }

    private function productsPayload(): array
    {
        return Product::query()
            ->active()
            ->visibleInStore()
            ->allowSale()
            ->ordered()
            ->get(['id', 'nome', 'slug'])
            ->toArray();
    }

    private function categoriesPayload(): array
    {
        return ItemCategory::query()->active()->forContext('loja')->orderBy('nome')->get(['id', 'nome'])->toArray();
    }
}