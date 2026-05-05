<?php

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class FiscalDocumentRequestService
{
    public function createFromReconciliation($reconciliation, array $options = []): ?FiscalDocumentRequest
    {
        $invoiceId = data_get($reconciliation, 'fatura_id')
            ?? data_get($reconciliation, 'invoice_id');

        if (!$invoiceId) {
            return null;
        }

        $invoice = Invoice::query()->with(['user', 'items'])->find($invoiceId);

        if (!$invoice) {
            return null;
        }

        return $this->createFromInvoice($invoice, array_merge([
            'bank_statement_id' => data_get($reconciliation, 'extrato_id')
                ?? data_get($reconciliation, 'bank_statement_id'),
            'mapa_conciliacao_id' => data_get($reconciliation, 'id'),
            'financial_entry_id' => data_get($reconciliation, 'lancamento_id')
                ?? data_get($reconciliation, 'financial_entry_id'),
            'amount' => data_get($reconciliation, 'valor_conciliado'),
        ], $options));
    }

    public function createFromInvoice($invoice, array $options = []): FiscalDocumentRequest
    {
        $resolvedInvoice = $invoice instanceof Invoice
            ? $invoice->loadMissing(['user', 'items'])
            : Invoice::query()->with(['user', 'items'])->findOrFail($invoice);

        $provider = $options['provider'] ?? FiscalDocumentRequest::PROVIDER_WINTOUCH;
        $documentType = $options['document_type'] ?? FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT;

        $existingRequest = FiscalDocumentRequest::query()
            ->where('invoice_id', $resolvedInvoice->id)
            ->where('provider', $provider)
            ->where('document_type', $documentType)
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

        $payload = array_merge(
            $this->buildInvoicePayload($resolvedInvoice),
            Arr::except($options, ['provider', 'document_type'])
        );

        $payload['invoice_id'] = $resolvedInvoice->id;
        $payload['user_id'] = $payload['user_id'] ?? $resolvedInvoice->user_id;
        $payload['provider'] = $provider;
        $payload['document_type'] = $documentType;
        $payload['priority'] = $payload['priority'] ?? FiscalDocumentRequest::PRIORITY_NORMAL;

        [$status, $lastError] = $this->resolveInitialStatus($payload, $options['status'] ?? null);

        $payload['status'] = $status;
        $payload['last_error'] = $payload['last_error'] ?? $lastError;

        return FiscalDocumentRequest::create($payload);
    }

    public function markInProgress(FiscalDocumentRequest $request, ?string $userId = null): FiscalDocumentRequest
    {
        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_IN_PROGRESS,
            'handled_by' => $userId,
            'handled_at' => now(),
        ]);
        $request->save();

        return $request->refresh();
    }

    public function markIssued(FiscalDocumentRequest $request, array $data, ?string $userId = null): FiscalDocumentRequest
    {
        $issuedAt = !empty($data['issued_at']) ? Carbon::parse($data['issued_at']) : now();

        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'document_type' => $data['document_type'],
            'external_document_number' => $data['external_document_number'],
            'external_document_id' => $data['external_document_id'] ?? $request->external_document_id,
            'external_document_url' => $data['external_document_url'] ?? $request->external_document_url,
            'external_series' => $data['external_series'] ?? $request->external_series,
            'notes' => $data['notes'] ?? $request->notes,
            'issued_at' => $issuedAt,
            'issued_by' => $userId,
            'handled_by' => $userId,
            'handled_at' => now(),
            'last_error' => null,
        ]);
        $request->save();

        return $request->refresh();
    }

    public function markCancelled(FiscalDocumentRequest $request, ?string $reason = null, ?string $userId = null): FiscalDocumentRequest
    {
        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'handled_by' => $userId,
            'handled_at' => now(),
            'last_error' => $reason,
        ]);
        $request->save();

        return $request->refresh();
    }

    public function markErrorData(FiscalDocumentRequest $request, string $error, ?string $userId = null): FiscalDocumentRequest
    {
        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_ERROR_DATA,
            'handled_by' => $userId,
            'handled_at' => now(),
            'last_error' => $error,
        ]);
        $request->save();

        return $request->refresh();
    }

    private function buildInvoicePayload(Invoice $invoice): array
    {
        $user = $invoice->user;
        $description = trim((string) ($invoice->observacoes ?: $invoice->items
            ->pluck('descricao')
            ->filter()
            ->implode('; ')));

        $addressParts = array_filter([
            $user?->morada,
            trim((string) implode(' ', array_filter([$user?->codigo_postal, $user?->localidade]))),
        ]);

        return [
            'user_id' => $invoice->user_id,
            'amount' => $invoice->valor_total,
            'paid_at' => null,
            'due_at' => $invoice->data_vencimento,
            'customer_name' => $user?->nome_completo ?: $user?->name,
            'customer_tax_number' => $user?->nif,
            'customer_email' => $user?->email,
            'customer_address' => !empty($addressParts) ? implode("\n", $addressParts) : null,
            'description' => $description !== '' ? $description : 'Pedido de documento fiscal pendente.',
            'internal_reference' => $invoice->numero_recibo ?: $invoice->referencia_pagamento ?: $invoice->id,
            'cost_center_id' => $invoice->centro_custo_id,
            'metadata' => [
                'invoice_type' => $invoice->tipo,
                'invoice_payment_status' => $invoice->estado_pagamento,
                'invoice_issue_date' => optional($invoice->data_emissao)?->toDateString(),
            ],
        ];
    }

    private function resolveInitialStatus(array $payload, ?string $requestedStatus = null): array
    {
        if ($requestedStatus) {
            return [$requestedStatus, $payload['last_error'] ?? null];
        }

        $criticalMissing = [];
        $warnings = [];

        if (blank($payload['customer_name'] ?? null)) {
            $criticalMissing[] = 'Nome do cliente em falta.';
        }

        if (blank($payload['customer_tax_number'] ?? null)) {
            $criticalMissing[] = 'NIF do cliente em falta.';
        }

        if (blank($payload['customer_address'] ?? null)) {
            $warnings[] = 'Morada fiscal em falta.';
        }

        if (blank($payload['customer_email'] ?? null)) {
            $warnings[] = 'Email do cliente em falta.';
        }

        if ($criticalMissing !== []) {
            return [FiscalDocumentRequest::STATUS_ERROR_DATA, implode(' ', $criticalMissing)];
        }

        if ($warnings !== []) {
            return [FiscalDocumentRequest::STATUS_PENDING, implode(' ', $warnings)];
        }

        return [FiscalDocumentRequest::STATUS_PENDING, null];
    }
}