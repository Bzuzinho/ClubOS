<?php

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\User;

class FiscalEmissionQueueService
{
    public function __construct(
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
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
        $request = $reusableRequest ?? new FiscalDocumentRequest();
        $request->fill([
            'invoice_id' => $options['invoice_id'] ?? null,
            'user_id' => $financialEntry->user_id,
            'bank_statement_id' => $options['bank_statement_id'] ?? $financialEntry->bank_statement_id,
            'mapa_conciliacao_id' => $options['mapa_conciliacao_id'] ?? null,
            'financial_entry_id' => $financialEntry->id,
            'provider' => $options['provider'] ?? FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => $options['document_type'] ?? FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => $this->resolveInitialStatus($financialEntry, $user),
            'priority' => $options['priority'] ?? FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => abs((float) $financialEntry->valor),
            'paid_at' => $options['paid_at'] ?? $financialEntry->data_pagamento,
            'due_at' => $options['due_at'] ?? $financialEntry->data,
            'customer_name' => $user?->nome_completo ?: $user?->name ?: $financialEntry->entidade_nome,
            'customer_tax_number' => $user?->nif,
            'customer_email' => $user?->email,
            'customer_address' => $this->resolveAddress($user),
            'description' => $financialEntry->descricao,
            'internal_reference' => $financialEntry->documento_ref ?: $financialEntry->id,
            'cost_center_id' => $financialEntry->centro_custo_id,
            'last_error' => $this->resolveLastError($financialEntry, $user),
            'notes' => $options['notes'] ?? null,
            'metadata' => array_merge((array) ($request->metadata ?? []), [
                'financial_entry_tipo' => $financialEntry->tipo,
                'financial_entry_estado' => $financialEntry->estado,
                'origem_modulo' => $financialEntry->origem_modulo,
                'origem_tipo' => $financialEntry->origem_tipo,
            ]),
            'created_by' => $options['created_by'] ?? null,
        ]);
        $request->save();

        $financialEntry->forceFill([
            'fiscal_document_request_id' => $request->id,
        ])->save();

        return $request->refresh();
    }

    private function resolveAddress(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $parts = array_filter([
            $user->morada,
            trim((string) implode(' ', array_filter([$user->codigo_postal, $user->localidade]))),
        ]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function resolveInitialStatus(FinancialEntry $financialEntry, ?User $user): string
    {
        if (!$user?->nif || !($user?->nome_completo ?: $user?->name ?: $financialEntry->entidade_nome)) {
            return FiscalDocumentRequest::STATUS_ERROR_DATA;
        }

        return FiscalDocumentRequest::STATUS_PENDING;
    }

    private function resolveLastError(FinancialEntry $financialEntry, ?User $user): ?string
    {
        $errors = [];

        if (!($user?->nome_completo ?: $user?->name ?: $financialEntry->entidade_nome)) {
            $errors[] = 'Nome do cliente em falta.';
        }

        if (!$user?->nif) {
            $errors[] = 'NIF do cliente em falta.';
        }

        return $errors === [] ? null : implode(' ', $errors);
    }
}