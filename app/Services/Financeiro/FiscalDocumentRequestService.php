<?php

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FiscalDocumentRequestService
{
    public const INVOICE_STATUS_CHANGE_BLOCK_MESSAGE = 'Esta fatura ja tem documento Wintouch registado. Para alterar o estado e necessario regularizar/anular o documento fiscal.';

    public const DELETE_WITH_DOCUMENT_MESSAGE = 'Nao e possivel apagar um pedido com documento Wintouch registado. Deve ser cancelado/anulado.';

    public const CANCEL_WITHOUT_DOCUMENT_MESSAGE = 'So e possivel cancelar/anular pedidos com documento Wintouch registado.';

    public function findActiveForInvoice($invoice, ?string $provider = null, ?string $documentType = null): ?FiscalDocumentRequest
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        $query = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoiceId)
            ->where('provider', $provider ?? FiscalDocumentRequest::PROVIDER_WINTOUCH)
            ->whereIn('status', [
                FiscalDocumentRequest::STATUS_PENDING,
                FiscalDocumentRequest::STATUS_IN_PROGRESS,
                FiscalDocumentRequest::STATUS_ISSUED,
            ]);

        if ($documentType !== null) {
            $query->where('document_type', $documentType);
        }

        return $query->latest('created_at')->first();
    }

    public function syncInvoicePaymentStatus(Invoice $invoice, ?string $previousStatus = null, array $options = []): ?FiscalDocumentRequest
    {
        $invoice = $invoice->loadMissing(['user', 'items']);

        if ($invoice->estado_pagamento === 'pago') {
            return $this->createFromInvoice($invoice, $options);
        }

        if ($previousStatus === 'pago') {
            $this->deletePendingForInvoice($invoice);
        }

        return null;
    }

    public function ensureInvoiceStatusCanChangeFromPaid(Invoice $invoice, ?string $nextStatus): void
    {
        if ($invoice->getOriginal('estado_pagamento') !== 'pago' || $nextStatus === 'pago') {
            return;
        }

        if ($this->invoiceHasRegisteredDocument($invoice)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => self::INVOICE_STATUS_CHANGE_BLOCK_MESSAGE,
            ]);
        }
    }

    public function invoiceHasRegisteredDocument($invoice): bool
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return FiscalDocumentRequest::query()
            ->where('invoice_id', $invoiceId)
            ->whereNotNull('external_document_number')
            ->where('external_document_number', '!=', '')
            ->exists();
    }

    public function deletePendingForInvoice($invoice): int
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return FiscalDocumentRequest::query()
            ->where('invoice_id', $invoiceId)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_document_number')
                    ->orWhere('external_document_number', '');
            })
            ->delete();
    }

    public function deleteRequest(FiscalDocumentRequest $request): void
    {
        if ($this->requestHasRegisteredDocument($request)) {
            throw ValidationException::withMessages([
                'request' => self::DELETE_WITH_DOCUMENT_MESSAGE,
            ]);
        }

        $request->delete();
    }

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

        $existingRequest = $this->findActiveForInvoice($resolvedInvoice, $provider, $documentType);

        if ($existingRequest) {
            return $existingRequest;
        }

        $reusableRequest = FiscalDocumentRequest::query()
            ->where('invoice_id', $resolvedInvoice->id)
            ->where('provider', $provider)
            ->where('document_type', $documentType)
            ->whereIn('status', [
                FiscalDocumentRequest::STATUS_ERROR_DATA,
                FiscalDocumentRequest::STATUS_API_ERROR,
            ])
            ->latest('created_at')
            ->first();

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

        if ($reusableRequest) {
            $reusableRequest->fill($payload);
            $reusableRequest->save();

            return $reusableRequest->refresh();
        }

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
            'document_type' => $data['document_type'] ?? $request->document_type,
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
        if (! $this->requestHasRegisteredDocument($request)) {
            throw ValidationException::withMessages([
                'request' => self::CANCEL_WITHOUT_DOCUMENT_MESSAGE,
            ]);
        }

        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'handled_by' => $userId,
            'handled_at' => now(),
            'last_error' => $reason,
        ]);
        $request->save();

        return $request->refresh();
    }

    public function requestHasRegisteredDocument(FiscalDocumentRequest $request): bool
    {
        return filled($request->external_document_number);
    }

    public function markErrorData(FiscalDocumentRequest $request, string $error, ?string $notes = null, ?string $userId = null): FiscalDocumentRequest
    {
        $request->fill([
            'status' => FiscalDocumentRequest::STATUS_ERROR_DATA,
            'handled_by' => $userId,
            'handled_at' => now(),
            'last_error' => $error,
            'notes' => $notes ?? $request->notes,
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
            'paid_at' => $invoice->data_pagamento,
            'due_at' => $invoice->data_vencimento,
            'customer_name' => $user?->nome_completo ?: $user?->name,
            'customer_tax_number' => $user?->nif,
            'customer_email' => $user?->email,
            'customer_address' => !empty($addressParts) ? implode("\n", $addressParts) : null,
            'description' => $description !== '' ? $description : 'Pedido de documento fiscal pendente.',
            'internal_reference' => $invoice->referencia_pagamento ?: $invoice->id,
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