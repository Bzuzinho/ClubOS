<?php

namespace App\Http\Controllers;

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

        return response()->json(['message' => 'Destaque removido com sucesso.']);
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

        return response()->json(['message' => 'Destaques reordenados com sucesso.']);
    }

    private function validatePayload(Request $request, ?LojaHeroItem $item = null): array
    {
        return $request->validate([
            'produto_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('visible_in_store', true)
                    ->where('allow_sale', true)),
                Rule::unique('loja_hero_items', 'article_id')->ignore($item?->id),
            ],
            'ativo' => ['required', 'boolean'],
            'ordem' => ['nullable', 'integer'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);
    }

    private function normalizePayload(array $validated): array
    {
        $product = Product::query()->findOrFail($validated['produto_id']);

        return [
            'article_id' => $product->id,
            'titulo_curto' => 'Destaque',
            'titulo_principal' => $product->nome,
            'descricao' => $product->descricao,
            'texto_botao' => 'Ver artigo',
            'tipo_destino' => LojaHeroItem::DESTINO_PRODUTO,
            'categoria_id' => null,
            'url_destino' => null,
            'imagem_desktop_path' => null,
            'imagem_tablet_path' => null,
            'imagem_mobile_path' => null,
            'cor_fundo' => null,
            'ativo' => $validated['ativo'],
            'ordem' => $validated['ordem'] ?? 0,
            'data_inicio' => $validated['data_inicio'] ?? null,
            'data_fim' => $validated['data_fim'] ?? null,
        ];
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
                'imagem_principal_path' => $product->imagem,
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
            ->get(['id', 'nome', 'slug', 'imagem'])
            ->toArray();
    }
}
