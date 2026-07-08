<?php

namespace App\Services\Logistica;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\LogisticsRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceLogisticsRequestAction
{
    public function __construct(
        private readonly LogisticsRequestCostCenterResolver $costCenterResolver,
    ) {
    }

    public function execute(LogisticsRequest $request, array $payload = [], ?User $actor = null): LogisticsRequest
    {
        return DB::transaction(function () use ($request, $payload, $actor) {
            $request = LogisticsRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with(['items', 'requester'])
                ->firstOrFail();

            if (!in_array($request->status, ['approved', 'invoiced'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Apenas requisições aprovadas podem ser faturadas.',
                ]);
            }

            if (empty($request->requester_user_id)) {
                throw ValidationException::withMessages([
                    'requester_user_id' => 'A requisição não tem utilizador associado para faturação.',
                ]);
            }

            if ($request->financial_invoice_id) {
                return $request->fresh(['items', 'financialInvoice']);
            }

            $invoiceType = InvoiceType::query()->where('codigo', 'material')->first();

            if (!$invoiceType) {
                throw ValidationException::withMessages([
                    'tipo' => 'Não existe tipo de fatura configurado para gerar a faturação.',
                ]);
            }

            $issueDate = $payload['data_emissao'] ?? now()->toDateString();
            $dueDate = $payload['data_vencimento'] ?? now()->addDays(15)->toDateString();
            $centroCustoId = $this->costCenterResolver->resolveForRequester($request->requester_user_id);

            $invoice = Invoice::create([
                'user_id' => $request->requester_user_id,
                'data_fatura' => $issueDate,
                'data_emissao' => $issueDate,
                'data_vencimento' => $dueDate,
                'valor_total' => $request->total_amount,
                'estado_pagamento' => 'pendente',
                'tipo' => $invoiceType->codigo,
                'centro_custo_id' => $centroCustoId,
                'origem_tipo' => 'logistics_request',
                'origem_id' => $request->id,
                'observacoes' => $payload['observacoes'] ?? 'Fatura gerada automaticamente pela logística.',
            ]);

            foreach ($request->items as $item) {
                InvoiceItem::create([
                    'fatura_id' => $invoice->id,
                    'descricao' => $item->article_name_snapshot,
                    'quantidade' => $item->quantity,
                    'valor_unitario' => $item->unit_price,
                    'imposto_percentual' => 0,
                    'total_linha' => $item->line_total,
                    'produto_id' => $item->article_id,
                ]);
            }

            $request->update([
                'status' => 'invoiced',
                'financial_invoice_id' => $invoice->id,
            ]);

            return $request->fresh(['items', 'financialInvoice', 'requester']);
        });
    }
}
