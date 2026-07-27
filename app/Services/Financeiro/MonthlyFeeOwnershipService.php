<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MonthlyFeeOwnershipService
{
    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $findings = [];
        $invoices = Invoice::query()
            ->where('tipo', 'mensalidade')
            ->with(['user:id,estado', 'paymentAllocations.payment:id,user_id', 'paymentAllocations.financialEntry:id,user_id,fatura_id'])
            ->orderBy('mes')
            ->orderBy('id')
            ->get();

        $duplicateIds = $invoices
            ->filter(fn (Invoice $invoice): bool => filled($invoice->user_id) && filled($invoice->mes) && $invoice->estado_pagamento !== 'cancelado')
            ->groupBy(fn (Invoice $invoice): string => $invoice->user_id.'|'.$invoice->mes)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->flatten()
            ->pluck('id')
            ->flip();

        foreach ($invoices as $invoice) {
            $owner = $invoice->user;
            if (blank($invoice->user_id)) {
                $findings[] = $this->finding('critical', 'monthly_invoice_without_owner', $invoice, true);
            } elseif ($owner === null) {
                $findings[] = $this->finding('critical', 'monthly_invoice_invalid_owner', $invoice, false);
            } elseif ($owner->estado !== null && $owner->estado !== 'ativo' && $invoice->estado_pagamento !== 'cancelado') {
                $findings[] = $this->finding('warning', 'monthly_invoice_inactive_owner', $invoice, false);
            }

            if ($duplicateIds->has($invoice->id)) {
                $findings[] = $this->finding('critical', 'monthly_invoice_duplicate', $invoice, false);
            }
            if (blank($invoice->centro_custo_id)) {
                $findings[] = $this->finding('warning', 'monthly_invoice_without_cost_center', $invoice, false);
            }
            if ($invoice->origem_tipo !== 'monthly_fee' || blank($invoice->origem_id)) {
                $findings[] = $this->finding('warning', 'monthly_invoice_without_monthly_fee_source', $invoice, false);
            }
        }

        $entries = FinancialEntry::query()
            ->where(function ($query): void {
                $query->where('origem_tipo', 'monthly_fee')
                    ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('tipo', 'mensalidade'));
            })
            ->with('invoice:id,user_id,tipo')
            ->get();

        foreach ($entries as $entry) {
            if (blank($entry->user_id)) {
                $findings[] = $this->entryFinding('critical', 'financial_entry_without_owner', $entry, true);
            }
            if ($entry->invoice && blank($entry->invoice->user_id)) {
                $findings[] = $this->entryFinding('critical', 'financial_entry_linked_to_ownerless_invoice', $entry, true);
            }
            if ($entry->invoice && filled($entry->user_id) && filled($entry->invoice->user_id) && $entry->user_id !== $entry->invoice->user_id) {
                $findings[] = $this->entryFinding('critical', 'financial_entry_owner_mismatch', $entry, true);
            }
            if ($entry->origem_tipo === 'monthly_fee' && blank($entry->origem_id)) {
                $findings[] = $this->entryFinding('warning', 'financial_entry_monthly_fee_without_source_id', $entry, false);
            }
        }

        $allocations = PaymentAllocation::query()
            ->whereHas('invoice', fn ($query) => $query->where('tipo', 'mensalidade'))
            ->with(['invoice:id,user_id,tipo', 'payment:id,user_id,family_id,bank_statement_id'])
            ->get();

        $ownerlessPaymentsReported = [];
        foreach ($allocations as $allocation) {
            $invoiceOwner = $allocation->invoice?->user_id;
            $paymentOwner = $allocation->payment?->user_id;
            if (blank($invoiceOwner)) {
                $findings[] = $this->allocationFinding('critical', 'payment_allocation_ownerless_invoice', $allocation, true);
            }
            if ($allocation->payment && blank($paymentOwner) && blank($allocation->payment->family_id) && ! isset($ownerlessPaymentsReported[$allocation->payment_id])) {
                $findings[] = $this->allocationFinding('warning', 'payment_without_owner', $allocation, true);
                $ownerlessPaymentsReported[$allocation->payment_id] = true;
            } elseif ($allocation->payment && blank($allocation->payment->family_id) && filled($paymentOwner) && filled($invoiceOwner) && $paymentOwner !== $invoiceOwner) {
                $findings[] = $this->allocationFinding('critical', 'payment_allocation_owner_mismatch', $allocation, false);
            }
        }

        $reconciliations = MapaConciliacao::query()
            ->where(function ($query): void {
                $query->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('tipo', 'mensalidade')->whereNull('user_id'))
                    ->orWhereHas('paymentAllocation.invoice', fn ($invoiceQuery) => $invoiceQuery->where('tipo', 'mensalidade')->whereNull('user_id'));
            })
            ->get();
        foreach ($reconciliations as $map) {
            $findings[] = [
                'severity' => 'critical',
                'code' => 'bank_reconciliation_linked_to_ownerless_invoice',
                'reconciliation_id' => $map->id,
                'invoice_id' => $map->fatura_id,
                'actionable' => true,
            ];
        }

        $summary = $this->summary($invoices, collect($findings));

        return [
            'generated_at' => now()->toIso8601String(),
            'source_of_truth' => 'invoices.user_id',
            'mode' => 'read-only',
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function repair(bool $apply): array
    {
        $changes = [];
        $skipped = [];

        DB::transaction(function () use ($apply, &$changes, &$skipped): void {
            Invoice::query()->where('tipo', 'mensalidade')->whereNull('user_id')->orderBy('id')->each(function (Invoice $invoice) use ($apply, &$changes, &$skipped): void {
                $candidates = $this->ownerCandidates($invoice);
                if ($candidates->count() !== 1) {
                    $skipped[] = ['invoice_id' => $invoice->id, 'reason' => $candidates->isEmpty() ? 'owner_not_determinable' : 'owner_ambiguous', 'candidate_user_ids' => $candidates->values()->all()];
                    return;
                }

                $ownerId = (string) $candidates->first();
                $invoiceChanges = [['table' => 'invoices', 'id' => $invoice->id, 'field' => 'user_id', 'from' => null, 'to' => $ownerId]];
                $entries = FinancialEntry::query()->where('fatura_id', $invoice->id)->whereNull('user_id')->get();
                foreach ($entries as $entry) {
                    $invoiceChanges[] = ['table' => 'financial_entries', 'id' => $entry->id, 'field' => 'user_id', 'from' => null, 'to' => $ownerId];
                }

                if ($apply) {
                    $invoice->forceFill(['user_id' => $ownerId])->save();
                    FinancialEntry::query()->where('fatura_id', $invoice->id)->whereNull('user_id')->update(['user_id' => $ownerId]);
                }
                array_push($changes, ...$invoiceChanges);
            });

            Payment::query()->whereNull('user_id')->whereNull('family_id')->with('allocations.invoice:id,user_id')->orderBy('id')->each(function (Payment $payment) use ($apply, &$changes, &$skipped): void {
                if ($payment->allocations->isEmpty() || $payment->allocations->contains(fn (PaymentAllocation $allocation): bool => $allocation->invoice === null)) {
                    $skipped[] = ['payment_id' => $payment->id, 'reason' => 'payment_without_invoice_allocation'];

                    return;
                }

                if ($payment->allocations->contains(fn (PaymentAllocation $allocation): bool => blank($allocation->invoice->user_id))) {
                    $skipped[] = ['payment_id' => $payment->id, 'reason' => 'allocated_invoice_without_owner'];

                    return;
                }

                $owners = $payment->allocations->pluck('invoice.user_id')->unique()->values();
                $validOwners = User::query()->whereKey($owners->all())->pluck('id');
                $invalidOwners = $owners->diff($validOwners)->values();
                if ($invalidOwners->isNotEmpty()) {
                    $skipped[] = ['payment_id' => $payment->id, 'reason' => 'invalid_invoice_owner', 'candidate_user_ids' => $owners->all()];

                    return;
                }

                if ($owners->count() !== 1) {
                    $skipped[] = ['payment_id' => $payment->id, 'reason' => 'ambiguous_payment_owner', 'candidate_user_ids' => $owners->all()];

                    return;
                }

                $ownerId = (string) $owners->first();
                $changes[] = ['table' => 'payments', 'id' => $payment->id, 'field' => 'user_id', 'from' => null, 'to' => $ownerId];
                if ($apply) {
                    $payment->forceFill(['user_id' => $ownerId])->save();
                }
            });

        });

        return [
            'generated_at' => now()->toIso8601String(),
            'mode' => $apply ? 'apply' : 'dry-run',
            'summary' => [
                'planned_change_count' => count($changes),
                'skipped_count' => count($skipped),
                'applied' => $apply,
            ],
            'changes' => $changes,
            'skipped' => $skipped,
        ];
    }

    private function ownerCandidates(Invoice $invoice): Collection
    {
        $entryOwners = FinancialEntry::query()->where('fatura_id', $invoice->id)->pluck('user_id');
        $paymentOwners = PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->pluck('payments.user_id');

        return $entryOwners->merge($paymentOwners)->filter()->unique()->values();
    }

    private function finding(string $severity, string $code, Invoice $invoice, bool $actionable): array
    {
        return ['severity' => $severity, 'code' => $code, 'invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'mes' => $invoice->mes, 'actionable' => $actionable];
    }

    private function entryFinding(string $severity, string $code, FinancialEntry $entry, bool $actionable): array
    {
        return ['severity' => $severity, 'code' => $code, 'financial_entry_id' => $entry->id, 'invoice_id' => $entry->fatura_id, 'user_id' => $entry->user_id, 'actionable' => $actionable];
    }

    private function allocationFinding(string $severity, string $code, PaymentAllocation $allocation, bool $actionable): array
    {
        return ['severity' => $severity, 'code' => $code, 'payment_allocation_id' => $allocation->id, 'payment_id' => $allocation->payment_id, 'invoice_id' => $allocation->invoice_id, 'actionable' => $actionable];
    }

    private function summary(Collection $invoices, Collection $findings): array
    {
        $count = fn (string $code): int => $findings->where('code', $code)->count();
        return [
            'total_monthly_invoices_scanned' => $invoices->count(),
            'monthly_invoices_without_owner_count' => $count('monthly_invoice_without_owner'),
            'monthly_invoices_invalid_owner_count' => $count('monthly_invoice_invalid_owner'),
            'monthly_invoices_duplicate_count' => $count('monthly_invoice_duplicate'),
            'financial_entries_without_owner_count' => $count('financial_entry_without_owner'),
            'financial_entries_owner_mismatch_count' => $count('financial_entry_owner_mismatch'),
            'payments_without_owner_count' => $count('payment_without_owner'),
            'payment_allocations_owner_mismatch_count' => $count('payment_allocation_owner_mismatch'),
            'bank_reconciliation_linked_to_ownerless_invoice_count' => $count('bank_reconciliation_linked_to_ownerless_invoice'),
            'critical_count' => $findings->where('severity', 'critical')->count(),
            'warning_count' => $findings->where('severity', 'warning')->count(),
            'info_count' => $findings->where('severity', 'info')->count(),
            'actionable_count' => $findings->where('actionable', true)->count(),
            'total_findings' => $findings->count(),
        ];
    }
}
