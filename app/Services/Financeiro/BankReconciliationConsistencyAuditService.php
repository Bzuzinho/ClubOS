<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class BankReconciliationConsistencyAuditService
{
    private const VERSION = 'a5-1-bank-reconciliation-audit-v1';
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $bankStatements = $this->bankStatements($filters);
        $payments = $this->payments($filters, $bankStatements);
        $allocations = $this->allocations($filters, $payments);
        $invoices = $this->invoices($filters, $allocations);
        $financialEntries = $this->financialEntries($payments, $allocations, $invoices);
        $reconciliations = $this->reconciliations($filters, $bankStatements, $payments, $allocations, $invoices, $financialEntries);
        $bankAllocations = $this->bankTransactionAllocations($filters, $bankStatements, $payments, $allocations, $invoices);
        $credits = $this->credits($payments);

        $findings = [];

        foreach ($bankStatements as $bankStatement) {
            array_push($findings, ...$this->bankStatementFindings($bankStatement, $payments, $reconciliations, $bankAllocations, $filters));
        }

        foreach ($payments as $payment) {
            array_push($findings, ...$this->paymentFindings($payment, $bankStatements, $reconciliations, $bankAllocations, $allocations, $credits));
        }

        foreach ($allocations as $allocation) {
            array_push($findings, ...$this->allocationFindings($allocation, $payments));
        }

        foreach ($invoices as $invoice) {
            array_push($findings, ...$this->invoiceFindings($invoice, $payments, $allocations, $reconciliations, $bankAllocations));
        }

        foreach ($reconciliations as $reconciliation) {
            array_push($findings, ...$this->reconciliationFindings($reconciliation, $bankStatements, $payments, $allocations));
        }

        foreach ($bankAllocations as $bankAllocation) {
            array_push($findings, ...$this->bankAllocationFindings($bankAllocation, $bankStatements, $payments, $allocations));
        }

        $findings = $this->uniqueFindings($findings);

        if (! $filters['include_clean']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => $finding['code'] !== 'clean_reconciled_payment'));
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $this->schemaDetected(),
            'summary' => $this->summary($bankStatements, $reconciliations, $bankAllocations, $payments, $allocations, $invoices, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'from' => $this->normalizeNullableString($options['from'] ?? null),
            'to' => $this->normalizeNullableString($options['to'] ?? null),
            'bank_transaction' => $this->normalizeNullableString($options['bank_transaction'] ?? null),
            'payment' => $this->normalizeNullableString($options['payment'] ?? null),
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'include_clean' => (bool) ($options['include_clean'] ?? false),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,BankStatement>
     */
    private function bankStatements(array $filters): Collection
    {
        if (! Schema::hasTable('bank_statements')) {
            return collect();
        }

        $query = BankStatement::query()
            ->orderBy('data_movimento')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['bank_transaction']) {
            $query->whereKey($filters['bank_transaction']);
        }

        if ($filters['from']) {
            $query->whereDate('data_movimento', '>=', Carbon::parse((string) $filters['from'])->toDateString());
        }

        if ($filters['to']) {
            $query->whereDate('data_movimento', '<=', Carbon::parse((string) $filters['to'])->toDateString());
        }

        if ($filters['payment']) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereHas('payments', fn (Builder $paymentQuery) => $paymentQuery->whereKey($filters['payment']))
                    ->orWhereHas('reconciliationMaps', fn (Builder $mapQuery) => $mapQuery->where('payment_id', $filters['payment']))
                    ->orWhereHas('bankTransactionAllocations', fn (Builder $allocationQuery) => $allocationQuery->where('payment_id', $filters['payment']));
            });
        }

        if ($filters['invoice']) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereHas('payments.allocations', fn (Builder $allocationQuery) => $allocationQuery->where('invoice_id', $filters['invoice']))
                    ->orWhereHas('reconciliationMaps', fn (Builder $mapQuery) => $mapQuery->where('fatura_id', $filters['invoice']))
                    ->orWhereHas('bankTransactionAllocations', fn (Builder $allocationQuery) => $allocationQuery->where('invoice_id', $filters['invoice']));
            });
        }

        if ($filters['user']) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereHas('payments', fn (Builder $paymentQuery) => $paymentQuery->where('user_id', $filters['user']))
                    ->orWhereHas('bankTransactionAllocations', fn (Builder $allocationQuery) => $allocationQuery->where('user_id', $filters['user']));
            });
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,BankStatement> $bankStatements
     * @return Collection<int,Payment>
     */
    private function payments(array $filters, Collection $bankStatements): Collection
    {
        if (! Schema::hasTable('payments')) {
            return collect();
        }

        $query = Payment::withTrashed()
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['payment']) {
            $query->whereKey($filters['payment']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['from']) {
            $query->whereDate('payment_date', '>=', Carbon::parse((string) $filters['from'])->toDateString());
        }

        if ($filters['to']) {
            $query->whereDate('payment_date', '<=', Carbon::parse((string) $filters['to'])->toDateString());
        }

        $bankIds = $bankStatements->pluck('id')->filter()->unique()->values();

        if ($filters['bank_transaction']) {
            $query->where('bank_statement_id', $filters['bank_transaction']);
        } elseif ($bankIds->isNotEmpty()) {
            $query->where(function (Builder $query) use ($bankIds): void {
                $query->whereIn('bank_statement_id', $bankIds->all())
                    ->orWhereHas('allocations', function (Builder $allocationQuery) use ($bankIds): void {
                        $allocationQuery->whereHas('financialEntry', fn (Builder $entryQuery) => $entryQuery->whereIn('bank_statement_id', $bankIds->all()));
                    });
            });
        }

        if ($filters['invoice']) {
            $query->whereHas('allocations', fn (Builder $allocationQuery) => $allocationQuery->withTrashed()->where('invoice_id', $filters['invoice']));
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,Payment> $payments
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(array $filters, Collection $payments): Collection
    {
        if (! Schema::hasTable('payment_allocations')) {
            return collect();
        }

        $query = PaymentAllocation::withTrashed()
            ->orderBy('allocated_at')
            ->orderBy('created_at')
            ->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        } elseif ($payments->isNotEmpty()) {
            $query->whereIn('payment_id', $payments->pluck('id')->all());
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,Invoice>
     */
    private function invoices(array $filters, Collection $allocations): Collection
    {
        if (! Schema::hasTable('invoices')) {
            return collect();
        }

        $invoiceIds = $allocations->pluck('invoice_id')->filter()->unique()->values();

        if ($filters['invoice']) {
            $invoiceIds->push($filters['invoice']);
        }

        if ($invoiceIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->whereIn('id', $invoiceIds->unique()->values()->all())
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(Collection $payments, Collection $allocations, Collection $invoices): Collection
    {
        if (! Schema::hasTable('financial_entries')) {
            return collect();
        }

        $entryIds = $allocations->pluck('financial_entry_id')->filter()->unique()->values();
        $paymentIds = $payments->pluck('id')->filter()->unique()->values();
        $invoiceIds = $invoices->pluck('id')->filter()->unique()->values();

        if ($entryIds->isEmpty() && $paymentIds->isEmpty() && $invoiceIds->isEmpty()) {
            return collect();
        }

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($entryIds, $paymentIds, $invoiceIds): void {
                if ($entryIds->isNotEmpty()) {
                    $query->whereIn('id', $entryIds->all());
                }

                if ($paymentIds->isNotEmpty()) {
                    $query->orWhereIn('payment_id', $paymentIds->all());
                }

                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('fatura_id', $invoiceIds->all());
                }
            })
            ->orderBy('data')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return Collection<int,MapaConciliacao>
     */
    private function reconciliations(array $filters, Collection $bankStatements, Collection $payments, Collection $allocations, Collection $invoices, Collection $financialEntries): Collection
    {
        if (! Schema::hasTable('mapa_conciliacao')) {
            return collect();
        }

        $query = MapaConciliacao::query()
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['bank_transaction']) {
            $query->where('extrato_id', $filters['bank_transaction']);
        } elseif ($bankStatements->isNotEmpty()) {
            $query->whereIn('extrato_id', $bankStatements->pluck('id')->all());
        }

        if ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        }

        if ($filters['invoice']) {
            $query->where('fatura_id', $filters['invoice']);
        }

        if (! $filters['bank_transaction'] && ! $filters['payment'] && ! $filters['invoice'] && $bankStatements->isEmpty()) {
            $query->where(function (Builder $query) use ($payments, $allocations, $invoices, $financialEntries): void {
                if ($payments->isNotEmpty()) {
                    $query->whereIn('payment_id', $payments->pluck('id')->all());
                }

                if ($allocations->isNotEmpty()) {
                    $query->orWhereIn('payment_allocation_id', $allocations->pluck('id')->all());
                }

                if ($invoices->isNotEmpty()) {
                    $query->orWhereIn('fatura_id', $invoices->pluck('id')->all());
                }

                if ($financialEntries->isNotEmpty()) {
                    $query->orWhereIn('lancamento_id', $financialEntries->pluck('id')->all());
                }
            });
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @return Collection<int,BankTransactionAllocation>
     */
    private function bankTransactionAllocations(array $filters, Collection $bankStatements, Collection $payments, Collection $allocations, Collection $invoices): Collection
    {
        if (! Schema::hasTable('bank_transaction_allocations')) {
            return collect();
        }

        $query = BankTransactionAllocation::query()
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['bank_transaction']) {
            $query->where('bank_statement_id', $filters['bank_transaction']);
        } elseif ($bankStatements->isNotEmpty()) {
            $query->whereIn('bank_statement_id', $bankStatements->pluck('id')->all());
        }

        if ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if (! $filters['bank_transaction'] && ! $filters['payment'] && ! $filters['invoice'] && $bankStatements->isEmpty()) {
            $query->where(function (Builder $query) use ($payments, $allocations, $invoices): void {
                if ($payments->isNotEmpty()) {
                    $query->whereIn('payment_id', $payments->pluck('id')->all());
                }

                if ($allocations->isNotEmpty()) {
                    $query->orWhereIn('payment_allocation_id', $allocations->pluck('id')->all());
                }

                if ($invoices->isNotEmpty()) {
                    $query->orWhereIn('invoice_id', $invoices->pluck('id')->all());
                }
            });
        }

        return $query->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @return Collection<int,AccountCredit>
     */
    private function credits(Collection $payments): Collection
    {
        if (! Schema::hasTable('account_credits') || $payments->isEmpty()) {
            return collect();
        }

        return AccountCredit::withTrashed()
            ->whereIn('payment_id', $payments->pluck('id')->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function bankStatementFindings(BankStatement $bankStatement, Collection $payments, Collection $reconciliations, Collection $bankAllocations, array $filters): array
    {
        $findings = [];
        $bankAmount = $this->bankAmount($bankStatement);
        $activePayments = $payments
            ->where('bank_statement_id', $bankStatement->id)
            ->filter(fn (Payment $payment): bool => $this->isActivePayment($payment))
            ->values();
        $activeReconciliations = $reconciliations
            ->where('extrato_id', $bankStatement->id)
            ->filter(fn (MapaConciliacao $map): bool => $this->isActiveReconciliationStatus($map->status))
            ->values();
        $activeBankAllocations = $bankAllocations
            ->where('bank_statement_id', $bankStatement->id)
            ->filter(fn (BankTransactionAllocation $allocation): bool => $this->isActiveReconciliationStatus($allocation->status))
            ->values();
        $activeTraceAmount = $this->reconciledAmount($activeReconciliations, $activeBankAllocations);

        if ($bankAmount > self::TOLERANCE
            && $activePayments->isEmpty()
            && $activeReconciliations->isEmpty()
            && $activeBankAllocations->isEmpty()
            && ! $this->bankStatementIgnoredOrCancelled($bankStatement)) {
            $findings[] = $this->finding(
                'warning',
                'bank_transaction_without_payment',
                $bankStatement,
                null,
                null,
                null,
                null,
                $bankAmount,
                true,
                'review_create_payment_or_mark_bank_transaction_ignored',
            );
        }

        if ($activePayments->count() > 1) {
            $findings[] = $this->finding(
                'critical',
                'bank_transaction_duplicate_payment',
                $bankStatement,
                null,
                $activePayments->first(),
                null,
                null,
                $bankAmount,
                true,
                'review_duplicate_payment_from_bank_transaction',
                [
                    'active_payment_ids' => $activePayments->pluck('id')->values()->all(),
                    'active_payment_count' => $activePayments->count(),
                ],
            );
        }

        if ($activeTraceAmount - $bankAmount > self::TOLERANCE) {
            $findings[] = $this->finding(
                'critical',
                'reconciliation_amount_exceeds_bank_transaction',
                $bankStatement,
                $activeReconciliations->first(),
                $activePayments->first(),
                null,
                null,
                $activeTraceAmount,
                true,
                'repair_reconciliation_amount_exceeds_bank_transaction',
                [
                    'bank_amount' => $bankAmount,
                    'reconciled_amount' => $activeTraceAmount,
                ],
            );
        }

        if ($filters['include_clean'] && $activePayments->count() === 1 && $activeTraceAmount > self::TOLERANCE) {
            $payment = $activePayments->first();
            if ($payment instanceof Payment && abs($this->money($payment->amount) - $activeTraceAmount) <= self::TOLERANCE && abs($this->money($payment->amount) - $bankAmount) <= self::TOLERANCE) {
                $findings[] = $this->finding(
                    'info',
                    'clean_reconciled_payment',
                    $bankStatement,
                    $activeReconciliations->first(),
                    $payment,
                    null,
                    null,
                    $bankAmount,
                    false,
                    'no_action_needed_clean_reconciled_payment',
                );
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,AccountCredit> $credits
     * @return list<array<string,mixed>>
     */
    private function paymentFindings(Payment $payment, Collection $bankStatements, Collection $reconciliations, Collection $bankAllocations, Collection $allocations, Collection $credits): array
    {
        $findings = [];
        $paymentAmount = $this->money($payment->amount);
        $bankStatement = $payment->bank_statement_id ? $bankStatements->firstWhere('id', $payment->bank_statement_id) : null;
        $paymentReconciliations = $reconciliations
            ->where('payment_id', $payment->id)
            ->filter(fn (MapaConciliacao $map): bool => $this->isActiveReconciliationStatus($map->status))
            ->values();
        $paymentBankAllocations = $bankAllocations
            ->where('payment_id', $payment->id)
            ->filter(fn (BankTransactionAllocation $allocation): bool => $this->isActiveReconciliationStatus($allocation->status))
            ->values();
        $reconciledAmount = $this->reconciledAmount($paymentReconciliations, $paymentBankAllocations);
        $confirmedAllocations = $allocations
            ->where('payment_id', $payment->id)
            ->filter(fn (PaymentAllocation $allocation): bool => $this->isConfirmedAllocation($allocation))
            ->values();
        $activeCredits = $credits
            ->where('payment_id', $payment->id)
            ->filter(fn (AccountCredit $credit): bool => $credit->deleted_at === null && (string) $credit->status !== AccountCredit::STATUS_CANCELLED)
            ->values();
        $requiresBankTrace = in_array((string) $payment->source, [Payment::SOURCE_RECONCILIATION, Payment::SOURCE_BANK_STATEMENT], true)
            || filled($payment->bank_statement_id);

        if ($requiresBankTrace && ! $bankStatement instanceof BankStatement && $paymentReconciliations->isEmpty() && $paymentBankAllocations->isEmpty()) {
            if ((string) $payment->status === Payment::STATUS_CANCELLED && $confirmedAllocations->isEmpty()) {
                $findings[] = $this->finding(
                    'info',
                    'historical_bank_trace_missing_but_payment_cancelled',
                    null,
                    null,
                    $payment,
                    null,
                    null,
                    $paymentAmount,
                    false,
                    'no_action_needed_cancelled_historical_payment',
                );
            } else {
                $findings[] = $this->finding(
                    'warning',
                    'payment_without_bank_trace',
                    null,
                    null,
                    $payment,
                    null,
                    null,
                    $paymentAmount,
                    true,
                    'review_payment_missing_bank_reconciliation_trace',
                );
            }
        }

        if ($bankStatement instanceof BankStatement && abs($paymentAmount - $this->bankAmount($bankStatement)) > self::TOLERANCE) {
            $findings[] = $this->finding(
                'critical',
                'payment_bank_amount_mismatch',
                $bankStatement,
                null,
                $payment,
                null,
                null,
                $paymentAmount,
                true,
                'repair_payment_bank_amount_mismatch',
                [
                    'bank_amount' => $this->bankAmount($bankStatement),
                    'payment_amount' => $paymentAmount,
                ],
            );
        }

        if ($reconciledAmount - $paymentAmount > self::TOLERANCE) {
            $findings[] = $this->finding(
                'critical',
                'reconciliation_amount_exceeds_payment',
                $bankStatement,
                $paymentReconciliations->first(),
                $payment,
                null,
                null,
                $reconciledAmount,
                true,
                'repair_reconciliation_amount_exceeds_payment',
                [
                    'payment_amount' => $paymentAmount,
                    'reconciled_amount' => $reconciledAmount,
                ],
            );
        }

        if ($this->money($confirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount)) - $paymentAmount > self::TOLERANCE) {
            $findings[] = $this->finding(
                'critical',
                'payment_allocation_exceeds_payment',
                $bankStatement,
                $paymentReconciliations->first(),
                $payment,
                $confirmedAllocations->first(),
                null,
                $this->money($confirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount)),
                true,
                'repair_payment_allocation_exceeds_payment',
            );
        }

        if ($reconciledAmount > self::TOLERANCE
            && $this->isActivePayment($payment)
            && $confirmedAllocations->isEmpty()
            && $activeCredits->isEmpty()
            && $this->money($payment->unallocated_amount) > self::TOLERANCE) {
            $findings[] = $this->finding(
                'warning',
                'reconciled_payment_without_invoice_allocation',
                $bankStatement,
                $paymentReconciliations->first(),
                $payment,
                null,
                null,
                $this->money($payment->unallocated_amount),
                true,
                'review_payment_allocation_or_credit',
            );
        }

        if ((string) $payment->status === Payment::STATUS_CANCELLED && $reconciledAmount > self::TOLERANCE) {
            $findings[] = $this->finding(
                'warning',
                'cancelled_payment_still_reconciled',
                $bankStatement,
                $paymentReconciliations->first(),
                $payment,
                null,
                null,
                $reconciledAmount,
                true,
                'review_cancelled_payment_still_reconciled',
            );
        }

        if ($this->hasDateSequenceIssue($payment, $bankStatement, $confirmedAllocations)) {
            $findings[] = $this->finding(
                'info',
                'date_sequence_inconsistent',
                $bankStatement,
                $paymentReconciliations->first(),
                $payment,
                $confirmedAllocations->first(),
                null,
                $paymentAmount,
                false,
                'review_bank_payment_allocation_date_sequence_if_operationally_relevant',
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @return list<array<string,mixed>>
     */
    private function allocationFindings(PaymentAllocation $allocation, Collection $payments): array
    {
        $payment = $payments->firstWhere('id', $allocation->payment_id);

        if (! $payment instanceof Payment || ! $this->isConfirmedAllocation($allocation)) {
            return [];
        }

        return [];
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return list<array<string,mixed>>
     */
    private function invoiceFindings(Invoice $invoice, Collection $payments, Collection $allocations, Collection $reconciliations, Collection $bankAllocations): array
    {
        $findings = [];
        $confirmedAllocations = $allocations
            ->where('invoice_id', $invoice->id)
            ->filter(fn (PaymentAllocation $allocation): bool => $this->isConfirmedAllocation($allocation))
            ->values();

        if ((string) $invoice->estado_pagamento === 'pago' && $confirmedAllocations->isNotEmpty()) {
            foreach ($confirmedAllocations as $allocation) {
                $payment = $payments->firstWhere('id', $allocation->payment_id);
                if (! $payment instanceof Payment || ! $this->paymentRequiresBankTrace($payment)) {
                    continue;
                }

                $hasTrace = filled($payment->bank_statement_id)
                    || $reconciliations->where('payment_id', $payment->id)->filter(fn (MapaConciliacao $map): bool => $this->isActiveReconciliationStatus($map->status))->isNotEmpty()
                    || $bankAllocations->where('payment_id', $payment->id)->filter(fn (BankTransactionAllocation $bankAllocation): bool => $this->isActiveReconciliationStatus($bankAllocation->status))->isNotEmpty();

                if (! $hasTrace) {
                    $findings[] = $this->finding(
                        'warning',
                        'paid_invoice_without_reconciled_payment',
                        null,
                        null,
                        $payment,
                        $allocation,
                        $invoice,
                        $this->money($allocation->amount),
                        true,
                        'review_paid_invoice_bank_trace_for_required_payment_method',
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function reconciliationFindings(MapaConciliacao $reconciliation, Collection $bankStatements, Collection $payments, Collection $allocations): array
    {
        if ($this->isActiveReconciliationStatus($reconciliation->status)) {
            return [];
        }

        $payment = $reconciliation->payment_id ? $payments->firstWhere('id', $reconciliation->payment_id) : null;
        $allocation = $reconciliation->payment_allocation_id ? $allocations->firstWhere('id', $reconciliation->payment_allocation_id) : null;

        if ($payment instanceof Payment && $this->isActivePayment($payment)) {
            return [
                $this->finding(
                    'critical',
                    'soft_deleted_reconciliation_still_affecting_payment',
                    $bankStatements->firstWhere('id', $reconciliation->extrato_id),
                    $reconciliation,
                    $payment,
                    $allocation instanceof PaymentAllocation ? $allocation : null,
                    null,
                    $this->money($reconciliation->valor_conciliado),
                    true,
                    'review_cancelled_reconciliation_still_affecting_payment',
                ),
            ];
        }

        return [];
    }

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function bankAllocationFindings(BankTransactionAllocation $bankAllocation, Collection $bankStatements, Collection $payments, Collection $allocations): array
    {
        if ($this->isActiveReconciliationStatus($bankAllocation->status)) {
            return [];
        }

        $payment = $bankAllocation->payment_id ? $payments->firstWhere('id', $bankAllocation->payment_id) : null;
        $allocation = $bankAllocation->payment_allocation_id ? $allocations->firstWhere('id', $bankAllocation->payment_allocation_id) : null;

        if ($payment instanceof Payment && $this->isActivePayment($payment)) {
            return [
                $this->finding(
                    'critical',
                    'soft_deleted_reconciliation_still_affecting_payment',
                    $bankStatements->firstWhere('id', $bankAllocation->bank_statement_id),
                    null,
                    $payment,
                    $allocation instanceof PaymentAllocation ? $allocation : null,
                    null,
                    $this->money($bankAllocation->valor_alocado),
                    true,
                    'review_cancelled_bank_allocation_still_affecting_payment',
                    [
                        'bank_transaction_allocation_id' => (string) $bankAllocation->id,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     */
    private function reconciledAmount(Collection $reconciliations, Collection $bankAllocations): float
    {
        $mapAmount = $this->money($reconciliations->sum(fn (MapaConciliacao $map): float => (float) $map->valor_conciliado));
        $bankAllocationAmount = $this->money($bankAllocations->sum(fn (BankTransactionAllocation $allocation): float => (float) $allocation->valor_alocado));

        return max($mapAmount, $bankAllocationAmount);
    }

    private function bankAmount(BankStatement $bankStatement): float
    {
        return $this->money(abs((float) $bankStatement->valor));
    }

    private function bankStatementIgnoredOrCancelled(BankStatement $bankStatement): bool
    {
        return in_array((string) $bankStatement->conciliacao_status, ['ignored', 'ignore', 'cancelled', 'canceled', 'cancelado', 'rejected'], true);
    }

    private function isActivePayment(Payment $payment): bool
    {
        return $payment->deleted_at === null && (string) $payment->status !== Payment::STATUS_CANCELLED;
    }

    private function isConfirmedAllocation(PaymentAllocation $allocation): bool
    {
        return $allocation->deleted_at === null && (string) $allocation->status === PaymentAllocation::STATUS_CONFIRMED;
    }

    private function paymentRequiresBankTrace(Payment $payment): bool
    {
        return filled($payment->bank_statement_id)
            || in_array((string) $payment->source, [Payment::SOURCE_RECONCILIATION, Payment::SOURCE_BANK_STATEMENT], true)
            || in_array((string) $payment->method, ['transferencia', 'bank_transfer', 'transfer', 'mbway'], true);
    }

    private function isActiveReconciliationStatus(mixed $status): bool
    {
        return ! in_array((string) $status, ['cancelled', 'canceled', 'cancelado', 'rejected', 'archived', 'deleted', 'void'], true);
    }

    /**
     * @param Collection<int,PaymentAllocation> $confirmedAllocations
     */
    private function hasDateSequenceIssue(Payment $payment, ?BankStatement $bankStatement, Collection $confirmedAllocations): bool
    {
        if ($bankStatement instanceof BankStatement && $payment->payment_date !== null) {
            $bankDate = Carbon::parse((string) $bankStatement->data_movimento)->startOfDay();
            $paymentDate = Carbon::parse((string) $payment->payment_date)->startOfDay();
            if (abs($paymentDate->diffInDays($bankDate)) > 30) {
                return true;
            }
        }

        foreach ($confirmedAllocations as $allocation) {
            if ($payment->payment_date !== null && $allocation->allocated_at !== null) {
                $allocatedAt = Carbon::parse((string) $allocation->allocated_at)->startOfDay();
                $paymentDate = Carbon::parse((string) $payment->payment_date)->startOfDay();
                if ($allocatedAt->lt($paymentDate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function uniqueFindings(array $findings): array
    {
        return collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                (string) ($finding['code'] ?? ''),
                (string) ($finding['bank_transaction_id'] ?? ''),
                (string) ($finding['reconciliation_id'] ?? ''),
                (string) ($finding['payment_id'] ?? ''),
                (string) ($finding['allocation_id'] ?? ''),
                (string) ($finding['invoice_id'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        return [
            'bank_transaction_tables' => array_values(array_filter([
                Schema::hasTable('bank_statements') ? 'bank_statements' : null,
                Schema::hasTable('bank_transaction_allocations') ? 'bank_transaction_allocations' : null,
            ])),
            'reconciliation_tables' => array_values(array_filter([
                Schema::hasTable('mapa_conciliacao') ? 'mapa_conciliacao' : null,
                Schema::hasTable('bank_transaction_allocations') ? 'bank_transaction_allocations' : null,
            ])),
            'payment_bank_columns' => [
                'payments.bank_statement_id' => Schema::hasColumn('payments', 'bank_statement_id'),
                'payments.source' => Schema::hasColumn('payments', 'source'),
                'payments.method' => Schema::hasColumn('payments', 'method'),
                'mapa_conciliacao.payment_id' => Schema::hasColumn('mapa_conciliacao', 'payment_id'),
                'mapa_conciliacao.payment_allocation_id' => Schema::hasColumn('mapa_conciliacao', 'payment_allocation_id'),
                'bank_transaction_allocations.payment_id' => Schema::hasColumn('bank_transaction_allocations', 'payment_id'),
                'bank_transaction_allocations.payment_allocation_id' => Schema::hasColumn('bank_transaction_allocations', 'payment_allocation_id'),
            ],
            'financial_entry_bank_origin_supported' => Schema::hasColumn('financial_entries', 'bank_statement_id')
                || (Schema::hasColumn('financial_entries', 'origem_tipo') && Schema::hasColumn('financial_entries', 'origem_id')),
        ];
    }

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $bankStatements, Collection $reconciliations, Collection $bankAllocations, Collection $payments, Collection $allocations, Collection $invoices, array $findings): array
    {
        $activeConfirmedAllocations = $allocations
            ->filter(fn (PaymentAllocation $allocation): bool => $this->isConfirmedAllocation($allocation));
        $activeReconciliations = $reconciliations
            ->filter(fn (MapaConciliacao $map): bool => $this->isActiveReconciliationStatus($map->status));
        $activeBankAllocations = $bankAllocations
            ->filter(fn (BankTransactionAllocation $allocation): bool => $this->isActiveReconciliationStatus($allocation->status));

        return [
            'total_bank_transactions_scanned' => $bankStatements->count(),
            'total_reconciliation_records_scanned' => $reconciliations->count() + $bankAllocations->count(),
            'total_payments_scanned' => $payments->count(),
            'total_allocations_scanned' => $allocations->count(),
            'total_invoices_touched' => $invoices->count(),
            'total_bank_amount_scanned' => $this->money($bankStatements->sum(fn (BankStatement $statement): float => abs((float) $statement->valor))),
            'total_payment_amount_scanned' => $this->money($payments->sum(fn (Payment $payment): float => (float) $payment->amount)),
            'total_reconciled_amount_scanned' => $this->money(max(
                $activeReconciliations->sum(fn (MapaConciliacao $map): float => (float) $map->valor_conciliado),
                $activeBankAllocations->sum(fn (BankTransactionAllocation $allocation): float => (float) $allocation->valor_alocado),
            )),
            'total_allocated_amount_scanned' => $this->money($activeConfirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount)),
            'clean_reconciled_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'clean_reconciled_payment')),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'actionable_count' => count(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))),
            'bank_transactions_with_findings' => collect($findings)->pluck('bank_transaction_id')->filter()->unique()->count(),
            'payments_with_findings' => collect($findings)->pluck('payment_id')->filter()->unique()->count(),
            'invoices_with_findings' => collect($findings)->pluck('invoice_id')->filter()->unique()->count(),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        ?BankStatement $bankStatement,
        ?MapaConciliacao $reconciliation,
        ?Payment $payment,
        ?PaymentAllocation $allocation,
        ?Invoice $invoice,
        float $amount,
        bool $actionable,
        string $recommendation,
        array $context = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'bank_transaction_id' => $bankStatement?->id ? (string) $bankStatement->id : null,
            'reconciliation_id' => $reconciliation?->id ? (string) $reconciliation->id : null,
            'payment_id' => $payment?->id ? (string) $payment->id : ($allocation?->payment_id ? (string) $allocation->payment_id : null),
            'allocation_id' => $allocation?->id ? (string) $allocation->id : null,
            'invoice_id' => $invoice?->id ? (string) $invoice->id : ($allocation?->invoice_id ? (string) $allocation->invoice_id : null),
            'user_id' => $payment?->user_id ? (string) $payment->user_id : ($invoice?->user_id ? (string) $invoice->user_id : null),
            'amount' => $this->money($amount),
            'bank_amount' => $bankStatement instanceof BankStatement ? $this->bankAmount($bankStatement) : null,
            'payment_amount' => $payment instanceof Payment ? $this->money($payment->amount) : null,
            'reconciled_amount' => $reconciliation instanceof MapaConciliacao ? $this->money($reconciliation->valor_conciliado) : null,
            'allocated_amount' => $allocation instanceof PaymentAllocation ? $this->money($allocation->amount) : null,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'context' => $context,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
