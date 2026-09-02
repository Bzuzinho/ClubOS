<?php

namespace App\Http\Controllers;

use App\Models\LojaEncomenda;
use App\Services\Loja\LojaEncomendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LojaEncomendaController extends Controller
{
    public function __construct(
        private readonly LojaEncomendaService $encomendaService,
    ) {
    }

    public function index(Request $request): Response|JsonResponse
    {
        $query = LojaEncomenda::query()
            ->with(['itens.article', 'itens.productVariant', 'user:id,nome_completo', 'targetUser:id,nome_completo', 'invoice:id,estado_pagamento,valor_pago,valor_em_aberto'])
            ->ordered();

        $this->encomendaService->visibleForUser($query, $request->user());

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado')->value());
        }

        $payload = $query->get()->map(fn (LojaEncomenda $encomenda) => $this->serializeOrder($encomenda))->values()->all();

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Store/OrderHistoryPage', [
            'orders' => $payload,
            'filter' => [
                'estado' => $request->query('estado'),
            ],
        ]);
    }

    public function show(Request $request, LojaEncomenda $encomenda): Response|JsonResponse
    {
        $query = LojaEncomenda::query()->whereKey($encomenda->id);
        $this->encomendaService->visibleForUser($query, $request->user());
        abort_unless($query->exists(), 404);

        $encomenda->load(['itens.article.category', 'itens.productVariant', 'user:id,nome_completo', 'targetUser:id,nome_completo', 'invoice:id,estado_pagamento,valor_pago,valor_em_aberto']);
        $payload = $this->serializeOrder($encomenda, true);

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Store/OrderDetailPage', [
            'order' => $payload,
        ]);
    }

    private function serializeOrder(LojaEncomenda $encomenda, bool $withItems = false): array
    {
        return [
            'id' => $encomenda->id,
            'numero' => $encomenda->numero,
            'estado' => $encomenda->estado,
            'subtotal' => (float) $encomenda->subtotal,
            'total' => (float) $encomenda->total,
            'observacoes' => $encomenda->observacoes,
            'created_at' => $encomenda->created_at?->toDateTimeString(),
            'invoice' => $encomenda->invoice ? [
                'id' => $encomenda->invoice->id,
                'estado_pagamento' => $encomenda->invoice->estado_pagamento,
                'valor_pago' => (float) $encomenda->invoice->valor_pago,
                'valor_em_aberto' => (float) $encomenda->invoice->valor_em_aberto,
            ] : null,
            'user' => $encomenda->user ? [
                'id' => $encomenda->user->id,
                'nome_completo' => $encomenda->user->nome_completo,
            ] : null,
            'target_user' => $encomenda->targetUser ? [
                'id' => $encomenda->targetUser->id,
                'nome_completo' => $encomenda->targetUser->nome_completo,
            ] : null,
            'items' => $withItems ? $encomenda->itens->map(function ($item) {
                $product = $item->article;
                $variant = $item->productVariant;

                return [
                    'id' => $item->id,
                    'descricao' => $item->descricao,
                    'quantidade' => (int) $item->quantidade,
                    'preco_unitario' => (float) $item->preco_unitario,
                    'total_linha' => (float) $item->total_linha,
                    'produto' => $product ? [
                        'id' => $product->id,
                        'slug' => $product->slug,
                        'nome' => $product->nome,
                    ] : null,
                    'variante' => $variant ? [
                        'id' => $variant->id,
                        'etiqueta' => $variant->label ?? $variant->etiqueta,
                    ] : null,
                ];
            })->values() : [],
        ];
    }
}
