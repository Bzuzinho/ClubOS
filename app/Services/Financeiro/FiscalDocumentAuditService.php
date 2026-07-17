<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class FiscalDocumentAuditService
{
    private const VERSION = 'a6-1-fiscal-document-audit-v1';
    private const TOLERANCE = 0.01;
    private const STALE_DAYS = 30;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $requests = $this->fiscalRequests($filters);
        $invoices = $this->invoices($filters, $requests);
        $allocations = $this->allocations($filters, $requests, $invoices);
        $payments = $this->payments($filters, $allocations, $requests);
        $financialEntries = $this->financialEntries($requests);

        $findings = [];

        foreach ($requests as $request) {
            $invoice = $request->invoice_id ? $invoices->firstWhere('id', $request->invoice_id) : null;
            $requestAllocations = $this->requestAllocations($request, $invoice instanceof Invoice ? $invoice : null, $allocations);
            $requestPayments = $this->requestPayments($request, $requestAllocations, $payments);
            $entry = $request->financial_entry_id ? $financialEntries->firstWhere('id', $request->financial_entry_id) : null;

            array_push($findings, ...$this->requestFindings(
                $request,
                $invoice instanceof Invoice ? $invoice : null,
                $requestPayments,
                $requestAllocations,
                $entry instanceof FinancialEntry ? $entry : null,
            ));
        }

        array_push($findings, ...$this->duplicateFindings($requests, $invoices, $allocations));

        if ($filters['include_clean']) {
            foreach ($requests as $request) {
                if (! collect($findings)->contains(fn (array $finding): bool => ($finding['fiscal_request_id'] ?? null) === (string) $request->id)) {
                    $invoice = $request->invoice_id ? $invoices->firstWhere('id', $request->invoice_id) : null;
                    $requestAllocations = $this->requestAllocations($request, $invoice instanceof Invoice ? $invoice : null, $allocations);
                    $requestPayments = $this->requestPayments($request, $requestAllocations, $payments);
                    $findings[] = $this->finding(
                        'info',
                        'clean_fiscal_chain',
                        $request,
                        $invoice instanceof Invoice ? $invoice : null,
                        $requestPayments->first(),
                        $requestAllocations->first(),
                        $request->financial_entry_id ? $financialEntries->firstWhere('id', $request->financial_entry_id) : null,
                        false,
                        'no_action_needed_clean_fiscal_chain',
                        'clean',
                    );
                }
            }
        }

        $findings = $this->uniqueFindings($findings);

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $this->schemaDetected(),
            'summary' => $this->summary($requests, $invoices, $payments, $allocations, $findings),
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
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'payment' => $this->normalizeNullableString($options['payment'] ?? null),
            'allocation' => $this->normalizeNullableString($options['allocation'] ?? null),
            'fiscal_request' => $this->normalizeNullableString($options['fiscal_request'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'include_clean' => (bool) ($options['include_clean'] ?? false),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function fiscalRequests(array $filters): Collection
    {
        if (! Schema::hasTable('fiscal_document_requests')) {
            return collect();
        }

        $query = FiscalDocumentRequest::withTrashed()->orderBy('created_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['fiscal_request']) {
            $query->whereKey($filters['fiscal_request']);
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['from']) {
            $from = Carbon::parse((string) $filters['from'])->toDateString();
            $query->where(function (Builder $query) use ($from): void {
                $query->whereDate('created_at', '>=', $from)
                    ->orWhereDate('issued_at', '>=', $from)
                    ->orWhereDate('paid_at', '>=', $from)
                    ->orWhereDate('due_at', '>=', $from);
            });
        }

        if ($filters['to']) {
            $to = Carbon::parse((string) $filters['to'])->toDateString();
            $query->where(function (Builder $query) use ($to): void {
                $query->whereDate('created_at', '<=', $to)
                    ->orWhereDate('issued_at', '<=', $to)
                    ->orWhereDate('paid_at', '<=', $to)
                    ->orWhereDate('due_at', '<=', $to);
            });
        }

        if ($filters['payment'] || $filters['allocation']) {
            $invoiceIds = PaymentAllocation::withTrashed()
                ->when($filters['payment'], fn (Builder $query) => $query->where('payment_id', $filters['payment']))
                ->when($filters['allocation'], fn (Builder $query) => $query->whereKey($filters['allocation']))
                ->pluck('invoice_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($invoiceIds === []) {
                return collect();
            }

            $query->whereIn('invoice_id', $invoiceIds);
        }

        return $query->get();
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @return Collection<int,Invoice>
     */
    private function invoices(array $filters, Collection $requests): Collection
    {
        if (! Schema::hasTable('invoices')) {
            return collect();
        }

        $ids = $requests->pluck('invoice_id')->filter()->unique()->values();
        if ($filters['invoice']) {
            $ids->push($filters['invoice']);
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return Invoice::query()->whereIn('id', $ids->unique()->values()->all())->get();
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @param Collection<int,Invoice> $invoices
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(array $filters, Collection $requests, Collection $invoices): Collection
    {
        if (! Schema::hasTable('payment_allocations')) {
            return collect();
        }

        $query = PaymentAllocation::withTrashed()->orderBy('created_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['allocation']) {
            $query->whereKey($filters['allocation']);
        } elseif ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        } elseif ($invoices->isNotEmpty()) {
            $query->whereIn('invoice_id', $invoices->pluck('id')->all());
        } elseif ($requests->isNotEmpty()) {
            return collect();
        }

        return $query->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @return Collection<int,Payment>
     */
    private function payments(array $filters, Collection $allocations, Collection $requests): Collection
    {
        if (! Schema::hasTable('payments')) {
            return collect();
        }

        $query = Payment::withTrashed()->orderBy('payment_date')->orderBy('created_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['payment']) {
            $query->whereKey($filters['payment']);
        } elseif ($allocations->isNotEmpty()) {
            $query->whereIn('id', $allocations->pluck('payment_id')->filter()->unique()->values()->all());
        } elseif ($requests->isNotEmpty()) {
            return collect();
        }

        return $query->get();
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(Collection $requests): Collection
    {
        if (! Schema::hasTable('financial_entries')) {
            return collect();
        }

        $ids = $requests->pluck('financial_entry_id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return FinancialEntry::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,PaymentAllocation>
     */
    private function requestAllocations(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $allocations): Collection
    {
        if (! $invoice instanceof Invoice) {
            return collect();
        }

        return $allocations->where('invoice_id', $invoice->id)->values();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @return Collection<int,Payment>
     */
    private function requestPayments(FiscalDocumentRequest $request, Collection $allocations, Collection $payments): Collection
    {
        $ids = $allocations->pluck('payment_id')->filter()->unique()->values()->all();

        return $payments->whereIn('id', $ids)->values();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function requestFindings(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $payments, Collection $allocations, ?FinancialEntry $entry): array
    {
        $findings = [];
        $active = $this->activeRequest($request);
        $hasExternalDocument = $this->hasExternalDocument($request);
        $hasExternalReference = $this->hasExternalReference($request);
        $confirmedAllocations = $allocations->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation));
        $confirmedPayments = $payments->filter(fn (Payment $payment): bool => $this->activePayment($payment));

        if ($request->trashed() && ! $hasExternalDocument) {
            $findings[] = $this->finding('info', 'historical_fiscal_request_soft_deleted', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, false, 'no_action_needed_historical_soft_deleted_fiscal_request', 'soft_deleted_without_external_document');

            if ($this->isArchivedStaleFiscalRequest($request)) {
                $findings[] = $this->finding('info', 'stale_pending_fiscal_request', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, false, 'no_action_needed_stale_pending_fiscal_request_archived', 'archived_stale_pending_request');
            }

            return $findings;
        }

        if (! $invoice instanceof Invoice) {
            $findings[] = $this->finding('warning', 'fiscal_request_without_invoice', $request, null, null, null, $entry, true, 'review_fiscal_request_without_invoice', 'missing_invoice_reference');
        }

        if ($invoice instanceof Invoice && (string) $invoice->estado_pagamento === 'cancelado' && $active) {
            $findings[] = $this->finding($hasExternalDocument ? 'critical' : 'warning', 'fiscal_request_for_cancelled_invoice', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_fiscal_request_for_cancelled_invoice', 'active_request_for_cancelled_invoice');
        }

        if ($this->requiresPayment($request) && $active && ($confirmedAllocations->isEmpty() || $confirmedPayments->isEmpty())) {
            $findings[] = $this->finding('warning', 'fiscal_request_without_confirmed_payment', $request, $invoice, $payments->first(), $allocations->first(), $entry, true, 'review_fiscal_request_without_confirmed_payment', 'missing_confirmed_payment_or_allocation');
        }

        if ($invoice instanceof Invoice && $this->amountMismatch($request, $invoice, $confirmedPayments, $confirmedAllocations)) {
            $findings[] = $this->finding($hasExternalDocument ? 'critical' : 'warning', 'fiscal_request_amount_mismatch', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_fiscal_request_amount_mismatch', 'amount_differs_from_invoice_payment_or_allocation');
        }

        if ((string) $request->status === FiscalDocumentRequest::STATUS_ISSUED && ! $hasExternalReference) {
            $findings[] = $this->finding('warning', 'issued_document_without_external_reference', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_issued_document_without_external_reference', 'issued_status_without_external_reference');
        }

        if ($hasExternalReference && ! in_array((string) $request->status, [FiscalDocumentRequest::STATUS_ISSUED, FiscalDocumentRequest::STATUS_CANCELLED], true)) {
            $findings[] = $this->finding('warning', 'external_reference_without_issued_status', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_external_reference_without_issued_status', 'external_reference_on_non_issued_status');
        }

        if ($invoice instanceof Invoice && $request->issued_at && $this->dateValue($request, 'issued_at')?->lt($this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at'))) {
            $findings[] = $this->finding($hasExternalDocument ? 'critical' : 'warning', 'fiscal_issue_before_invoice', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_fiscal_issue_before_invoice', 'issued_before_invoice_date');
        }

        $firstPaymentDate = $this->firstPaymentDate($confirmedPayments, $confirmedAllocations);
        if ($request->issued_at && $firstPaymentDate && $this->dateValue($request, 'issued_at')?->lt($firstPaymentDate)) {
            $findings[] = $this->finding($hasExternalDocument ? 'critical' : 'warning', 'receipt_issued_before_payment', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_receipt_issued_before_payment', 'issued_before_confirmed_payment');
        }

        if ($active && $this->hasReversalBeforeRequest($request, $payments, $allocations, $invoice)) {
            $findings[] = $this->finding('warning', 'fiscal_request_after_reversal', $request, $invoice, $payments->first(), $allocations->first(), $entry, true, 'review_fiscal_request_after_reversal', 'request_created_after_reversal');
        }

        if ($hasExternalDocument && $this->hasReversalBeforeIssuedDocument($request, $payments, $allocations, $invoice)) {
            $findings[] = $this->finding('critical', 'issued_document_after_reversal', $request, $invoice, $payments->first(), $allocations->first(), $entry, true, 'review_issued_document_after_reversal', 'document_issued_after_reversal');
        }

        if ($this->isStalePending($request) && ! $hasExternalDocument) {
            $findings[] = $this->finding('warning', 'stale_pending_fiscal_request', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, true, 'review_stale_pending_fiscal_request', 'active_old_pending_request_without_external_document');
        }

        if ($this->providerUnknown($request) && count($findings) === 0) {
            $findings[] = $this->finding('info', 'fiscal_request_provider_unknown', $request, $invoice, $confirmedPayments->first(), $confirmedAllocations->first(), $entry, false, 'review_fiscal_provider_mapping', 'provider_missing_or_unknown_without_other_impact');
        }

        return $findings;
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function duplicateFindings(Collection $requests, Collection $invoices, Collection $allocations): array
    {
        $findings = [];
        $active = $requests->filter(fn (FiscalDocumentRequest $request): bool => $this->activeRequest($request));

        $active
            ->whereIn('status', [FiscalDocumentRequest::STATUS_PENDING, FiscalDocumentRequest::STATUS_IN_PROGRESS])
            ->groupBy(fn (FiscalDocumentRequest $request): string => $this->obligationKey($request, $allocations))
            ->filter(static fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $invoices, $allocations): void {
                $request = $group->first();
                $invoice = $request->invoice_id ? $invoices->firstWhere('id', $request->invoice_id) : null;
                $findings[] = $this->finding('warning', 'duplicate_pending_fiscal_request', $request, $invoice instanceof Invoice ? $invoice : null, null, $allocations->where('invoice_id', $request->invoice_id)->first(), null, true, 'review_duplicate_pending_fiscal_request', 'duplicate_pending_active_requests', [
                    'duplicate_fiscal_request_ids' => $group->pluck('id')->map('strval')->values()->all(),
                ]);
            });

        $active
            ->filter(fn (FiscalDocumentRequest $request): bool => $this->hasExternalReference($request))
            ->groupBy(fn (FiscalDocumentRequest $request): string => $this->obligationKey($request, $allocations))
            ->filter(static fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $invoices, $allocations): void {
                $request = $group->first();
                $invoice = $request->invoice_id ? $invoices->firstWhere('id', $request->invoice_id) : null;
                $findings[] = $this->finding('critical', 'duplicate_issued_external_document', $request, $invoice instanceof Invoice ? $invoice : null, null, $allocations->where('invoice_id', $request->invoice_id)->first(), null, true, 'review_duplicate_issued_external_document', 'duplicate_external_document_for_same_obligation', [
                    'duplicate_fiscal_request_ids' => $group->pluck('id')->map('strval')->values()->all(),
                ]);
            });

        return $findings;
    }

    private function activeRequest(FiscalDocumentRequest $request): bool
    {
        return ! $request->trashed()
            && ! in_array((string) $request->status, [FiscalDocumentRequest::STATUS_CANCELLED, FiscalDocumentRequest::STATUS_NOT_APPLICABLE], true);
    }

    private function activePayment(Payment $payment): bool
    {
        return $payment->deleted_at === null && (string) $payment->status === Payment::STATUS_CONFIRMED;
    }

    private function activeAllocation(PaymentAllocation $allocation): bool
    {
        return $allocation->deleted_at === null && (string) $allocation->status === PaymentAllocation::STATUS_CONFIRMED;
    }

    private function requiresPayment(FiscalDocumentRequest $request): bool
    {
        return in_array((string) $request->document_type, [
            FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE_RECEIPT,
        ], true);
    }

    private function hasExternalDocument(FiscalDocumentRequest $request): bool
    {
        return $this->hasExternalReference($request)
            || $request->issued_at !== null;
    }

    private function hasExternalReference(FiscalDocumentRequest $request): bool
    {
        return filled($request->external_document_number)
            || filled($request->external_document_id);
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function amountMismatch(FiscalDocumentRequest $request, Invoice $invoice, Collection $payments, Collection $allocations): bool
    {
        $amount = $this->money($request->amount);
        $invoiceAmount = $this->money($invoice->valor_total);
        $allocationSum = $this->money($allocations->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount));
        $paymentSum = $this->money($payments->filter(fn (Payment $payment): bool => $this->activePayment($payment))->sum(fn (Payment $payment): float => (float) $payment->amount));

        if (abs($amount - $invoiceAmount) > self::TOLERANCE) {
            return true;
        }

        if ($allocationSum > 0 && abs($amount - $allocationSum) > self::TOLERANCE) {
            return true;
        }

        return $paymentSum > 0 && abs($amount - $paymentSum) > self::TOLERANCE;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function firstPaymentDate(Collection $payments, Collection $allocations): ?Carbon
    {
        return $payments
            ->map(fn (Payment $payment): ?Carbon => $this->dateValue($payment, 'payment_date') ?? $this->dateValue($payment, 'created_at'))
            ->merge($allocations->map(fn (PaymentAllocation $allocation): ?Carbon => $this->dateValue($allocation, 'allocated_at') ?? $this->dateValue($allocation, 'created_at')))
            ->filter()
            ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
            ->first();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function hasReversalBeforeRequest(FiscalDocumentRequest $request, Collection $payments, Collection $allocations, ?Invoice $invoice): bool
    {
        $requestDate = $this->dateValue($request, 'created_at');
        if (! $requestDate) {
            return false;
        }

        return $this->reversalDates($payments, $allocations, $invoice)
            ->contains(fn (Carbon $date): bool => $date->lte($requestDate));
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function hasReversalBeforeIssuedDocument(FiscalDocumentRequest $request, Collection $payments, Collection $allocations, ?Invoice $invoice): bool
    {
        $issuedAt = $this->dateValue($request, 'issued_at');
        if (! $issuedAt) {
            return false;
        }

        return $this->reversalDates($payments, $allocations, $invoice)
            ->contains(fn (Carbon $date): bool => $date->lte($issuedAt));
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,Carbon>
     */
    private function reversalDates(Collection $payments, Collection $allocations, ?Invoice $invoice): Collection
    {
        $dates = collect();

        foreach ($payments as $payment) {
            if ((string) $payment->status === Payment::STATUS_CANCELLED || $payment->deleted_at !== null) {
                $date = $this->dateValue($payment, 'cancelled_at') ?? $this->dateValue($payment, 'deleted_at') ?? $this->dateValue($payment, 'updated_at');
                if ($date) {
                    $dates->push($date);
                }
            }
        }

        foreach ($allocations as $allocation) {
            if ((string) $allocation->status === PaymentAllocation::STATUS_CANCELLED || $allocation->deleted_at !== null) {
                $date = $this->dateValue($allocation, 'deleted_at') ?? $this->dateValue($allocation, 'updated_at');
                if ($date) {
                    $dates->push($date);
                }
            }
        }

        if ($invoice instanceof Invoice && (string) $invoice->estado_pagamento === 'cancelado') {
            $date = $this->dateValue($invoice, 'updated_at') ?? $this->dateValue($invoice, 'created_at');
            if ($date) {
                $dates->push($date);
            }
        }

        return $dates;
    }

    private function isStalePending(FiscalDocumentRequest $request): bool
    {
        $createdAt = $this->dateValue($request, 'created_at');

        return $this->activeRequest($request)
            && (string) $request->status === FiscalDocumentRequest::STATUS_PENDING
            && ! $this->hasExternalDocument($request)
            && $createdAt instanceof Carbon
            && $createdAt->lt(Carbon::now()->subDays(self::STALE_DAYS)->startOfDay());
    }

    private function isArchivedStaleFiscalRequest(FiscalDocumentRequest $request): bool
    {
        return (bool) data_get($request->metadata, 'stale_cleanup') === true
            && in_array(data_get($request->metadata, 'stale_cleanup_version'), ['a3-6', 'a4-6'], true)
            && blank($request->external_document_number)
            && blank($request->external_document_id)
            && $request->issued_at === null;
    }

    private function providerUnknown(FiscalDocumentRequest $request): bool
    {
        $provider = $this->normalizeNullableString($request->provider);

        return $provider === null || ! in_array($provider, [FiscalDocumentRequest::PROVIDER_WINTOUCH], true);
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function obligationKey(FiscalDocumentRequest $request, Collection $allocations): string
    {
        if ($request->invoice_id) {
            return 'invoice:' . (string) $request->invoice_id;
        }

        $allocation = $allocations->firstWhere('invoice_id', $request->invoice_id);
        if ($allocation instanceof PaymentAllocation) {
            return 'allocation:' . (string) $allocation->id;
        }

        return $request->financial_entry_id ? 'financial_entry:' . (string) $request->financial_entry_id : '';
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, FiscalDocumentRequest $request, ?Invoice $invoice, ?Payment $payment, ?PaymentAllocation $allocation, ?FinancialEntry $entry, bool $actionable, string $recommendation, string $reason, array $extra = []): array
    {
        return array_merge([
            'severity' => $severity,
            'code' => $code,
            'fiscal_request_id' => (string) $request->id,
            'invoice_id' => $invoice?->id ? (string) $invoice->id : ($request->invoice_id ? (string) $request->invoice_id : null),
            'payment_id' => $payment?->id ? (string) $payment->id : null,
            'allocation_id' => $allocation?->id ? (string) $allocation->id : null,
            'financial_entry_id' => $entry?->id ? (string) $entry->id : ($request->financial_entry_id ? (string) $request->financial_entry_id : null),
            'user_id' => $request->user_id ? (string) $request->user_id : ($invoice?->user_id ? (string) $invoice->user_id : ($payment?->user_id ? (string) $payment->user_id : null)),
            'amount' => $this->money($request->amount),
            'invoice_amount' => $invoice ? $this->money($invoice->valor_total) : null,
            'payment_amount' => $payment ? $this->money($payment->amount) : null,
            'allocation_amount' => $allocation ? $this->money($allocation->amount) : null,
            'external_document_number' => $this->normalizeNullableString($request->external_document_number),
            'external_document_id' => $this->normalizeNullableString($request->external_document_id),
            'external_provider' => $this->normalizeNullableString($request->provider),
            'fiscal_status' => (string) $request->status,
            'invoice_status' => $invoice?->estado_pagamento,
            'payment_status' => $payment?->status,
            'allocation_status' => $allocation?->status,
            'issued_at' => $request->issued_at?->toIso8601String(),
            'handled_at' => $request->handled_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'deleted_at' => $request->deleted_at?->toIso8601String(),
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'classification_reason' => $reason,
        ], $extra);
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
                (string) ($finding['fiscal_request_id'] ?? ''),
                (string) ($finding['invoice_id'] ?? ''),
                (string) ($finding['payment_id'] ?? ''),
                (string) ($finding['allocation_id'] ?? ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $requests
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $requests, Collection $invoices, Collection $payments, Collection $allocations, array $findings): array
    {
        return [
            'total_fiscal_requests_scanned' => $requests->count(),
            'total_invoices_touched' => $invoices->count(),
            'total_payments_touched' => $payments->count(),
            'total_allocations_touched' => $allocations->count(),
            'total_external_documents_detected' => $requests->filter(fn (FiscalDocumentRequest $request): bool => $this->hasExternalDocument($request))->count(),
            'clean_fiscal_chain_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'clean_fiscal_chain')),
            'pending_request_count' => $requests->where('status', FiscalDocumentRequest::STATUS_PENDING)->count(),
            'issued_document_count' => $requests->where('status', FiscalDocumentRequest::STATUS_ISSUED)->count(),
            'stale_pending_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'stale_pending_fiscal_request')),
            'duplicate_request_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'duplicate_pending_fiscal_request')),
            'duplicate_external_document_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'duplicate_issued_external_document')),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'actionable_count' => count(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))),
            'invoices_with_findings' => collect($findings)->pluck('invoice_id')->filter()->unique()->count(),
            'payments_with_findings' => collect($findings)->pluck('payment_id')->filter()->unique()->count(),
            'allocations_with_findings' => collect($findings)->pluck('allocation_id')->filter()->unique()->count(),
            'fiscal_requests_with_findings' => collect($findings)->pluck('fiscal_request_id')->filter()->unique()->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        return [
            'fiscal_tables' => array_values(array_filter(['fiscal_document_requests', 'receipt_import_items'], static fn (string $table): bool => Schema::hasTable($table))),
            'fiscal_request_columns' => $this->columns('fiscal_document_requests', ['id', 'invoice_id', 'user_id', 'bank_statement_id', 'mapa_conciliacao_id', 'financial_entry_id', 'provider', 'document_type', 'status', 'amount', 'paid_at', 'due_at', 'external_document_number', 'external_document_id', 'issued_at', 'handled_at', 'metadata', 'deleted_at', 'created_at', 'updated_at']),
            'invoice_fiscal_columns' => $this->columns('invoices', ['id', 'numero_recibo', 'recibo_emitido_em', 'recibo_pdf_path', 'receipt_import_item_id', 'estado_pagamento', 'valor_total', 'valor_pago', 'data_emissao', 'data_pagamento']),
            'payment_columns' => $this->columns('payments', ['id', 'amount', 'allocated_amount', 'unallocated_amount', 'payment_date', 'source', 'status', 'cancelled_at', 'deleted_at']),
            'allocation_columns' => $this->columns('payment_allocations', ['id', 'payment_id', 'invoice_id', 'financial_entry_id', 'amount', 'status', 'allocated_at', 'deleted_at']),
            'external_provider_columns' => $this->columns('fiscal_document_requests', ['provider', 'external_document_number', 'external_document_id', 'external_document_url', 'external_series']),
            'soft_delete_supported' => [
                'fiscal_document_requests' => Schema::hasColumn('fiscal_document_requests', 'deleted_at'),
                'payments' => Schema::hasColumn('payments', 'deleted_at'),
                'payment_allocations' => Schema::hasColumn('payment_allocations', 'deleted_at'),
            ],
        ];
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function columns(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn($table, $column)));
    }

    private function dateValue(object|null $model, string $field): ?Carbon
    {
        if ($model === null) {
            return null;
        }

        $value = data_get($model, $field);
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->startOfDay();
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
