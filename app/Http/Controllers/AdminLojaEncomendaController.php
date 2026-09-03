<?php

namespace App\Http\Controllers;

use App\Models\LojaEncomenda;
use App\Services\Loja\LojaEncomendaService;
use App\Services\Loja\StoreOrderFinancialProjection;
use App\Services\Loja\StoreOrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminLojaEncomendaController extends Controller
{
    public function __construct(
        private readonly LojaEncomendaService $encomendaService,
        private readonly StoreOrderFinancialProjection $financialProjection,
        private readonly StoreOrderReturnService $returnService,
    ) {
    }

    public function index(Request $request): Response|JsonResponse
    {
        $query = LojaEncomenda::query()
            ->with(['itens.article', 'itens.productVariant', 'user:id,nome_completo', 'targetUser:id,nome_completo', 'invoice.fiscalDocumentRequests', 'devolucao.fiscalDocumentRequest'])
            ->ordered();

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado')->value());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->value());
        }

        $payload = $query->get()->map(fn (LojaEncomenda $encomenda) => $this->serializeOrder($encomenda, false))->values()->all();

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Admin/Store/AdminOrdersTable', [
            'orders' => $payload,
            'filters' => $request->only(['estado', 'user_id']),
        ]);
    }

    public function show(Request $request, LojaEncomenda $encomenda): Response|JsonResponse
    {
        $payload = $this->serializeOrder($encomenda->load(['itens.article', 'itens.productVariant', 'user:id,nome_completo', 'targetUser:id,nome_completo', 'invoice.fiscalDocumentRequests', 'devolucao.fiscalDocumentRequest']), true);

        if ($request->is('api/*')) {
            return response()->json($payload);
        }

        return Inertia::render('Admin/Store/AdminOrderDetail', [
            'order' => $payload,
        ]);
    }

    public function updateEstado(Request $request, LojaEncomenda $encomenda): JsonResponse
    {
        $validated = $request->validate([
            'estado' => ['required', 'string'],
        ]);

        $order = $this->encomendaService->updateEstado($encomenda, $validated['estado'], $request->user());

        return response()->json($this->serializeOrder($order, true));
    }

    public function processDevolucao(Request $request, LojaEncomenda $encomenda): JsonResponse
    {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->returnService->process($encomenda, $request->user(), $validated['motivo']);
        $order = $encomenda->fresh([
            'itens.article',
            'itens.productVariant',
            'user:id,nome_completo',
            'targetUser:id,nome_completo',
            'invoice.fiscalDocumentRequests',
            'devolucao.fiscalDocumentRequest',
        ]);

        return response()->json($this->serializeOrder($order, true));
    }

    private function serializeOrder(LojaEncomenda $encomenda, bool $withItems): array
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
            'financeiro' => $this->financialProjection->forOrder($encomenda),
            'devolucao' => $encomenda->devolucao ? [
                'id' => $encomenda->devolucao->id,
                'estado' => $encomenda->devolucao->estado,
                'motivo' => $encomenda->devolucao->motivo,
                'solicitada_em' => $encomenda->devolucao->solicitada_em?->toIso8601String(),
                'concluida_em' => $encomenda->devolucao->concluida_em?->toIso8601String(),
                'nota_credito' => $encomenda->devolucao->fiscalDocumentRequest ? [
                    'id' => $encomenda->devolucao->fiscalDocumentRequest->id,
                    'estado' => $encomenda->devolucao->fiscalDocumentRequest->status,
                    'numero_externo' => $encomenda->devolucao->fiscalDocumentRequest->external_document_number,
                ] : null,
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
