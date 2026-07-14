<?php

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FiscalDocumentRequestGuardService
{
    public const GENERIC_UPDATE_BLOCK_MESSAGE = 'Pedidos fiscais com documento externo registado sao snapshots fiscais e so podem ser alterados por transicoes explicitas.';

    public const DOCUMENT_IDENTITY_BLOCK_MESSAGE = 'A identidade do documento fiscal so pode ser definida pelo fluxo explicito de emissao.';

    public const STATUS_TRANSITION_BLOCK_MESSAGE = 'O estado do pedido fiscal so pode ser alterado pelos endpoints explicitos de transicao.';

    /**
     * @return list<string>
     */
    public function structuralFields(): array
    {
        return [
            'invoice_id',
            'user_id',
            'bank_statement_id',
            'mapa_conciliacao_id',
            'financial_entry_id',
            'provider',
            'document_type',
            'amount',
            'paid_at',
            'due_at',
            'customer_name',
            'customer_tax_number',
            'customer_email',
            'customer_address',
            'description',
            'internal_reference',
            'cost_center_id',
            'metadata',
        ];
    }

    /**
     * @return list<string>
     */
    public function documentIdentityFields(): array
    {
        return [
            'external_document_number',
            'external_document_id',
            'external_document_url',
            'external_series',
            'issued_at',
        ];
    }

    public function hasRegisteredDocument(FiscalDocumentRequest $request): bool
    {
        return filled($request->external_document_number)
            || filled($request->external_document_id);
    }

    public function ensureDocumentIdentityNotMutatedViaGenericUpdate(array $validated): void
    {
        foreach ($this->documentIdentityFields() as $field) {
            if (array_key_exists($field, $validated)) {
                throw ValidationException::withMessages([
                    $field => self::DOCUMENT_IDENTITY_BLOCK_MESSAGE,
                ]);
            }
        }
    }

    public function ensureGenericUpdateAllowed(FiscalDocumentRequest $request, array $validated): void
    {
        $this->ensureDocumentIdentityNotMutatedViaGenericUpdate($validated);

        if (array_key_exists('status', $validated)) {
            throw ValidationException::withMessages([
                'status' => self::STATUS_TRANSITION_BLOCK_MESSAGE,
            ]);
        }

        $allowedFields = $this->hasRegisteredDocument($request)
            ? ['notes']
            : ['priority', 'notes'];

        foreach (array_keys($validated) as $field) {
            if (! in_array($field, $allowedFields, true)) {
                throw ValidationException::withMessages([
                    $field => self::GENERIC_UPDATE_BLOCK_MESSAGE,
                ]);
            }
        }
    }

    public function sanitizeGenericUpdatePayload(FiscalDocumentRequest $request, array $validated): array
    {
        $this->ensureGenericUpdateAllowed($request, $validated);

        return Arr::only($validated, $this->hasRegisteredDocument($request) ? ['notes'] : ['priority', 'notes']);
    }
}
