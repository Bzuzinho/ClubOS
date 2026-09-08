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

        if ($this->hasSourceKeyedEntryForPurchase($purchase)) {
            $reasons[] = 'legacy_source_keyed_financial_entry';
        }

        $originMovements = Movement::query()
            ->where('origem_tipo', 'supplier_purchase')
            ->where('origem_id', $purchase->id)
            ->get();
        $movement = $this->resolveMovement($purchase);

        if (!$movement) {
            if ($originMovements->isNotEmpty()) {
                $reasons[] = 'unlinked_supplier_purchase_movement_exists';
            }

            $reasons[] = 'movement_reference_missing_or_invalid';

            return array_values(array_unique($reasons));
        }

        if ((string) $movement->origem_tipo !== 'supplier_purchase'
            || (string) $movement->origem_id !== (string) $purchase->id) {
            $reasons[] = 'conflicting_supplier_purchase_movement';
        }

        if ($originMovements->count() > 1) {
            $reasons[] = 'multiple_supplier_purchase_movements_exist';
        }

        if ($originMovements->isNotEmpty()
            && !$originMovements->contains(fn (Movement $originMovement): bool => (string) $originMovement->id === (string) $movement->id)) {
            $reasons[] = 'conflicting_supplier_purchase_movement';
        }

        if (in_array((string) $movement->estado_pagamento, ['parcial', 'pago', 'pago_parcial'], true)) {
            $reasons[] = 'movement_payment_state_locked';
        }

        $canonicalEntries = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->get();

        $entryIds = $canonicalEntries->pluck('id')->filter()->values();

        if ($canonicalEntries->isNotEmpty()) {
            $reasons[] = 'movement_financial_entry_exists';
        }

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

    /**
     * @param list<string> $reasons
     */
    public function deletionBlockMessage(array $reasons): string
    {
        if (array_intersect($reasons, ['issued_fiscal_document_exists', 'issued_movement_document_exists', 'movement_receipt_number_present'])) {
            return 'Esta compra já possui documento fiscal/financeiro emitido. Deve ser anulada ou revertida, não apagada.';
        }

        if (array_intersect($reasons, ['movement_reconciled', 'reconciliation_map_exists', 'movement_reconciliation_map_exists'])) {
            return 'Esta compra já está conciliada com o banco. Deve ser desconciliada/revertida antes de qualquer correção.';
        }

        if (array_intersect($reasons, ['movement_payment_state_locked', 'movement_financial_entry_settled', 'confirmed_payment_allocation_exists', 'confirmed_payment_exists'])) {
            return 'Esta compra já possui liquidação total ou parcial. Deve ser revertida, não apagada diretamente.';
        }

        if (in_array('movement_financial_entry_exists', $reasons, true)) {
            return 'Esta compra já possui um lançamento financeiro canónico. A eliminação direta foi bloqueada para não deixar movimentos órfãos.';
        }

        if (array_intersect($reasons, [
            'legacy_parallel_financial_entry_reference',
            'legacy_source_keyed_financial_entry',
            'movement_reference_missing_or_invalid',
            'unlinked_supplier_purchase_movement_exists',
            'multiple_supplier_purchase_movements_exist',
            'conflicting_supplier_purchase_movement',
        ])) {
            return 'Esta compra tem uma inconsistência histórica nas ligações financeiras. Requer limpeza/correção controlada; não serão criadas ligações retroativas automaticamente.';
        }

        return 'Esta compra possui dependências financeiras e não pode ser apagada diretamente.';
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
