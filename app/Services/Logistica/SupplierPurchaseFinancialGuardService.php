<?php

namespace App\Services\Logistica;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SupplierPurchase;

class SupplierPurchaseFinancialGuardService
{
    /**
     * @return list<string>
     */
    public function blockingReasons(SupplierPurchase $purchase): array
    {
        $purchase = $purchase->fresh();
        if (!$purchase) {
            return ['purchase_missing'];
        }

        $reasons = [];

        if ($purchase->financial_entry_id) {
            $reasons[] = 'legacy_parallel_financial_entry_reference';
        }

        $movement = $this->resolveMovement($purchase);
        if (!$movement) {
            $reasons[] = 'movement_reference_missing_or_invalid';

            return array_values(array_unique($reasons));
        }

        if ($this->hasSourceKeyedEntryForPurchase($purchase)) {
            $reasons[] = 'legacy_source_keyed_financial_entry';
        }

        if (in_array((string) $movement->estado_pagamento, ['parcial', 'pago', 'pago_parcial'], true)) {
            $reasons[] = 'movement_payment_state_locked';
        }

        $canonicalEntries = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->get();

        $entryIds = $canonicalEntries->pluck('id')->filter()->values();

        if ($canonicalEntries->contains(fn (FinancialEntry $entry): bool => in_array((string) $entry->estado, ['parcial', 'pago'], true)
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

            $hasMapByEntry = MapaConciliacao::query()
                ->whereIn('lancamento_id', $entryIds)
                ->exists();

            if ($hasMapByEntry) {
                $reasons[] = 'reconciliation_map_exists';
            }
        }

        if ((string) $movement->estado_conciliacao === 'conciliado') {
            $reasons[] = 'movement_reconciled';
        }

        $hasMapByMovement = MapaConciliacao::query()
            ->where('movimento_id', $movement->id)
            ->exists();

        if ($hasMapByMovement) {
            $reasons[] = 'movement_reconciliation_map_exists';
        }

        if (filled($movement->numero_recibo)) {
            $reasons[] = 'movement_receipt_number_present';
        }

        if ($entryIds->isNotEmpty()) {
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
        }

        $hasIssuedMovementDocument = MovementDocument::query()
            ->where('movement_id', $movement->id)
            ->whereIn('document_type', ['receipt', 'invoice_receipt'])
            ->whereIn('status', ['issued', 'emitido'])
            ->exists();

        if ($hasIssuedMovementDocument) {
            $reasons[] = 'issued_movement_document_exists';
        }

        return array_values(array_unique($reasons));
    }

    public function canMutate(SupplierPurchase $purchase): bool
    {
        return $this->blockingReasons($purchase) === [];
    }

    public function canDelete(SupplierPurchase $purchase): bool
    {
        return $this->blockingReasons($purchase) === [];
    }

    private function resolveMovement(SupplierPurchase $purchase): ?Movement
    {
        if (!$purchase->financial_movement_id) {
            return null;
        }

        return Movement::query()->find($purchase->financial_movement_id);
    }

    private function hasSourceKeyedEntryForPurchase(SupplierPurchase $purchase): bool
    {
        return FinancialEntry::query()
            ->whereIn('origem_tipo', ['stock', 'supplier_purchase'])
            ->where('origem_id', $purchase->id)
            ->exists();
    }
}
