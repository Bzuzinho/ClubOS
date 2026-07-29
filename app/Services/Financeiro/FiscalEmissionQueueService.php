<?php

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Services\Members\MemberFiscalDataResolver;

class FiscalEmissionQueueService
{
    public function __construct(
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
        private readonly MemberFiscalDataResolver $memberFiscalDataResolver,
    ) {
    }

    public function queueInvoice(Invoice $invoice, array $options = []): ?FiscalDocumentRequest
    {
        if ($invoice->estado_pagamento !== 'pago') {
            return null;
        }

        return $this->fiscalDocumentRequestService->createFromInvoice($invoice, $options);
    }

    public function queueFinancialEntry(FinancialEntry $financialEntry, array $options = []): ?FiscalDocumentRequest
    {
        $financialEntry = $financialEntry->fresh(['usuario']);

        if ($financialEntry->tipo !== 'receita' || $financialEntry->estado !== 'pago') {
            return null;
        }

        $existingRequest = FiscalDocumentRequest::query()
            ->where('financial_entry_id', $financialEntry->id)
            ->whereIn('status', [
                FiscalDocumentRequest::STATUS_PENDING,
                FiscalDocumentRequest::STATUS_IN_PROGRESS,
                FiscalDocumentRequest::STATUS_ISSUED,
            ])
            ->latest('created_at')
            ->first();

        if ($existingRequest) {
            return $existingRequest;
        }

        $reusableRequest = FiscalDocumentRequest::query()
            ->where('financial_entry_id', $financialEntry->id)
            ->whereIn('status', [
                FiscalDocumentRequest::STATUS_ERROR_DATA,
                FiscalDocumentRequest::STATUS_API_ERROR,
            ])
            ->latest('created_at')
            ->first();

        $user = $financialEntry->usuario;
        $fiscalData = $user
            ? $this->memberFiscalDataResolver->resolve($user)
            : [
                'nome' => null,
                'nif' => null,
                'morada' => null,
                'codigo_postal' => null,
                'localidade' => null,
            ];
        $request = $reusableRequest ?? new FiscalDocumentRequest();
        $request->fill([
            'invoice_id' => $options['invoice_id'] ?? null,
            'user_id' => $financialEntry->user_id,
            'bank_statement_id' => $options['bank_statement_id'] ?? $financialEntry->bank_statement_id,
            'mapa_conciliacao_id' => $options['mapa_conciliacao_id'] ?? null,
            'financial_entry_id' => $financialEntry->id,
            'provider' => $options['provider'] ?? FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => $options['document_type'] ?? FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => $this->resolveInitialStatus($financialEntry, $fiscalData),
            'priority' => $options['priority'] ?? FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => abs((float) $financialEntry->valor),
            'paid_at' => $options['paid_at'] ?? $financialEntry->data_pagamento,
            'due_at' => $options['due_at'] ?? null,
            'customer_name' => $fiscalData['nome'] ?: $financialEntry->entidade_nome,
            'customer_tax_number' => $fiscalData['nif'],
            'customer_email' => $user?->email,
            'customer_address' => $this->resolveAddress($fiscalData),
            'description' => $financialEntry->descricao,
            'internal_reference' => $financialEntry->documento_ref ?: $financialEntry->id,
            'cost_center_id' => $financialEntry->centro_custo_id,
            'last_error' => $this->resolveLastError($financialEntry, $fiscalData),
            'notes' => $options['notes'] ?? null,
            'metadata' => array_merge((array) ($request->metadata ?? []), [
                'financial_entry_tipo' => $financialEntry->tipo,
                'financial_entry_estado' => $financialEntry->estado,
                'origem_modulo' => $financialEntry->origem_modulo,
                'origem_tipo' => $financialEntry->origem_tipo,
                'internal_due_at_explicit' => array_key_exists('due_at', $options) && filled($options['due_at']),
            ]),
            'created_by' => $options['created_by'] ?? null,
        ]);
        $request->save();

        $financialEntry->forceFill([
            'fiscal_document_request_id' => $request->id,
        ])->save();

        return $request->refresh();
    }

    private function resolveAddress(array $fiscalData): ?string
    {
        $parts = array_filter([
            $fiscalData['morada'] ?? null,
            trim((string) implode(' ', array_filter([
                $fiscalData['codigo_postal'] ?? null,
                $fiscalData['localidade'] ?? null,
            ]))),
        ]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function resolveInitialStatus(FinancialEntry $financialEntry, array $fiscalData): string
    {
        if (!($fiscalData['nif'] ?? null) || !(($fiscalData['nome'] ?? null) ?: $financialEntry->entidade_nome)) {
            return FiscalDocumentRequest::STATUS_ERROR_DATA;
        }

        return FiscalDocumentRequest::STATUS_PENDING;
    }

    private function resolveLastError(FinancialEntry $financialEntry, array $fiscalData): ?string
    {
        $errors = [];

        if (!(($fiscalData['nome'] ?? null) ?: $financialEntry->entidade_nome)) {
            $errors[] = 'Nome do cliente em falta.';
        }

        if (!($fiscalData['nif'] ?? null)) {
            $errors[] = 'NIF do cliente em falta.';
        }

        return $errors === [] ? null : implode(' ', $errors);
    }
}
