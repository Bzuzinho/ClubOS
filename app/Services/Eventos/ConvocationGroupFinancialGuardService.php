<?php

namespace App\Services\Eventos;

use App\Models\ConvocationGroup;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;

class ConvocationGroupFinancialGuardService
{
    /**
     * @return list<string>
     */
    public function blockingReasons(ConvocationGroup $group): array
    {
        $group = $group->fresh();
        if (!$group || !$group->movimento_id) {
            return [];
        }

        $movement = Movement::query()->find($group->movimento_id);
        if (!$movement) {
            return ['movement_reference_missing_or_invalid'];
        }

        $reasons = [];

        if ((string) $movement->origem_tipo !== 'convocation_group' || (string) $movement->origem_id !== (string) $group->id) {
            $reasons[] = 'movement_non_canonical_origin';
        }

        if (in_array((string) $movement->estado_pagamento, ['parcial', 'pago', 'pago_parcial'], true)) {
            $reasons[] = 'movement_payment_state_locked';
        }

        $movementEntries = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->get();

        $entryIds = $movementEntries->pluck('id')->filter()->values();

        if ($movementEntries->contains(fn (FinancialEntry $entry): bool => in_array((string) $entry->estado, ['parcial', 'pago'], true)
            || (float) ($entry->valor_pago ?? 0) > 0.009)) {
            $reasons[] = 'movement_financial_entry_settled';
        }

        if ($entryIds->isNotEmpty()) {
            $confirmedAllocations = PaymentAllocation::query()
                ->confirmed()
                ->whereIn('financial_entry_id', $entryIds)
                ->whereNull('deleted_at')
                ->get();

            if ($confirmedAllocations->isNotEmpty()) {
                $reasons[] = 'confirmed_payment_allocation_exists';

                $confirmedPaymentExists = Payment::query()
                    ->confirmed()
                    ->whereIn('id', $confirmedAllocations->pluck('payment_id')->filter()->unique()->values())
                    ->exists();

                if ($confirmedPaymentExists) {
                    $reasons[] = 'confirmed_payment_exists';
                }
            }

            $hasIssuedFiscalDocument = FiscalDocumentRequest::query()
                ->whereIn('financial_entry_id', $entryIds)
                ->whereNull('deleted_at')
                ->where(function ($query): void {
                    $query
                        ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                        ->orWhere(function ($nested): void {
                            $nested->whereNotNull('external_document_number')
                                ->where('external_document_number', '!=', '');
                        });
                })
                ->exists();

            if ($hasIssuedFiscalDocument) {
                $reasons[] = 'issued_fiscal_document_exists';
            }

            $hasMapByEntry = MapaConciliacao::query()
                ->whereIn('lancamento_id', $entryIds)
                ->where('status', 'confirmado')
                ->exists();

            if ($hasMapByEntry) {
                $reasons[] = 'entry_reconciliation_map_confirmed';
            }
        }

        if ((string) $movement->estado_conciliacao === 'conciliado') {
            $reasons[] = 'movement_reconciled';
        }

        $hasMapByMovement = MapaConciliacao::query()
            ->where('movimento_id', $movement->id)
            ->where('status', 'confirmado')
            ->exists();

        if ($hasMapByMovement) {
            $reasons[] = 'movement_reconciliation_map_confirmed';
        }

        if (filled($movement->numero_recibo)) {
            $reasons[] = 'movement_receipt_number_present';
        }

        if (filled($movement->external_document_number ?? null)) {
            $reasons[] = 'movement_external_document_number_present';
        }

        $hasIssuedMovementDocument = MovementDocument::query()
            ->where('movement_id', $movement->id)
            ->whereIn('document_type', ['receipt', 'invoice_receipt', 'invoice'])
            ->whereIn('status', ['issued', 'emitido', 'validated', 'approved'])
            ->exists();

        if ($hasIssuedMovementDocument) {
            $reasons[] = 'issued_movement_document_exists';
        }

        return array_values(array_unique($reasons));
    }

    public function canMutate(ConvocationGroup $group): bool
    {
        return $this->blockingReasons($group) === [];
    }

    public function canDelete(ConvocationGroup $group): bool
    {
        return $this->blockingReasons($group) === [];
    }
}
