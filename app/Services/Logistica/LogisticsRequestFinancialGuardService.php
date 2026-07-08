<?php

namespace App\Services\Logistica;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;

class LogisticsRequestFinancialGuardService
{
    /**
     * @return array<string,mixed>
     */
    public function financialState(LogisticsRequest $request): array
    {
        $request = $request->fresh();

        if (!$request || !$request->financial_invoice_id) {
            return [
                'has_invoice_reference' => false,
                'invoice_exists' => false,
                'invoice_id' => null,
                'confirmed_allocations_count' => 0,
                'confirmed_payments_count' => 0,
                'confirmed_reconciliations_count' => 0,
                'issued_fiscal_count' => 0,
                'external_document_count' => 0,
                'numero_recibo_present' => false,
                'recibo_emitido_em_present' => false,
                'receipt_import_link_present' => false,
                'receipt_pdf_present' => false,
                'invoice_payment_status' => null,
                'invoice_valor_pago' => 0.0,
            ];
        }

        $invoice = Invoice::query()->find($request->financial_invoice_id);
        if (!$invoice) {
            return [
                'has_invoice_reference' => true,
                'invoice_exists' => false,
                'invoice_id' => (string) $request->financial_invoice_id,
                'confirmed_allocations_count' => 0,
                'confirmed_payments_count' => 0,
                'confirmed_reconciliations_count' => 0,
                'issued_fiscal_count' => 0,
                'external_document_count' => 0,
                'numero_recibo_present' => false,
                'recibo_emitido_em_present' => false,
                'receipt_import_link_present' => false,
                'receipt_pdf_present' => false,
                'invoice_payment_status' => null,
                'invoice_valor_pago' => 0.0,
            ];
        }

        $confirmedAllocations = PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->whereNull('deleted_at')
            ->get();

        $confirmedAllocationIds = $confirmedAllocations->pluck('id')->filter()->unique()->values();
        $confirmedPaymentIds = $confirmedAllocations->pluck('payment_id')->filter()->unique()->values();

        $confirmedPaymentsCount = Payment::query()
            ->confirmed()
            ->whereIn('id', $confirmedPaymentIds)
            ->count();

        $confirmedReconciliationsCount = MapaConciliacao::query()
            ->where(function ($query) use ($invoice, $confirmedAllocationIds): void {
                $query->where('fatura_id', $invoice->id);

                if ($confirmedAllocationIds->isNotEmpty()) {
                    $query->orWhereIn('payment_allocation_id', $confirmedAllocationIds->all());
                }
            })
            ->where('status', 'confirmado')
            ->count();

        $issuedFiscalCount = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('deleted_at')
            ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
            ->count();

        $externalDocumentCount = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('deleted_at')
            ->whereNotNull('external_document_number')
            ->where('external_document_number', '!=', '')
            ->count();

        return [
            'has_invoice_reference' => true,
            'invoice_exists' => true,
            'invoice_id' => (string) $invoice->id,
            'confirmed_allocations_count' => (int) $confirmedAllocations->count(),
            'confirmed_payments_count' => (int) $confirmedPaymentsCount,
            'confirmed_reconciliations_count' => (int) $confirmedReconciliationsCount,
            'issued_fiscal_count' => (int) $issuedFiscalCount,
            'external_document_count' => (int) $externalDocumentCount,
            'numero_recibo_present' => filled($invoice->numero_recibo),
            'recibo_emitido_em_present' => $invoice->recibo_emitido_em !== null,
            'receipt_import_link_present' => $invoice->receipt_import_item_id !== null,
            'receipt_pdf_present' => filled($invoice->recibo_pdf_path),
            'invoice_payment_status' => (string) $invoice->estado_pagamento,
            'invoice_valor_pago' => (float) ($invoice->valor_pago ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    public function blockingReasons(LogisticsRequest $request): array
    {
        $state = $this->financialState($request);
        $reasons = [];

        if (!$state['has_invoice_reference']) {
            return $reasons;
        }

        if (!$state['invoice_exists']) {
            $reasons[] = 'invoice_reference_missing_or_invalid';

            return $reasons;
        }

        if (in_array((string) $state['invoice_payment_status'], ['parcial', 'pago'], true)) {
            $reasons[] = 'invoice_paid_or_partial_state';
        }

        if ((float) $state['invoice_valor_pago'] > 0.009) {
            $reasons[] = 'invoice_paid_amount_present';
        }

        if ((int) $state['confirmed_allocations_count'] > 0) {
            $reasons[] = 'confirmed_allocation_exists';
        }

        if ((int) $state['confirmed_payments_count'] > 0) {
            $reasons[] = 'confirmed_payment_exists';
        }

        if ((int) $state['confirmed_reconciliations_count'] > 0) {
            $reasons[] = 'confirmed_reconciliation_exists';
        }

        if ((int) $state['issued_fiscal_count'] > 0) {
            $reasons[] = 'issued_fiscal_document_exists';
        }

        if ((int) $state['external_document_count'] > 0) {
            $reasons[] = 'external_document_number_present';
        }

        if ($state['numero_recibo_present'] === true) {
            $reasons[] = 'invoice_receipt_number_present';
        }

        if ($state['recibo_emitido_em_present'] === true) {
            $reasons[] = 'invoice_receipt_issue_date_present';
        }

        if ($state['receipt_import_link_present'] === true) {
            $reasons[] = 'invoice_receipt_import_link_present';
        }

        if ($state['receipt_pdf_present'] === true) {
            $reasons[] = 'invoice_receipt_pdf_present';
        }

        return array_values(array_unique($reasons));
    }

    public function canMutate(LogisticsRequest $request): bool
    {
        return $this->blockingReasons($request) === [];
    }

    public function canDelete(LogisticsRequest $request): bool
    {
        return $this->blockingReasons($request) === [];
    }
}
