<?php

namespace App\Services\Patrocinios;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SponsorshipMoneyItem;

class SponsorshipFinancialGuardService
{
    /**
     * @return list<string>
     */
    public function blockingReasons(SponsorshipMoneyItem $moneyItem): array
    {
        $moneyItem = $moneyItem->fresh();
        if (!$moneyItem) {
            return [];
        }

        $movement = $this->resolveCanonicalMovement($moneyItem);
        if (!$movement) {
            return [];
        }

        $reasons = [];

        if ((string) $movement->origem_tipo !== 'sponsorship_money_item' || (string) $movement->origem_id !== (string) $moneyItem->id) {
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

            if (MapaConciliacao::query()
                ->whereIn('lancamento_id', $entryIds)
                ->where('status', 'confirmado')
                ->exists()) {
                $reasons[] = 'reconciliation_map_confirmed';
            }

            if (FiscalDocumentRequest::query()
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
                ->exists()) {
                $reasons[] = 'issued_fiscal_document_exists';
            }
        }

        if ((string) $movement->estado_conciliacao === 'conciliado') {
            $reasons[] = 'movement_reconciled';
        }

        if (MapaConciliacao::query()
            ->where('movimento_id', $movement->id)
            ->where('status', 'confirmado')
            ->exists()) {
            $reasons[] = 'movement_reconciliation_map_confirmed';
        }

        if (filled($movement->numero_recibo)) {
            $reasons[] = 'movement_receipt_number_present';
        }

        if (MovementDocument::query()
            ->where('movement_id', $movement->id)
            ->whereIn('document_type', ['invoice', 'receipt', 'invoice_receipt'])
            ->whereIn('status', ['issued', 'emitido', 'validated', 'approved'])
            ->exists()) {
            $reasons[] = 'issued_movement_document_exists';
        }

        return array_values(array_unique($reasons));
    }

    public function canMutate(SponsorshipMoneyItem $moneyItem): bool
    {
        return $this->blockingReasons($moneyItem) === [];
    }

    public function canDelete(SponsorshipMoneyItem $moneyItem): bool
    {
        return $this->blockingReasons($moneyItem) === [];
    }

    private function resolveCanonicalMovement(SponsorshipMoneyItem $moneyItem): ?Movement
    {
        if ($moneyItem->financial_movement_id) {
            $linkedMovement = Movement::query()->find($moneyItem->financial_movement_id);

            if ($linkedMovement && (string) $linkedMovement->origem_tipo === 'sponsorship_money_item' && (string) $linkedMovement->origem_id === (string) $moneyItem->id) {
                return $linkedMovement;
            }
        }

        return Movement::query()
            ->where('origem_tipo', 'sponsorship_money_item')
            ->where('origem_id', $moneyItem->id)
            ->orderByDesc('created_at')
            ->first();
    }
}