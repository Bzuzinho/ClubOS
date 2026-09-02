<?php

declare(strict_types=1);

namespace App\Services\Loja;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LojaEncomenda;

final class StoreOrderFinancialProjection
{
    /**
     * @return array<string,mixed>
     */
    public function forOrder(LojaEncomenda $order): array
    {
        $order->loadMissing('invoice.fiscalDocumentRequests');

        $invoice = $order->invoice;
        if (! $invoice instanceof Invoice) {
            return [
                'estado_pagamento' => (float) $order->total > 0 ? 'sem_fatura' : 'nao_aplicavel',
                'pagamento_confirmado' => false,
                'valor_pago' => 0.0,
                'valor_em_aberto' => max((float) $order->total, 0.0),
                'estado_fiscal' => 'nao_aplicavel',
                'pedido_fiscal_id' => null,
                'provider_fiscal' => null,
                'modo_fiscal' => (string) config('fiscal.operation_mode', 'manual_wintouch'),
                'numero_documento_fiscal' => null,
                'emitido_em' => null,
            ];
        }

        $request = $this->currentFiscalRequest($invoice);
        $paymentStatus = (string) $invoice->estado_pagamento;

        return [
            'estado_pagamento' => $paymentStatus,
            'pagamento_confirmado' => $paymentStatus === 'pago',
            'valor_pago' => (float) $invoice->valor_pago,
            'valor_em_aberto' => (float) $invoice->valor_em_aberto,
            'estado_fiscal' => $this->fiscalState($invoice, $request),
            'pedido_fiscal_id' => $request?->id,
            'provider_fiscal' => $request?->provider,
            'modo_fiscal' => (string) config('fiscal.operation_mode', 'manual_wintouch'),
            'numero_documento_fiscal' => $request?->external_document_number,
            'emitido_em' => $request?->issued_at?->toIso8601String(),
        ];
    }

    private function currentFiscalRequest(Invoice $invoice): ?FiscalDocumentRequest
    {
        $requests = $invoice->fiscalDocumentRequests
            ->sortByDesc(fn (FiscalDocumentRequest $request): string => sprintf(
                '%s|%s',
                $request->updated_at?->format('Y-m-d H:i:s.u') ?? '',
                (string) $request->id,
            ));

        return $requests->first(fn (FiscalDocumentRequest $request): bool => in_array($request->status, [
            FiscalDocumentRequest::STATUS_PENDING,
            FiscalDocumentRequest::STATUS_IN_PROGRESS,
            FiscalDocumentRequest::STATUS_ISSUED,
        ], true)) ?? $requests->first();
    }

    private function fiscalState(Invoice $invoice, ?FiscalDocumentRequest $request): string
    {
        if (! $request) {
            if ($invoice->estado_pagamento === 'pago') {
                return 'pedido_em_falta';
            }

            return $invoice->estado_pagamento === 'cancelado' ? 'nao_aplicavel' : 'aguarda_pagamento';
        }

        return match ($request->status) {
            FiscalDocumentRequest::STATUS_PENDING => 'pendente_emissao',
            FiscalDocumentRequest::STATUS_IN_PROGRESS => 'em_emissao',
            FiscalDocumentRequest::STATUS_ISSUED => 'emitido',
            FiscalDocumentRequest::STATUS_ERROR_DATA => 'dados_em_falta',
            FiscalDocumentRequest::STATUS_API_ERROR => 'erro_provider',
            FiscalDocumentRequest::STATUS_CANCELLED => 'cancelado',
            FiscalDocumentRequest::STATUS_NOT_APPLICABLE => 'nao_aplicavel',
            default => (string) $request->status,
        };
    }
}
