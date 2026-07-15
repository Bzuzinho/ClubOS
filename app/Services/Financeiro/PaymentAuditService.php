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

final class PaymentAuditService
{
    private const VERSION = 'a4-1-payment-audit-v1';
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $payments = $this->payments($filters);
        $allocations = $this->allocations($filters, $payments);
        $invoices = $this->invoices($filters, $allocations);
        $financialEntries = $this->financialEntries($allocations);
        $bankStatements = $this->bankStatements($payments);
        $credits = $this->credits($payments);
        $reconciliations = $this->reconciliations($payments, $allocations, $invoices, $financialEntries);
        $bankAllocations = $this->bankAllocations($payments, $allocations, $invoices);

        $findings = [];

        foreach ($payments as $payment) {
            array_push(
                $findings,
                ...$this->paymentFindings($payment, $allocations, $credits, $bankStatements, $reconciliations, $bankAllocations),
            );
        }

        foreach ($allocations as $allocation) {
            array_push(
                $findings,
                ...$this->allocationFindings($allocation, $payments, $invoices, $financialEntries),
            );
        }

        foreach ($invoices as $invoice) {
            array_push($findings, ...$this->invoiceFindings($invoice, $allocations));
        }

        foreach ($financialEntries as $entry) {
            array_push($findings, ...$this->financialEntryFindings($entry, $allocations));
        }

        foreach ($bankAllocations as $bankAllocation) {
            array_push($findings, ...$this->bankAllocationFindings($bankAllocation, $allocations, $payments));
        }

        foreach ($reconciliations as $reconciliation) {
            array_push($findings, ...$this->reconciliationFindings($reconciliation, $allocations, $payments));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'detected_models' => $this->detectedModels(),
            'summary' => $this->summary($payments, $allocations, $invoices, $findings),
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
            'payment' => $this->normalizeNullableString($options['payment'] ?? null),
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
            'only_open' => (bool) ($options['only_open'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,Payment>
     */
    private function payments(array $filters): Collection
    {
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

        if ($filters['only_open']) {
            $query->where('unallocated_amount', '>', self::TOLERANCE);
        }

        if ($filters['invoice']) {
            $paymentIds = PaymentAllocation::withTrashed()
                ->where('invoice_id', $filters['invoice'])
                ->pluck('payment_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $query->whereIn('id', $paymentIds);
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
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(Collection $allocations): Collection
    {
        $entryIds = $allocations->pluck('financial_entry_id')->filter()->unique()->values();
        $allocationIds = $allocations->pluck('id')->filter()->unique()->values();
        $paymentIds = $allocations->pluck('payment_id')->filter()->unique()->values();
        $invoiceIds = $allocations->pluck('invoice_id')->filter()->unique()->values();

        if ($entryIds->isEmpty() && $allocationIds->isEmpty() && $paymentIds->isEmpty() && $invoiceIds->isEmpty()) {
            return collect();
        }

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($entryIds, $allocationIds, $paymentIds, $invoiceIds): void {
                if ($entryIds->isNotEmpty()) {
                    $query->whereIn('id', $entryIds->all());
                }

                if ($allocationIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $query) use ($allocationIds): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', $allocationIds->all());
                    });
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
     * @param Collection<int,Payment> $payments
     * @return Collection<string,BankStatement>
     */
    private function bankStatements(Collection $payments): Collection
    {
        $bankIds = $payments->pluck('bank_statement_id')->filter()->unique()->values();

        if ($bankIds->isEmpty()) {
            return collect();
        }

        return BankStatement::query()->whereIn('id', $bankIds->all())->get()->keyBy('id');
    }

    /**
     * @param Collection<int,Payment> $payments
     * @return Collection<int,AccountCredit>
     */
    private function credits(Collection $payments): Collection
    {
        if ($payments->isEmpty()) {
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
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return Collection<int,MapaConciliacao>
     */
    private function reconciliations(Collection $payments, Collection $allocations, Collection $invoices, Collection $financialEntries): Collection
    {
        $paymentIds = $payments->pluck('id')->filter()->unique()->values();
        $allocationIds = $allocations->pluck('id')->filter()->unique()->values();
        $invoiceIds = $invoices->pluck('id')->filter()->unique()->values();
        $entryIds = $financialEntries->pluck('id')->filter()->unique()->values();

        if ($paymentIds->isEmpty() && $allocationIds->isEmpty() && $invoiceIds->isEmpty() && $entryIds->isEmpty()) {
            return collect();
        }

        return MapaConciliacao::query()
            ->where(function (Builder $query) use ($paymentIds, $allocationIds, $invoiceIds, $entryIds): void {
                if ($paymentIds->isNotEmpty()) {
                    $query->whereIn('payment_id', $paymentIds->all());
                }

                if ($allocationIds->isNotEmpty()) {
                    $query->orWhereIn('payment_allocation_id', $allocationIds->all());
                }

                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('fatura_id', $invoiceIds->all());
                }

                if ($entryIds->isNotEmpty()) {
                    $query->orWhereIn('lancamento_id', $entryIds->all());
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @return Collection<int,BankTransactionAllocation>
     */
    private function bankAllocations(Collection $payments, Collection $allocations, Collection $invoices): Collection
    {
        $paymentIds = $payments->pluck('id')->filter()->unique()->values();
        $allocationIds = $allocations->pluck('id')->filter()->unique()->values();
        $invoiceIds = $invoices->pluck('id')->filter()->unique()->values();

        if ($paymentIds->isEmpty() && $allocationIds->isEmpty() && $invoiceIds->isEmpty()) {
            return collect();
        }

        return BankTransactionAllocation::query()
            ->where(function (Builder $query) use ($paymentIds, $allocationIds, $invoiceIds): void {
                if ($paymentIds->isNotEmpty()) {
                    $query->whereIn('payment_id', $paymentIds->all());
                }

                if ($allocationIds->isNotEmpty()) {
                    $query->orWhereIn('payment_allocation_id', $allocationIds->all());
                }

                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('invoice_id', $invoiceIds->all());
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,AccountCredit> $credits
     * @param Collection<string,BankStatement> $bankStatements
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return list<array<string,mixed>>
     */
    private function paymentFindings(Payment $payment, Collection $allocations, Collection $credits, Collection $bankStatements, Collection $reconciliations, Collection $bankAllocations): array
    {
        $findings = [];
        $paymentAllocations = $allocations->where('payment_id', $payment->id);
        $confirmedAllocations = $paymentAllocations
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at');
        $activeCredits = $credits
            ->where('payment_id', $payment->id)
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->whereNull('deleted_at');
        $confirmedSum = $this->money($confirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount));
        $creditSum = $this->money($activeCredits->sum(fn (AccountCredit $credit): float => (float) $credit->amount));
        $amount = $this->money($payment->amount);
        $allocatedAmount = $this->money($payment->allocated_amount);
        $unallocatedAmount = $this->money($payment->unallocated_amount);
        $expectedUnallocated = $this->money($amount - $confirmedSum - $creditSum);

        if ($amount < -self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'payment_negative_amount', $payment, null, null, $amount, $payment->status, 'review_payment_amount_before_any_balance_or_bank_action');
        }

        if ($allocatedAmount - $amount > self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'payment_allocated_exceeds_amount', $payment, null, null, $allocatedAmount, $payment->status, 'review_payment_allocated_amount_against_payment_total', [
                'payment_amount' => $amount,
                'allocated_amount' => $allocatedAmount,
            ]);
        }

        if (abs($allocatedAmount - $confirmedSum) > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'payment_allocated_amount_differs_from_confirmed_allocations', $payment, null, null, $allocatedAmount, $payment->status, 'recalculate_or_review_payment_confirmed_allocations', [
                'confirmed_allocation_sum' => $confirmedSum,
            ]);
        }

        if (abs($unallocatedAmount - $expectedUnallocated) > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'payment_unallocated_inconsistent', $payment, null, null, $unallocatedAmount, $payment->status, 'review_payment_unallocated_amount_against_allocations_and_credits', [
                'expected_unallocated_amount' => $expectedUnallocated,
                'confirmed_allocation_sum' => $confirmedSum,
                'active_credit_sum' => $creditSum,
            ]);
        }

        if ($payment->status === Payment::STATUS_CONFIRMED && $payment->payment_date === null) {
            $findings[] = $this->finding('warning', 'payment_confirmed_without_payment_date', $payment, null, null, $amount, $payment->status, 'review_missing_payment_date_for_confirmed_payment');
        }

        if ($payment->source === Payment::SOURCE_RECONCILIATION && blank($payment->bank_statement_id)) {
            $findings[] = $this->finding('warning', 'payment_reconciliation_without_bank_statement', $payment, null, null, $amount, $payment->status, 'review_reconciliation_payment_without_bank_statement');
        }

        if (filled($payment->bank_statement_id) && ! $bankStatements->has($payment->bank_statement_id)) {
            $findings[] = $this->finding('critical', 'payment_bank_statement_missing', $payment, null, null, $amount, $payment->status, 'review_payment_bank_statement_reference');
        }

        if (in_array($payment->source, [Payment::SOURCE_RECONCILIATION, Payment::SOURCE_BANK_STATEMENT], true)
            && $reconciliations->where('payment_id', $payment->id)->isEmpty()
            && $bankAllocations->where('payment_id', $payment->id)->isEmpty()) {
            $findings[] = $this->finding('warning', 'payment_reconciliation_without_bank_statement', $payment, null, null, $amount, $payment->status, 'review_bank_origin_payment_without_reconciliation_trace');
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return list<array<string,mixed>>
     */
    private function allocationFindings(PaymentAllocation $allocation, Collection $payments, Collection $invoices, Collection $financialEntries): array
    {
        $findings = [];
        $payment = $payments->firstWhere('id', $allocation->payment_id);
        $invoice = $allocation->invoice_id ? $invoices->firstWhere('id', $allocation->invoice_id) : null;
        $amount = $this->money($allocation->amount);

        if ($amount <= self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'payment_allocation_negative_or_zero', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_payment_allocation_amount');
        }

        if (blank($allocation->payment_id) || ! $payment instanceof Payment) {
            $findings[] = $this->finding('critical', 'payment_allocation_payment_missing', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_allocation_payment_reference');
        }

        if ($allocation->invoice_id !== null && ! $invoice instanceof Invoice) {
            $findings[] = $this->finding('critical', 'payment_allocation_invoice_missing', $payment, $allocation, null, $amount, $allocation->status, 'review_allocation_invoice_reference');
        }

        if ($allocation->status === PaymentAllocation::STATUS_CONFIRMED && $allocation->deleted_at !== null) {
            $findings[] = $this->finding('critical', 'payment_allocation_confirmed_soft_deleted', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_soft_deleted_confirmed_allocation');
        }

        if ($allocation->status === PaymentAllocation::STATUS_CANCELLED && $allocation->deleted_at === null) {
            $findings[] = $this->finding('warning', 'payment_allocation_cancelled_still_active', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_active_cancelled_allocation_trace');
        }

        if ($allocation->deleted_at !== null && $allocation->status !== PaymentAllocation::STATUS_CONFIRMED) {
            $findings[] = $this->finding('info', 'soft_deleted_allocation_ignored', $payment, $allocation, $invoice, $amount, $allocation->status, 'no_action_needed_soft_deleted_cancelled_allocation_ignored_by_active_totals');
        }

        if ($allocation->status === PaymentAllocation::STATUS_CONFIRMED
            && $invoice instanceof Invoice
            && $invoice->estado_pagamento === 'cancelado'
            && $allocation->deleted_at === null) {
            $findings[] = $this->finding('critical', 'confirmed_allocation_to_cancelled_invoice', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_confirmed_payment_to_cancelled_invoice');
        }

        if ($allocation->financial_entry_id !== null && ! $financialEntries->contains('id', $allocation->financial_entry_id)) {
            $findings[] = $this->finding('critical', 'allocation_financial_entry_missing', $payment, $allocation, $invoice, $amount, $allocation->status, 'review_allocation_financial_entry_reference');
        }

        return $findings;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function invoiceFindings(Invoice $invoice, Collection $allocations): array
    {
        $findings = [];
        $confirmedAllocations = $allocations
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at');
        $confirmedSum = $this->money($confirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount));
        $valorTotal = $this->money($invoice->valor_total);
        $valorPago = $this->money($invoice->valor_pago);
        $valorAberto = $this->money($invoice->valor_em_aberto);
        $computedOpen = $this->money($valorTotal - $valorPago);

        if (abs($valorPago - $confirmedSum) > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'invoice_paid_amount_differs_from_confirmed_allocations', null, null, $invoice, $valorPago, $invoice->estado_pagamento, 'review_invoice_paid_amount_against_confirmed_allocations', [
                'confirmed_allocation_sum' => $confirmedSum,
            ]);
        }

        if ($valorPago - $valorTotal > self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'invoice_paid_amount_exceeds_total', null, null, $invoice, $valorPago, $invoice->estado_pagamento, 'review_invoice_paid_amount_before_reporting_or_fiscal_action');
        }

        if ($valorAberto < -self::TOLERANCE || abs($valorAberto - $computedOpen) > self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'invoice_open_amount_inconsistent', null, null, $invoice, $valorAberto, $invoice->estado_pagamento, 'review_invoice_open_amount_formula', [
                'computed_open_amount' => $computedOpen,
            ]);
        }

        if ($invoice->estado_pagamento === 'pago' && (abs($valorPago - $valorTotal) > self::TOLERANCE || $valorAberto > self::TOLERANCE)) {
            $findings[] = $this->finding('critical', 'invoice_open_amount_inconsistent', null, null, $invoice, $valorAberto, $invoice->estado_pagamento, 'review_paid_invoice_payment_state');
        }

        if ($invoice->estado_pagamento === 'parcial' && ($valorPago <= self::TOLERANCE || $valorPago - $valorTotal >= -self::TOLERANCE)) {
            $findings[] = $this->finding('warning', 'invoice_open_amount_inconsistent', null, null, $invoice, $valorAberto, $invoice->estado_pagamento, 'review_partial_invoice_payment_state');
        }

        if ($confirmedSum > self::TOLERANCE && $valorPago <= self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'invoice_paid_amount_differs_from_confirmed_allocations', null, null, $invoice, $valorPago, $invoice->estado_pagamento, 'review_invoice_with_confirmed_allocation_but_zero_paid_amount', [
                'confirmed_allocation_sum' => $confirmedSum,
            ]);
        }

        if ($valorPago > self::TOLERANCE && $confirmedSum <= self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'invoice_paid_without_confirmed_allocation', null, null, $invoice, $valorPago, $invoice->estado_pagamento, 'review_invoice_paid_amount_without_confirmed_payment_allocation');
        }

        return $findings;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function financialEntryFindings(FinancialEntry $entry, Collection $allocations): array
    {
        $findings = [];
        $entryAllocations = $allocations->where('financial_entry_id', $entry->id);

        if (in_array((string) $entry->estado, ['pago', 'parcial'], true)
            && $entryAllocations->where('status', PaymentAllocation::STATUS_CONFIRMED)->whereNull('deleted_at')->isEmpty()
            && blank($entry->payment_id)) {
            $findings[] = $this->finding('warning', 'financial_entry_references_missing_payment_context', null, null, null, $this->money($entry->valor_pago), (string) $entry->estado, 'review_paid_financial_entry_without_payment_allocation_context', [
                'financial_entry_id' => (string) $entry->id,
            ]);
        }

        foreach ($entryAllocations as $allocation) {
            if ($allocation->status === PaymentAllocation::STATUS_CONFIRMED
                && abs($this->money($entry->valor_pago ?: $entry->valor) - $this->money($allocation->amount)) > self::TOLERANCE
                && $entryAllocations->count() === 1) {
                $findings[] = $this->finding('warning', 'financial_entry_references_missing_payment_context', null, $allocation, null, $this->money($entry->valor), (string) $entry->estado, 'review_financial_entry_amount_against_allocation', [
                    'financial_entry_id' => (string) $entry->id,
                    'allocation_amount' => $this->money($allocation->amount),
                ]);
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @return list<array<string,mixed>>
     */
    private function bankAllocationFindings(BankTransactionAllocation $bankAllocation, Collection $allocations, Collection $payments): array
    {
        $findings = [];
        $allocation = $bankAllocation->payment_allocation_id
            ? $allocations->firstWhere('id', $bankAllocation->payment_allocation_id)
            : null;
        $payment = $bankAllocation->payment_id
            ? $payments->firstWhere('id', $bankAllocation->payment_id)
            : null;

        if (! $allocation instanceof PaymentAllocation) {
            $findings[] = $this->finding('warning', 'bank_allocation_without_payment_allocation', $payment, null, null, $this->money($bankAllocation->valor_alocado), (string) $bankAllocation->status, 'review_bank_allocation_missing_payment_allocation', [
                'bank_transaction_allocation_id' => (string) $bankAllocation->id,
            ]);
        }

        return $findings;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @return list<array<string,mixed>>
     */
    private function reconciliationFindings(MapaConciliacao $reconciliation, Collection $allocations, Collection $payments): array
    {
        $findings = [];
        $allocation = $reconciliation->payment_allocation_id
            ? $allocations->firstWhere('id', $reconciliation->payment_allocation_id)
            : null;
        $payment = $reconciliation->payment_id
            ? $payments->firstWhere('id', $reconciliation->payment_id)
            : null;

        if (! $allocation instanceof PaymentAllocation || $allocation->status !== PaymentAllocation::STATUS_CONFIRMED || $allocation->deleted_at !== null) {
            $findings[] = $this->finding('critical', 'reconciliation_without_confirmed_payment', $payment, $allocation, null, $this->money($reconciliation->valor_conciliado), (string) $reconciliation->status, 'review_reconciliation_without_confirmed_payment_allocation', [
                'mapa_conciliacao_id' => (string) $reconciliation->id,
            ]);
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    private function detectedModels(): array
    {
        $detected = [
            'Payment' => Schema::hasTable('payments'),
            'PaymentAllocation' => Schema::hasTable('payment_allocations'),
            'AccountCredit' => Schema::hasTable('account_credits') && class_exists(AccountCredit::class),
            'CreditNote' => class_exists('App\\Models\\CreditNote'),
            'Refund' => class_exists('App\\Models\\Refund'),
            'Reversal' => class_exists('App\\Models\\Reversal'),
            'PaymentReversal' => class_exists('App\\Models\\PaymentReversal'),
            'Wallet' => class_exists('App\\Models\\Wallet'),
            'CreditBalance' => class_exists('App\\Models\\CreditBalance'),
        ];

        return [
            'payments' => $detected['Payment'],
            'payment_allocations' => $detected['PaymentAllocation'],
            'credit_refund_reversal_models_detected' => collect($detected)
                ->filter()
                ->keys()
                ->filter(fn (string $name): bool => in_array($name, ['AccountCredit', 'CreditNote', 'Refund', 'Reversal', 'PaymentReversal', 'Wallet', 'CreditBalance'], true))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $payments, Collection $allocations, Collection $invoices, array $findings): array
    {
        $activeConfirmedAllocations = $allocations
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at');

        return [
            'total_payments_scanned' => $payments->count(),
            'total_allocations_scanned' => $allocations->count(),
            'total_invoices_touched' => $invoices->count(),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'payments_with_findings' => collect($findings)->pluck('payment_id')->filter()->unique()->count(),
            'invoices_with_findings' => collect($findings)->pluck('invoice_id')->filter()->unique()->count(),
            'total_payment_amount_scanned' => $this->money($payments->sum(fn (Payment $payment): float => (float) $payment->amount)),
            'total_confirmed_allocation_amount_scanned' => $this->money($activeConfirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount)),
            'total_unallocated_amount_scanned' => $this->money($payments->sum(fn (Payment $payment): float => (float) $payment->unallocated_amount)),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, ?Payment $payment, ?PaymentAllocation $allocation, ?Invoice $invoice, float $amount, string $status, string $recommendation, array $context = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'payment_id' => $payment?->id ? (string) $payment->id : ($allocation?->payment_id ? (string) $allocation->payment_id : null),
            'allocation_id' => $allocation?->id ? (string) $allocation->id : null,
            'invoice_id' => $invoice?->id ? (string) $invoice->id : ($allocation?->invoice_id ? (string) $allocation->invoice_id : null),
            'user_id' => $payment?->user_id ? (string) $payment->user_id : ($invoice?->user_id ? (string) $invoice->user_id : null),
            'amount' => $this->money($amount),
            'status' => $status,
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
