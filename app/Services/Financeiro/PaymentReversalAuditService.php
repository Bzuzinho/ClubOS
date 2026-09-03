<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class PaymentReversalAuditService
{
    private const VERSION = 'a4-5-payment-reversal-audit-v1';
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $reversedAllocations = $this->reversedAllocations($filters);
        $cancelledPayments = $this->cancelledPayments($filters, $reversedAllocations);
        $payments = $this->payments($cancelledPayments, $reversedAllocations);
        $allocations = $this->allocations($payments, $reversedAllocations);
        $invoices = $this->invoices($filters, $allocations);
        $financialEntries = $this->financialEntries($payments, $allocations, $invoices);
        $reconciliations = $this->reconciliations($payments, $allocations, $invoices, $financialEntries);
        $bankAllocations = $this->bankAllocations($payments, $allocations, $invoices);
        $fiscalRequests = $this->fiscalRequests($invoices, $financialEntries, $reconciliations);

        $findings = [];

        foreach ($cancelledPayments as $payment) {
            array_push($findings, ...$this->cancelledPaymentFindings(
                payment: $payment,
                allocations: $allocations,
                financialEntries: $financialEntries,
                reconciliations: $reconciliations,
                bankAllocations: $bankAllocations,
                includeClean: $filters['include_clean'],
            ));
        }

        foreach ($reversedAllocations as $allocation) {
            array_push($findings, ...$this->reversedAllocationFindings(
                allocation: $allocation,
                payments: $payments,
                invoices: $invoices,
                financialEntries: $financialEntries,
                reconciliations: $reconciliations,
                bankAllocations: $bankAllocations,
                fiscalRequests: $fiscalRequests,
                includeClean: $filters['include_clean'],
                includeDeleted: $filters['include_deleted'],
            ));
        }

        $findings = $this->deduplicate($findings);

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
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
            'allocation' => $this->normalizeNullableString($options['allocation'] ?? null),
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'include_clean' => (bool) ($options['include_clean'] ?? false),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,PaymentAllocation>
     */
    private function reversedAllocations(array $filters): Collection
    {
        $query = PaymentAllocation::withTrashed()
            ->where(function (Builder $query): void {
                $query->where('status', PaymentAllocation::STATUS_CANCELLED)
                    ->orWhereNotNull('deleted_at');
            })
            ->orderBy('allocated_at')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['allocation']) {
            $query->whereKey($filters['allocation']);
        }

        if ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        if ($filters['from']) {
            $from = Carbon::parse((string) $filters['from'])->toDateString();
            $query->where(function (Builder $query) use ($from): void {
                $query->whereDate('allocated_at', '>=', $from)
                    ->orWhereDate('created_at', '>=', $from);
            });
        }

        if ($filters['to']) {
            $to = Carbon::parse((string) $filters['to'])->toDateString();
            $query->where(function (Builder $query) use ($to): void {
                $query->whereDate('allocated_at', '<=', $to)
                    ->orWhereDate('created_at', '<=', $to);
            });
        }

        $allocations = $query->get();

        if ($filters['user']) {
            $userId = (string) $filters['user'];
            $payments = Payment::withTrashed()
                ->whereIn('id', $allocations->pluck('payment_id')->filter()->unique()->values()->all())
                ->get()
                ->keyBy('id');
            $invoices = Invoice::query()
                ->whereIn('id', $allocations->pluck('invoice_id')->filter()->unique()->values()->all())
                ->get()
                ->keyBy('id');

            $allocations = $allocations
                ->filter(static function (PaymentAllocation $allocation) use ($payments, $invoices, $userId): bool {
                    $payment = $payments->get($allocation->payment_id);
                    $invoice = $allocation->invoice_id ? $invoices->get($allocation->invoice_id) : null;

                    return (string) ($payment?->user_id ?? '') === $userId
                        || (string) ($invoice?->user_id ?? '') === $userId;
                })
                ->values();
        }

        return $allocations;
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,PaymentAllocation> $reversedAllocations
     * @return Collection<int,Payment>
     */
    private function cancelledPayments(array $filters, Collection $reversedAllocations): Collection
    {
        $query = Payment::withTrashed()
            ->where('status', Payment::STATUS_CANCELLED)
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['payment']) {
            $query->whereKey($filters['payment']);
        }

        if ($filters['allocation']) {
            $paymentIds = $reversedAllocations->pluck('payment_id')->filter()->unique()->values()->all();
            $query->whereIn('id', $paymentIds === [] ? ['__none__'] : $paymentIds);
        }

        if ($filters['invoice']) {
            $paymentIds = $reversedAllocations->pluck('payment_id')->filter()->unique()->values()->all();
            $query->whereIn('id', $paymentIds === [] ? ['__none__'] : $paymentIds);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['from']) {
            $from = Carbon::parse((string) $filters['from'])->toDateString();
            $query->where(function (Builder $query) use ($from): void {
                $query->whereDate('payment_date', '>=', $from)
                    ->orWhereDate('created_at', '>=', $from);
            });
        }

        if ($filters['to']) {
            $to = Carbon::parse((string) $filters['to'])->toDateString();
            $query->where(function (Builder $query) use ($to): void {
                $query->whereDate('payment_date', '<=', $to)
                    ->orWhereDate('created_at', '<=', $to);
            });
        }

        return $query->get();
    }

    /**
     * @param Collection<int,Payment> $cancelledPayments
     * @param Collection<int,PaymentAllocation> $reversedAllocations
     * @return Collection<int,Payment>
     */
    private function payments(Collection $cancelledPayments, Collection $reversedAllocations): Collection
    {
        $paymentIds = $cancelledPayments
            ->pluck('id')
            ->merge($reversedAllocations->pluck('payment_id'))
            ->filter()
            ->unique()
            ->values();

        if ($paymentIds->isEmpty()) {
            return collect();
        }

        return Payment::withTrashed()
            ->whereIn('id', $paymentIds->all())
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $reversedAllocations
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(Collection $payments, Collection $reversedAllocations): Collection
    {
        $paymentIds = $payments->pluck('id')->filter()->unique()->values();
        $allocationIds = $reversedAllocations->pluck('id')->filter()->unique()->values();

        if ($paymentIds->isEmpty() && $allocationIds->isEmpty()) {
            return collect();
        }

        return PaymentAllocation::withTrashed()
            ->where(function (Builder $query) use ($paymentIds, $allocationIds): void {
                if ($paymentIds->isNotEmpty()) {
                    $query->whereIn('payment_id', $paymentIds->all());
                }

                if ($allocationIds->isNotEmpty()) {
                    $query->orWhereIn('id', $allocationIds->all());
                }
            })
            ->orderBy('allocated_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
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
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(Collection $payments, Collection $allocations, Collection $invoices): Collection
    {
        $paymentIds = $payments->pluck('id')->filter()->unique()->values();
        $allocationIds = $allocations->pluck('id')->filter()->unique()->values();
        $entryIds = $allocations->pluck('financial_entry_id')->filter()->unique()->values();
        $invoiceIds = $invoices->pluck('id')->filter()->unique()->values();

        if ($paymentIds->isEmpty() && $allocationIds->isEmpty() && $entryIds->isEmpty() && $invoiceIds->isEmpty()) {
            return collect();
        }

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($paymentIds, $allocationIds, $entryIds, $invoiceIds): void {
                if ($paymentIds->isNotEmpty()) {
                    $query->whereIn('payment_id', $paymentIds->all());
                }

                if ($entryIds->isNotEmpty()) {
                    $query->orWhereIn('id', $entryIds->all());
                }

                if ($allocationIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $query) use ($allocationIds): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', $allocationIds->all());
                    });
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
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FinancialEntry> $financialEntries
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function fiscalRequests(Collection $invoices, Collection $financialEntries, Collection $reconciliations): Collection
    {
        $invoiceIds = $invoices->pluck('id')->filter()->unique()->values();
        $entryIds = $financialEntries->pluck('id')->filter()->unique()->values();
        $reconciliationIds = $reconciliations->pluck('id')->filter()->unique()->values();

        if ($invoiceIds->isEmpty() && $entryIds->isEmpty() && $reconciliationIds->isEmpty()) {
            return collect();
        }

        return FiscalDocumentRequest::withTrashed()
            ->where(function (Builder $query) use ($invoiceIds, $entryIds, $reconciliationIds): void {
                if ($invoiceIds->isNotEmpty()) {
                    $query->whereIn('invoice_id', $invoiceIds->all());
                }

                if ($entryIds->isNotEmpty()) {
                    $query->orWhereIn('financial_entry_id', $entryIds->all());
                }

                if ($reconciliationIds->isNotEmpty()) {
                    $query->orWhereIn('mapa_conciliacao_id', $reconciliationIds->all());
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FinancialEntry> $financialEntries
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return list<array<string,mixed>>
     */
    private function cancelledPaymentFindings(Payment $payment, Collection $allocations, Collection $financialEntries, Collection $reconciliations, Collection $bankAllocations, bool $includeClean): array
    {
        $paymentAllocations = $allocations->where('payment_id', $payment->id);
        $activeAllocations = $paymentAllocations
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at');
        $activeFinancialEntries = $this->activeFinancialEntriesForPayment($payment, $financialEntries);
        $activeReconciliations = $this->activeReconciliationsForPayment($payment, $reconciliations);
        $activeBankAllocations = $this->activeBankAllocationsForPayment($payment, $bankAllocations);

        $findings = [];

        if ($activeAllocations->isNotEmpty()) {
            $findings[] = $this->finding(
                'critical',
                'cancelled_payment_with_active_allocation',
                $payment,
                $activeAllocations->first(),
                null,
                $this->money($activeAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount)),
                (string) $payment->status,
                'review_cancelled_payment_with_active_allocation',
                ['active_allocation_count' => $activeAllocations->count()],
            );
        }

        if ($activeFinancialEntries->isNotEmpty()) {
            $findings[] = $this->finding(
                'warning',
                'cancelled_payment_with_financial_entry',
                $payment,
                null,
                null,
                $this->money($activeFinancialEntries->sum(fn (FinancialEntry $entry): float => (float) ($entry->valor_pago ?: $entry->valor))),
                (string) $payment->status,
                'review_financial_entry_for_cancelled_payment',
                ['financial_entry_ids' => $activeFinancialEntries->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all()],
            );
        }

        if ($activeReconciliations->isNotEmpty() || $activeBankAllocations->isNotEmpty()) {
            $findings[] = $this->finding(
                'warning',
                'cancelled_payment_with_bank_reconciliation',
                $payment,
                null,
                null,
                $this->money($activeReconciliations->sum(fn (MapaConciliacao $map): float => (float) $map->valor_conciliado)
                    + $activeBankAllocations->sum(fn (BankTransactionAllocation $allocation): float => (float) $allocation->valor_alocado)),
                (string) $payment->status,
                'review_bank_reconciliation_for_cancelled_payment',
                [
                    'mapa_conciliacao_ids' => $activeReconciliations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                    'bank_transaction_allocation_ids' => $activeBankAllocations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                ],
            );
        }

        if ($findings === [] && $includeClean) {
            $findings[] = $this->finding(
                'info',
                'clean_cancelled_payment',
                $payment,
                null,
                null,
                $this->money($payment->amount),
                (string) $payment->status,
                'no_action_needed_clean_cancelled_payment',
                ['reversed_allocation_count' => $paymentAllocations->where('status', PaymentAllocation::STATUS_CANCELLED)->count()],
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FinancialEntry> $financialEntries
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return list<array<string,mixed>>
     */
    private function reversedAllocationFindings(PaymentAllocation $allocation, Collection $payments, Collection $invoices, Collection $financialEntries, Collection $reconciliations, Collection $bankAllocations, Collection $fiscalRequests, bool $includeClean, bool $includeDeleted): array
    {
        $payment = $payments->firstWhere('id', $allocation->payment_id);
        $invoice = $allocation->invoice_id ? $invoices->firstWhere('id', $allocation->invoice_id) : null;
        $activeSiblingAllocations = $this->activeAllocationsForPaymentOrInvoice($allocation);
        $activePaymentAllocationSum = $this->activePaymentAllocationSum((string) $allocation->payment_id);
        $activeInvoiceAllocationSum = $allocation->invoice_id ? $this->activeInvoiceAllocationSum((string) $allocation->invoice_id) : 0.0;
        $activeFinancialEntries = $this->activeFinancialEntriesForAllocation($allocation, $financialEntries);
        $activeReconciliations = $this->activeReconciliationsForAllocation($allocation, $reconciliations);
        $activeBankAllocations = $this->activeBankAllocationsForAllocation($allocation, $bankAllocations);

        $findings = [];

        if ($invoice instanceof Invoice && abs($this->money($invoice->valor_pago) - $activeInvoiceAllocationSum) > self::TOLERANCE) {
            $code = $this->money($invoice->valor_pago) - $activeInvoiceAllocationSum >= $this->money($allocation->amount) - self::TOLERANCE
                ? 'cancelled_allocation_still_counted_on_invoice'
                : 'cancelled_allocation_still_counted_on_invoice';
            $findings[] = $this->finding(
                'critical',
                $code,
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice,
                $this->money($allocation->amount),
                (string) $allocation->status,
                'repair_invoice_paid_amount_after_allocation_reversal',
                ['active_invoice_allocation_sum' => $activeInvoiceAllocationSum, 'invoice_valor_pago' => $this->money($invoice->valor_pago)],
            );
        }

        if ($payment instanceof Payment && abs($this->money($payment->allocated_amount) - $activePaymentAllocationSum) > self::TOLERANCE) {
            $findings[] = $this->finding(
                $this->money($payment->allocated_amount) - $activePaymentAllocationSum >= $this->money($allocation->amount) - self::TOLERANCE ? 'critical' : 'warning',
                'cancelled_allocation_still_counted_on_payment',
                $payment,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                $this->money($allocation->amount),
                (string) $allocation->status,
                'repair_payment_allocated_amount_after_allocation_reversal',
                ['active_payment_allocation_sum' => $activePaymentAllocationSum, 'payment_allocated_amount' => $this->money($payment->allocated_amount)],
            );
        }

        if ($activeFinancialEntries->isNotEmpty()) {
            $findings[] = $this->finding(
                'warning',
                'cancelled_allocation_with_financial_entry',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                $this->money($activeFinancialEntries->sum(fn (FinancialEntry $entry): float => (float) ($entry->valor_pago ?: $entry->valor))),
                (string) $allocation->status,
                'review_financial_entry_for_cancelled_allocation',
                ['financial_entry_ids' => $activeFinancialEntries->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all()],
            );
        }

        if ($activeReconciliations->isNotEmpty() || $activeBankAllocations->isNotEmpty()) {
            $findings[] = $this->finding(
                'warning',
                'cancelled_payment_with_bank_reconciliation',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                $this->money($activeReconciliations->sum(fn (MapaConciliacao $map): float => (float) $map->valor_conciliado)
                    + $activeBankAllocations->sum(fn (BankTransactionAllocation $bankAllocation): float => (float) $bankAllocation->valor_alocado)),
                (string) $allocation->status,
                'review_bank_reconciliation_for_cancelled_payment',
                [
                    'mapa_conciliacao_ids' => $activeReconciliations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                    'bank_transaction_allocation_ids' => $activeBankAllocations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                ],
            );
        }

        if (
            $invoice instanceof Invoice
            && $this->hasFiscalDocument($invoice, $fiscalRequests)
            && ! $this->hasCompletedFiscalReversal($invoice, $fiscalRequests)
        ) {
            $findings[] = $this->finding(
                'critical',
                'reversed_invoice_with_fiscal_document',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice,
                $this->money($allocation->amount),
                (string) $allocation->status,
                'review_reversed_invoice_with_fiscal_document',
                ['invoice_numero_recibo' => $invoice->numero_recibo],
            );
        }

        $pendingFiscalRequests = $invoice instanceof Invoice ? $this->pendingFiscalRequests($invoice, $fiscalRequests) : collect();
        $archivedStaleFiscalRequests = $invoice instanceof Invoice ? $this->archivedStaleFiscalRequests($invoice, $fiscalRequests) : collect();

        if ($invoice instanceof Invoice && $pendingFiscalRequests->isNotEmpty()) {
            $pendingFiscalRequestIds = $pendingFiscalRequests->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
            $historicalPaidContext = $this->historicalReversalOnCurrentlyPaidInvoiceContext($invoice, $financialEntries, $reconciliations);

            if ((bool) $historicalPaidContext['is_currently_paid_and_protected']) {
                $findings[] = $this->finding(
                    'info',
                    'reversed_allocation_historical_with_active_paid_invoice',
                    $payment instanceof Payment ? $payment : null,
                    $allocation,
                    $invoice,
                    $this->money($allocation->amount),
                    (string) $allocation->status,
                    'no_action_needed_reversed_allocation_invoice_currently_paid',
                    array_merge($historicalPaidContext, ['fiscal_request_ids' => $pendingFiscalRequestIds]),
                );
            } else {
                $findings[] = $this->finding(
                    'warning',
                    'reversed_invoice_with_pending_fiscal_request',
                    $payment instanceof Payment ? $payment : null,
                    $allocation,
                    $invoice,
                    $this->money($allocation->amount),
                    (string) $allocation->status,
                    'review_pending_fiscal_request_after_payment_reversal',
                    array_merge($historicalPaidContext, ['fiscal_request_ids' => $pendingFiscalRequestIds]),
                );
            }
        }

        if ($invoice instanceof Invoice && $archivedStaleFiscalRequests->isNotEmpty()) {
            $findings[] = $this->finding(
                'info',
                'stale_fiscal_request_after_reversal_archived',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice,
                $this->money($allocation->amount),
                (string) $allocation->status,
                'no_action_needed_stale_fiscal_request_after_reversal_archived',
                ['fiscal_request_ids' => $archivedStaleFiscalRequests->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all()],
            );
        }

        if ($findings === [] && $includeClean) {
            $findings[] = $this->finding(
                'info',
                $allocation->deleted_at !== null && $includeDeleted ? 'soft_deleted_allocation_ignored' : 'clean_cancelled_allocation',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                $this->money($allocation->amount),
                (string) $allocation->status,
                $allocation->deleted_at !== null && $includeDeleted
                    ? 'no_action_needed_soft_deleted_cancelled_allocation_ignored_by_active_totals'
                    : 'no_action_needed_clean_cancelled_allocation',
                [
                    'active_sibling_allocation_count' => $activeSiblingAllocations->count(),
                    'active_invoice_allocation_sum' => $activeInvoiceAllocationSum,
                    'active_payment_allocation_sum' => $activePaymentAllocationSum,
                ],
            );
        }

        if ($findings === [] && $allocation->deleted_at !== null && ! $includeClean && $includeDeleted) {
            $findings[] = $this->finding(
                'info',
                'stale_reversal_trace_info',
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                $this->money($allocation->amount),
                (string) $allocation->status,
                'no_action_needed_historical_reversal_trace_without_active_impact',
            );
        }

        return $findings;
    }

    /**
     * @return Collection<int,PaymentAllocation>
     */
    private function activeAllocationsForPaymentOrInvoice(PaymentAllocation $allocation): Collection
    {
        return PaymentAllocation::query()
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($allocation): void {
                $query->where('payment_id', $allocation->payment_id);

                if ($allocation->invoice_id) {
                    $query->orWhere('invoice_id', $allocation->invoice_id);
                }
            })
            ->get();
    }

    private function activePaymentAllocationSum(string $paymentId): float
    {
        return $this->money(PaymentAllocation::query()
            ->where('payment_id', $paymentId)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->sum('amount'));
    }

    private function activeInvoiceAllocationSum(string $invoiceId): float
    {
        return $this->money(PaymentAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->sum('amount'));
    }

    /**
     * @param Collection<int,FinancialEntry> $financialEntries
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @return array<string,mixed>
     */
    private function historicalReversalOnCurrentlyPaidInvoiceContext(Invoice $invoice, Collection $financialEntries, Collection $reconciliations): array
    {
        $activeInvoiceAllocationSum = $this->activeInvoiceAllocationSum((string) $invoice->id);
        $activeConfirmedAllocationCount = PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->count();
        $activeInvoiceFinancialEntries = $this->activeFinancialEntriesForInvoice($invoice, $financialEntries);
        $activeInvoiceReconciliations = $this->activeReconciliationsForInvoice($invoice, $reconciliations);
        $invoiceTotal = $this->money($invoice->valor_total);
        $invoicePaid = $this->money($invoice->valor_pago);
        $invoiceOpen = $this->money($invoice->valor_em_aberto);
        $isPaidStateCoherent = (string) $invoice->estado_pagamento === 'pago'
            && abs($invoicePaid - $invoiceTotal) <= self::TOLERANCE
            && abs($invoiceOpen) <= self::TOLERANCE;
        $hasCoherentActiveAllocation = $activeConfirmedAllocationCount > 0
            && abs($activeInvoiceAllocationSum - $invoicePaid) <= self::TOLERANCE;
        $hasActiveProtectionTrail = $activeInvoiceFinancialEntries->isNotEmpty()
            || $activeInvoiceReconciliations->isNotEmpty();

        return [
            'is_currently_paid_and_protected' => $isPaidStateCoherent && $hasCoherentActiveAllocation && $hasActiveProtectionTrail,
            'is_paid_state_coherent' => $isPaidStateCoherent,
            'active_confirmed_allocation_count' => $activeConfirmedAllocationCount,
            'active_invoice_allocation_sum' => $activeInvoiceAllocationSum,
            'invoice_valor_total' => $invoiceTotal,
            'invoice_valor_pago' => $invoicePaid,
            'invoice_valor_em_aberto' => $invoiceOpen,
            'active_financial_entry_count' => $activeInvoiceFinancialEntries->count(),
            'active_reconciliation_count' => $activeInvoiceReconciliations->count(),
        ];
    }

    /**
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return Collection<int,FinancialEntry>
     */
    private function activeFinancialEntriesForPayment(Payment $payment, Collection $financialEntries): Collection
    {
        return $financialEntries
            ->filter(static fn (FinancialEntry $entry): bool => (string) $entry->payment_id === (string) $payment->id)
            ->filter(fn (FinancialEntry $entry): bool => $this->isFinancialEntryActive($entry))
            ->values();
    }

    /**
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return Collection<int,FinancialEntry>
     */
    private function activeFinancialEntriesForAllocation(PaymentAllocation $allocation, Collection $financialEntries): Collection
    {
        return $financialEntries
            ->filter(static function (FinancialEntry $entry) use ($allocation): bool {
                return (string) $entry->id === (string) $allocation->financial_entry_id
                    || ((string) $entry->origem_tipo === 'payment_allocation' && (string) $entry->origem_id === (string) $allocation->id);
            })
            ->filter(fn (FinancialEntry $entry): bool => $this->isFinancialEntryActive($entry))
            ->values();
    }

    /**
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return Collection<int,FinancialEntry>
     */
    private function activeFinancialEntriesForInvoice(Invoice $invoice, Collection $financialEntries): Collection
    {
        return $financialEntries
            ->filter(static fn (FinancialEntry $entry): bool => (string) $entry->fatura_id === (string) $invoice->id)
            ->filter(fn (FinancialEntry $entry): bool => $this->isFinancialEntryActive($entry))
            ->values();
    }

    private function isFinancialEntryActive(FinancialEntry $entry): bool
    {
        if (in_array((string) $entry->estado, ['cancelado', 'cancelled', 'reversed', 'anulado'], true)) {
            return false;
        }

        return in_array((string) $entry->estado, ['pago', 'parcial'], true)
            || (float) $entry->valor_pago > self::TOLERANCE
            || filled($entry->payment_id);
    }

    /**
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @return Collection<int,MapaConciliacao>
     */
    private function activeReconciliationsForPayment(Payment $payment, Collection $reconciliations): Collection
    {
        return $reconciliations
            ->where('payment_id', $payment->id)
            ->filter(fn (MapaConciliacao $map): bool => $this->isReconciliationActive($map))
            ->values();
    }

    /**
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @return Collection<int,MapaConciliacao>
     */
    private function activeReconciliationsForAllocation(PaymentAllocation $allocation, Collection $reconciliations): Collection
    {
        return $reconciliations
            ->where('payment_allocation_id', $allocation->id)
            ->filter(fn (MapaConciliacao $map): bool => $this->isReconciliationActive($map))
            ->values();
    }

    /**
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @return Collection<int,MapaConciliacao>
     */
    private function activeReconciliationsForInvoice(Invoice $invoice, Collection $reconciliations): Collection
    {
        return $reconciliations
            ->where('fatura_id', $invoice->id)
            ->filter(fn (MapaConciliacao $map): bool => $this->isReconciliationActive($map))
            ->values();
    }

    private function isReconciliationActive(MapaConciliacao $map): bool
    {
        return ! in_array((string) $map->status, ['cancelled', 'cancelado', 'reversed', 'anulado'], true)
            && (float) $map->valor_conciliado > self::TOLERANCE;
    }

    /**
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return Collection<int,BankTransactionAllocation>
     */
    private function activeBankAllocationsForPayment(Payment $payment, Collection $bankAllocations): Collection
    {
        return $bankAllocations
            ->where('payment_id', $payment->id)
            ->filter(fn (BankTransactionAllocation $allocation): bool => $this->isBankAllocationActive($allocation))
            ->values();
    }

    /**
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return Collection<int,BankTransactionAllocation>
     */
    private function activeBankAllocationsForAllocation(PaymentAllocation $allocation, Collection $bankAllocations): Collection
    {
        return $bankAllocations
            ->where('payment_allocation_id', $allocation->id)
            ->filter(fn (BankTransactionAllocation $bankAllocation): bool => $this->isBankAllocationActive($bankAllocation))
            ->values();
    }

    private function isBankAllocationActive(BankTransactionAllocation $allocation): bool
    {
        return $allocation->status === BankTransactionAllocation::STATUS_CONFIRMED
            && (float) $allocation->valor_alocado > self::TOLERANCE;
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     */
    private function hasFiscalDocument(Invoice $invoice, Collection $fiscalRequests): bool
    {
        if (filled($invoice->numero_recibo)) {
            return true;
        }

        return $fiscalRequests
            ->where('invoice_id', $invoice->id)
            ->contains(static fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_ISSUED
                || filled($request->external_document_number)
                || filled($request->external_document_id));
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     */
    private function hasCompletedFiscalReversal(Invoice $invoice, Collection $fiscalRequests): bool
    {
        $invoiceRequests = $fiscalRequests->where('invoice_id', $invoice->id);
        $registeredOriginals = $invoiceRequests
            ->where('document_type', '!=', FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE)
            ->filter(static fn (FiscalDocumentRequest $request): bool => filled($request->external_document_number)
                || filled($request->external_document_id));

        if ($registeredOriginals->isEmpty() || $registeredOriginals->contains(
            static fn (FiscalDocumentRequest $request): bool => $request->status !== FiscalDocumentRequest::STATUS_CANCELLED,
        )) {
            return false;
        }

        return $invoiceRequests
            ->where('document_type', FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE)
            ->contains(static fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_ISSUED
                && (filled($request->external_document_number) || filled($request->external_document_id)));
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function pendingFiscalRequests(Invoice $invoice, Collection $fiscalRequests): Collection
    {
        return $fiscalRequests
            ->where('invoice_id', $invoice->id)
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->reject(fn (FiscalDocumentRequest $request): bool => $this->isArchivedStaleFiscalRequest($request))
            ->values();
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function archivedStaleFiscalRequests(Invoice $invoice, Collection $fiscalRequests): Collection
    {
        return $fiscalRequests
            ->where('invoice_id', $invoice->id)
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->filter(fn (FiscalDocumentRequest $request): bool => $this->isArchivedStaleFiscalRequest($request))
            ->values();
    }

    private function isArchivedStaleFiscalRequest(FiscalDocumentRequest $request): bool
    {
        return (bool) data_get($request->metadata, 'stale_cleanup') === true
            && in_array(data_get($request->metadata, 'stale_cleanup_version'), ['a3-6', 'a4-6'], true)
            && blank($request->external_document_number)
            && blank($request->external_document_id)
            && $request->issued_at === null;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function deduplicate(array $findings): array
    {
        return collect($findings)
            ->unique(static fn (array $finding): string => implode('|', [
                $finding['code'] ?? '',
                $finding['payment_id'] ?? '',
                $finding['allocation_id'] ?? '',
                $finding['invoice_id'] ?? '',
            ]))
            ->values()
            ->all();
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
        $cancelledPayments = $payments->where('status', Payment::STATUS_CANCELLED);
        $cancelledAllocations = $allocations->where('status', PaymentAllocation::STATUS_CANCELLED);
        $softDeletedAllocations = $allocations->filter(static fn (PaymentAllocation $allocation): bool => $allocation->deleted_at !== null);

        return [
            'total_payments_scanned' => $payments->count(),
            'total_cancelled_payments' => $cancelledPayments->count(),
            'total_allocations_scanned' => $allocations->count(),
            'total_cancelled_allocations' => $cancelledAllocations->count(),
            'total_soft_deleted_allocations' => $softDeletedAllocations->count(),
            'total_invoices_touched' => $invoices->count(),
            'clean_reversal_count' => collect($findings)->whereIn('code', ['clean_cancelled_payment', 'clean_cancelled_allocation', 'soft_deleted_allocation_ignored', 'stale_reversal_trace_info'])->count(),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'payments_with_findings' => collect($findings)->pluck('payment_id')->filter()->unique()->count(),
            'allocations_with_findings' => collect($findings)->pluck('allocation_id')->filter()->unique()->count(),
            'invoices_with_findings' => collect($findings)->pluck('invoice_id')->filter()->unique()->count(),
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
            'deleted_at' => $allocation?->deleted_at?->toIso8601String() ?? $payment?->deleted_at?->toIso8601String(),
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
