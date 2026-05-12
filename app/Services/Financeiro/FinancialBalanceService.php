<?php

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\PaymentAllocation;

class FinancialBalanceService
{
    public function recalculateFinancialEntry(FinancialEntry $financialEntry): FinancialEntry
    {
        $financialEntry = $financialEntry->fresh(['paymentAllocations.payment']);

        $paidAmount = round((float) PaymentAllocation::query()
            ->confirmed()
            ->where('financial_entry_id', $financialEntry->id)
            ->sum('amount'), 2);
        $totalAmount = round(abs((float) $financialEntry->valor), 2);
        $outstandingAmount = round(max($totalAmount - $paidAmount, 0), 2);
        $latestAllocation = PaymentAllocation::query()
            ->confirmed()
            ->where('financial_entry_id', $financialEntry->id)
            ->with('payment')
            ->orderByDesc('allocated_at')
            ->orderByDesc('created_at')
            ->first();

        $status = $financialEntry->estado === 'cancelado'
            ? 'cancelado'
            : ($outstandingAmount <= 0.009 ? 'pago' : ($paidAmount > 0 ? 'parcial' : 'pendente'));

        $financialEntry->forceFill([
            'valor_pago' => $paidAmount,
            'valor_em_aberto' => $outstandingAmount,
            'estado' => $status,
            'data_pagamento' => $status === 'pago' ? ($latestAllocation?->payment?->payment_date ?? now()->toDateString()) : null,
            'metodo_pagamento' => $latestAllocation?->payment?->method,
            'payment_id' => $latestAllocation?->payment?->id,
            'bank_statement_id' => $latestAllocation?->payment?->bank_statement_id,
        ]);
        $financialEntry->save();

        return $financialEntry->refresh();
    }

    public function getFinancialEntryOutstandingAmount(FinancialEntry $financialEntry): float
    {
        $financialEntry = $financialEntry->fresh();
        $paidAmount = round((float) PaymentAllocation::query()
            ->confirmed()
            ->where('financial_entry_id', $financialEntry->id)
            ->sum('amount'), 2);

        return round(max(abs((float) $financialEntry->valor) - $paidAmount, 0), 2);
    }
}