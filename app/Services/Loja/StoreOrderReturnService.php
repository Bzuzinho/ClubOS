<?php

declare(strict_types=1);

namespace App\Services\Loja;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaDevolucao;
use App\Models\User;
use App\Services\Financeiro\FiscalDocumentRequestService;
use App\Services\Financeiro\PaymentAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreOrderReturnService
{
    public function __construct(
        private readonly FiscalDocumentRequestService $fiscalRequests,
        private readonly PaymentAllocationService $paymentAllocations,
        private readonly CancelStoreOrderStockAction $stockAction,
    ) {
    }

    public function process(LojaEncomenda $order, User $actor, string $reason): LojaEncomendaDevolucao
    {
        return DB::transaction(function () use ($order, $actor, $reason): LojaEncomendaDevolucao {
            $order = LojaEncomenda::query()
                ->whereKey($order->id)
                ->with(['invoice.fiscalDocumentRequests', 'itens.article', 'devolucao'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->estado === LojaEncomenda::ESTADO_DEVOLVIDO) {
                return $order->devolucao()->firstOrFail();
            }

            if ($order->estado !== LojaEncomenda::ESTADO_ENTREGUE) {
                throw ValidationException::withMessages([
                    'devolucao' => 'Apenas uma encomenda entregue pode entrar no fluxo de devolução.',
                ]);
            }

            $invoice = $order->invoice;
            $this->assertCanonicalInvoice($order, $invoice);

            $return = $order->devolucao ?: LojaEncomendaDevolucao::query()->create([
                'loja_encomenda_id' => $order->id,
                'fatura_id' => $invoice->id,
                'estado' => LojaEncomendaDevolucao::ESTADO_SOLICITADA,
                'motivo' => $reason,
                'solicitada_por' => $actor->id,
                'solicitada_em' => now(),
            ]);

            $originalFiscalRequest = $this->registeredOriginalFiscalRequest($invoice);
            if (filled($invoice->numero_recibo) && ! $originalFiscalRequest) {
                throw ValidationException::withMessages([
                    'devolucao' => 'A fatura tem um recibo registado sem pedido fiscal correspondente. É necessária revisão antes da devolução.',
                ]);
            }

            if ($originalFiscalRequest) {
                $creditNote = $return->fiscalDocumentRequest;
                if (! $creditNote) {
                    $creditNote = $this->fiscalRequests->createCreditNoteForInvoice($invoice, $originalFiscalRequest, [
                        'created_by' => $actor->id,
                        'description' => 'Nota de crédito por devolução da encomenda '.$order->numero.'. Motivo: '.$return->motivo,
                        'metadata' => [
                            'store_order_id' => $order->id,
                            'store_order_return_id' => $return->id,
                            'return_reason' => $return->motivo,
                        ],
                    ]);
                    $return->forceFill([
                        'fiscal_document_request_id' => $creditNote->id,
                        'estado' => LojaEncomendaDevolucao::ESTADO_AGUARDA_NOTA_CREDITO,
                    ])->save();
                }

                if (
                    $creditNote->status !== FiscalDocumentRequest::STATUS_ISSUED
                    || ! $this->fiscalRequests->requestHasRegisteredDocument($creditNote)
                ) {
                    return $return->refresh();
                }

                if ($originalFiscalRequest->status !== FiscalDocumentRequest::STATUS_CANCELLED) {
                    $this->fiscalRequests->markCancelled(
                        $originalFiscalRequest,
                        'Documento revertido pela nota de crédito '.$creditNote->external_document_number.'.',
                        $actor->id,
                    );
                }
            }

            $this->fiscalRequests->cancelUnissuedForInvoice(
                $invoice,
                'Pedido fiscal cancelado pela devolução '.$return->id.'.',
                $actor->id,
            );

            $reversedAt = now();
            $this->paymentAllocations->reverseInvoicePaymentsPreservingHistory($invoice, [
                'source_type' => 'store_order_return',
                'source_id' => $return->id,
                'reason' => $return->motivo,
                'reference' => $order->numero,
                'reversed_by' => $actor->id,
                'reversed_at' => $reversedAt,
                'metadata' => [
                    'store_order_id' => $order->id,
                    'store_order_return_id' => $return->id,
                ],
            ]);
            $return->forceFill([
                'reversao_financeira_por' => $actor->id,
                'reversao_financeira_em' => $reversedAt,
            ])->save();

            $this->stockAction->execute($order, $actor, 'devolucao');
            $completedAt = now();
            $return->forceFill([
                'estado' => LojaEncomendaDevolucao::ESTADO_CONCLUIDA,
                'stock_reposto_por' => $actor->id,
                'stock_reposto_em' => $completedAt,
                'concluida_por' => $actor->id,
                'concluida_em' => $completedAt,
            ])->save();
            $order->forceFill([
                'estado' => LojaEncomenda::ESTADO_DEVOLVIDO,
                'updated_by' => $actor->id,
            ])->save();

            return $return->refresh(['fiscalDocumentRequest']);
        });
    }

    private function assertCanonicalInvoice(LojaEncomenda $order, ?Invoice $invoice): void
    {
        $expectedUserId = $order->target_user_id ?: $order->user_id;

        if (
            ! $invoice
            || $invoice->origem_tipo !== 'store_order'
            || (string) $invoice->origem_id !== (string) $order->id
            || (string) $invoice->user_id !== (string) $expectedUserId
            || abs((float) $invoice->valor_total - (float) $order->total) > 0.009
        ) {
            throw ValidationException::withMessages([
                'devolucao' => 'A encomenda não possui uma fatura canónica válida para executar a devolução.',
            ]);
        }
    }

    private function registeredOriginalFiscalRequest(Invoice $invoice): ?FiscalDocumentRequest
    {
        $requests = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('document_type', '!=', FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE)
            ->where(function ($query): void {
                $query->whereNotNull('external_document_number')->where('external_document_number', '!=', '')
                    ->orWhere(function ($externalId): void {
                        $externalId->whereNotNull('external_document_id')->where('external_document_id', '!=', '');
                    });
            })
            ->lockForUpdate()
            ->get();

        if ($requests->count() > 1) {
            throw ValidationException::withMessages([
                'devolucao' => 'Existem vários documentos fiscais emitidos para a encomenda e é necessária revisão manual.',
            ]);
        }

        return $requests->first();
    }
}
