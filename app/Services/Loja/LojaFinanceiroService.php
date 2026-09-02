<?php

namespace App\Services\Loja;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\LojaEncomenda;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Services\Financeiro\InvoiceFinancialGuardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LojaFinanceiroService
{
    private const INVOICE_ORIGIN = 'store_order';

    private const INVOICE_TYPE = 'material';

    public function __construct(
        private readonly StoreOrderCostCenterResolver $costCenterResolver,
        private readonly InvoiceFinancialGuardService $financialGuardService,
    ) {
    }

    public function prepareForOrder(LojaEncomenda $encomenda): ?string
    {
        if ((float) $encomenda->total <= 0) {
            return null;
        }

        return DB::transaction(function () use ($encomenda): string {
            $encomenda = LojaEncomenda::query()
                ->whereKey($encomenda->id)
                ->with(['itens', 'user:id,name,nome_completo', 'targetUser:id,name,nome_completo'])
                ->lockForUpdate()
                ->firstOrFail();

            $debtorUserId = $encomenda->target_user_id ?: $encomenda->user_id;

            if ($encomenda->fatura_id) {
                $invoice = Invoice::query()->whereKey($encomenda->fatura_id)->lockForUpdate()->first();
                $this->assertCanonicalInvoice($encomenda, $invoice, $debtorUserId);

                return (string) $invoice->id;
            }

            $existingInvoice = Invoice::query()
                ->where('origem_tipo', self::INVOICE_ORIGIN)
                ->where('origem_id', $encomenda->id)
                ->lockForUpdate()
                ->first();

            if ($existingInvoice) {
                $this->assertCanonicalInvoice($encomenda, $existingInvoice, $debtorUserId);
                $encomenda->update(['fatura_id' => $existingInvoice->id]);

                return (string) $existingInvoice->id;
            }

            $invoiceType = InvoiceType::query()
                ->where('codigo', self::INVOICE_TYPE)
                ->where('ativo', true)
                ->first();

            if (! $invoiceType) {
                throw ValidationException::withMessages([
                    'tipo' => 'Não existe um tipo de fatura ativo para vendas de material da Loja.',
                ]);
            }

            $issueDate = now()->toDateString();
            $costCenterId = $this->costCenterResolver->resolveForUser($debtorUserId);

            $invoice = Invoice::query()->create([
                'user_id' => $debtorUserId,
                'data_fatura' => $issueDate,
                'data_emissao' => $issueDate,
                'data_vencimento' => now()->addDays(15)->toDateString(),
                'valor_total' => $encomenda->total,
                'valor_pago' => 0,
                'valor_em_aberto' => $encomenda->total,
                'oculta' => true,
                'estado_pagamento' => 'pendente',
                'referencia_pagamento' => $encomenda->numero,
                'centro_custo_id' => $costCenterId,
                'tipo' => $invoiceType->codigo,
                'origem_tipo' => self::INVOICE_ORIGIN,
                'origem_id' => $encomenda->id,
                'observacoes' => 'Fatura gerada automaticamente pela encomenda '.$encomenda->numero.' da Loja.',
            ]);

            foreach ($encomenda->itens as $item) {
                InvoiceItem::query()->create([
                    'fatura_id' => $invoice->id,
                    'descricao' => $item->descricao,
                    'quantidade' => $item->quantidade,
                    'valor_unitario' => $item->preco_unitario,
                    'imposto_percentual' => 0,
                    'total_linha' => $item->total_linha,
                    'produto_id' => $item->article_id,
                    'centro_custo_id' => $costCenterId,
                ]);
            }

            $encomenda->update(['fatura_id' => $invoice->id]);
            $invoice->update(['oculta' => false]);
            $this->invalidateFinanceiroCaches();

            return (string) $invoice->id;
        });
    }

    public function cancelPristineInvoiceForOrder(LojaEncomenda $encomenda): void
    {
        if (! $encomenda->fatura_id) {
            return;
        }

        $invoice = Invoice::query()
            ->whereKey($encomenda->fatura_id)
            ->lockForUpdate()
            ->first();

        $debtorUserId = $encomenda->target_user_id ?: $encomenda->user_id;
        $this->assertCanonicalInvoice($encomenda, $invoice, $debtorUserId);

        if ($invoice->estado_pagamento === 'cancelado') {
            return;
        }

        if ($this->financialGuardService->hasFinancialOrFiscalTrail($invoice)) {
            throw ValidationException::withMessages([
                'estado' => 'A encomenda já tem pagamentos, conciliação ou documento fiscal e exige reversão financeira antes do cancelamento.',
            ]);
        }

        if (! in_array($invoice->estado_pagamento, ['pendente', 'vencido'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'O estado financeiro da encomenda exige reversão explícita antes do cancelamento.',
            ]);
        }

        $invoice->forceFill([
            'estado_pagamento' => 'cancelado',
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'data_pagamento' => null,
            'metodo_pagamento' => null,
            'pagamento_observacoes' => null,
        ])->save();

        $this->invalidateFinanceiroCaches();
    }

    public function syncDeliveredRevenueMovement(LojaEncomenda $encomenda): ?string
    {
        if (
            $encomenda->estado !== LojaEncomenda::ESTADO_ENTREGUE
            || (float) $encomenda->total <= 0
            || filled($encomenda->fatura_id)
        ) {
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
        Cache::forget('financeiro:faturas');
        Cache::forget('financeiro:fatura_itens');
        Cache::forget('financeiro:movimentos');
        Cache::forget('financeiro:movimento_itens');
        Cache::forget('dashboard:stats');
    }

    private function assertCanonicalInvoice(LojaEncomenda $encomenda, ?Invoice $invoice, string $debtorUserId): void
    {
        if (! $invoice) {
            throw ValidationException::withMessages([
                'fatura_id' => 'A referência financeira da encomenda é inválida.',
            ]);
        }

        if (
            $invoice->origem_tipo !== self::INVOICE_ORIGIN
            || (string) $invoice->origem_id !== (string) $encomenda->id
            || (string) $invoice->user_id !== $debtorUserId
            || abs((float) $invoice->valor_total - (float) $encomenda->total) > 0.009
        ) {
            throw ValidationException::withMessages([
                'fatura_id' => 'A fatura associada não corresponde ao contrato financeiro canónico da encomenda.',
            ]);
        }
    }
}
