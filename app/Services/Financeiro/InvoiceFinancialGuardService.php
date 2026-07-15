<?php

namespace App\Services\Financeiro;

use App\Models\AccountCreditUsage;
use App\Models\BankTransactionAllocation;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\PaymentAllocation;

class InvoiceFinancialGuardService
{
    /**
     * @return list<string>
     */
    public function trailReasons(Invoice $invoice): array
    {
        $invoice = $invoice->fresh() ?? $invoice;
        $reasons = [];

        if (in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)) {
            $reasons[] = 'estado_pagamento';
        }

        if ((float) ($invoice->valor_pago ?? 0) > 0) {
            $reasons[] = 'valor_pago';
        }

        if (filled($invoice->numero_recibo)) {
            $reasons[] = 'numero_recibo';
        }

        if (filled($invoice->receipt_import_item_id)) {
            $reasons[] = 'receipt_import_item_id';
        }

        if (filled($invoice->recibo_pdf_path) || filled($invoice->recibo_emitido_em)) {
            $reasons[] = 'recibo_emitido';
        }

        if (PaymentAllocation::withTrashed()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'payment_allocation';
        }

        if ($invoice->payments()->exists()) {
            $reasons[] = 'payment';
        }

        if (AccountCreditUsage::withTrashed()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'account_credit_usage';
        }

        if (MapaConciliacao::query()->where('fatura_id', $invoice->id)->exists()) {
            $reasons[] = 'mapa_conciliacao';
        }

        if (BankTransactionAllocation::query()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'bank_transaction_allocation';
        }

        if (FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'fiscal_document_request';
        }

        return array_values(array_unique($reasons));
    }

    public function hasFinancialOrFiscalTrail(Invoice $invoice): bool
    {
        return $this->trailReasons($invoice) !== [];
    }
}
