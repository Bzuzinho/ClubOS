<?php

namespace App\Services\Loja;

use App\Models\LojaEncomenda;
use App\Models\Movement;
use App\Models\MovementItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LojaFinanceiroService
{
    public function prepareForOrder(LojaEncomenda $encomenda): ?string
    {
        if ((float) $encomenda->total <= 0) {
            return null;
        }

        // Placeholder para futura integração com faturas/movimentos financeiros.
        return null;
    }

    public function syncDeliveredRevenueMovement(LojaEncomenda $encomenda): ?string
    {
        if ($encomenda->estado !== LojaEncomenda::ESTADO_ENTREGUE || (float) $encomenda->total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($encomenda) {
            $encomenda = LojaEncomenda::query()
                ->whereKey($encomenda->id)
                ->with(['itens', 'user:id,name,nome_completo'])
                ->lockForUpdate()
                ->firstOrFail();

            $movement = Movement::query()->firstOrNew([
                'origem_tipo' => 'stock',
                'origem_id' => $encomenda->id,
                'tipo' => 'material',
            ]);

            $movement->fill([
                'user_id' => $encomenda->user_id,
                'nome_manual' => $encomenda->user?->nome_completo ?: $encomenda->user?->name,
                'classificacao' => 'receita',
                'data_emissao' => now()->toDateString(),
                'data_vencimento' => now()->toDateString(),
                'valor_total' => $encomenda->total,
                'estado_pagamento' => $movement->exists ? $movement->estado_pagamento : 'pendente',
                'referencia_pagamento' => $encomenda->numero,
                'tipo' => 'material',
                'origem_tipo' => 'stock',
                'origem_id' => $encomenda->id,
                'observacoes' => 'Receita gerada automaticamente pela entrega da encomenda da Loja.',
            ]);
            $movement->save();

            MovementItem::query()->where('movimento_id', $movement->id)->delete();

            foreach ($encomenda->itens as $item) {
                MovementItem::create([
                    'movimento_id' => $movement->id,
                    'descricao' => $item->descricao,
                    'valor_unitario' => $item->preco_unitario,
                    'quantidade' => $item->quantidade,
                    'imposto_percentual' => 0,
                    'total_linha' => $item->total_linha,
                    'produto_id' => $item->article_id,
                ]);
            }

            $this->invalidateFinanceiroCaches();

            return $movement->id;
        });
    }

    private function invalidateFinanceiroCaches(): void
    {
        Cache::forget('financeiro:index');
        Cache::forget('financeiro:movimentos');
        Cache::forget('financeiro:movimento_itens');
        Cache::forget('dashboard:stats');
    }
}