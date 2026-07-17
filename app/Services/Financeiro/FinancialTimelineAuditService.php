<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankStatement;
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
use Illuminate\Support\Facades\Schema;

final class FinancialTimelineAuditService
{
    private const VERSION = 'a5-3-financial-timeline-audit-v1';
    private const WARNING_AFTER_DAYS = 1;
    private const HISTORICAL_AFTER_DAYS = 180;

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
        $fiscalRequests = $this->fiscalRequests($filters, $invoices, $bankStatements, $payments);
        $financialEntries = $this->financialEntries($filters, $payments, $allocations, $invoices, $bankStatements, $fiscalRequests);
        $reconciliations = $this->reconciliations($filters, $bankStatements, $payments, $allocations, $invoices, $financialEntries);
        $bankAllocations = $this->bankAllocations($filters, $bankStatements, $payments, $allocations, $invoices);

        $findings = [];

        foreach ($payments as $payment) {
            array_push($findings, ...$this->paymentTimelineFindings($payment, $bankStatements, $reconciliations, $bankAllocations));
        }

        foreach ($allocations as $allocation) {
            array_push($findings, ...$this->allocationTimelineFindings($allocation, $payments, $invoices, $fiscalRequests));
        }

        foreach ($invoices as $invoice) {
            array_push($findings, ...$this->invoiceTimelineFindings($invoice, $payments, $allocations, $fiscalRequests));
        }

        foreach ($fiscalRequests as $request) {
            array_push($findings, ...$this->fiscalTimelineFindings($request, $payments, $allocations, $invoices));
        }

        foreach ($financialEntries as $entry) {
            array_push($findings, ...$this->financialEntryTimelineFindings($entry, $payments, $allocations, $invoices, $bankStatements, $fiscalRequests));
        }

        foreach ($allocations as $allocation) {
            $allocationFindings = collect($findings)
                ->where('allocation_id', (string) $allocation->id)
                ->reject(fn (array $finding): bool => $finding['code'] === 'clean_financial_timeline')
                ->values();

            if ($allocationFindings->isEmpty()) {
                $payment = $payments->firstWhere('id', $allocation->payment_id);
                $invoice = $invoices->firstWhere('id', $allocation->invoice_id);
                $bank = $payment instanceof Payment ? $bankStatements->firstWhere('id', $payment->bank_statement_id) : null;
                $request = $invoice instanceof Invoice ? $fiscalRequests->firstWhere('invoice_id', $invoice->id) : null;

                $findings[] = $this->finding(
                    'info',
                    'clean_financial_timeline',
                    $bank instanceof BankStatement ? $bank : null,
                    $this->reconciliationFor($reconciliations, $payment, $allocation, $invoice),
                    $payment instanceof Payment ? $payment : null,
                    $allocation,
                    $invoice instanceof Invoice ? $invoice : null,
                    $request instanceof FiscalDocumentRequest ? $request : null,
                    $this->financialEntryFor($financialEntries, $payment, $allocation, $invoice, $request instanceof FiscalDocumentRequest ? $request : null),
                    false,
                    'no_action_needed_clean_financial_timeline',
                    $this->context($bank instanceof BankStatement ? $bank : null, $this->reconciliationFor($reconciliations, $payment, $allocation, $invoice), $payment instanceof Payment ? $payment : null, $allocation, $invoice instanceof Invoice ? $invoice : null, $request instanceof FiscalDocumentRequest ? $request : null, 'clean'),
                );
            }
        }

        foreach ($invoices as $invoice) {
            if ($this->isHistoricalIncomplete($invoice, $payments, $allocations, $fiscalRequests)) {
                $findings[] = $this->finding(
                    'info',
                    'historical_timeline_incomplete',
                    null,
                    null,
                    null,
                    null,
                    $invoice,
                    $fiscalRequests->firstWhere('invoice_id', $invoice->id),
                    null,
                    false,
                    'no_action_needed_historical_timeline_incomplete',
                    $this->context(null, null, null, null, $invoice, $fiscalRequests->firstWhere('invoice_id', $invoice->id), 'historical_incomplete'),
                );
            }
        }

        $findings = $this->uniqueFindings($findings);

        if (! $filters['include_clean']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => $finding['code'] !== 'clean_financial_timeline'));
        }

        if ($filters['only_actionable']) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false)));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $this->schemaDetected(),
            'summary' => $this->summary($bankStatements, $reconciliations, $bankAllocations, $payments, $allocations, $invoices, $fiscalRequests, $findings),
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
            'bank_transaction' => $this->normalizeNullableString($options['bank_transaction'] ?? null),
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

        $query = BankStatement::query()->orderBy('data_movimento')->orderBy('created_at')->orderBy('id');

        if ($filters['bank_transaction']) {
            $query->whereKey($filters['bank_transaction']);
        }

        if ($filters['from'] && Schema::hasColumn('bank_statements', 'data_movimento')) {
            $query->whereDate('data_movimento', '>=', Carbon::parse((string) $filters['from'])->toDateString());
        }

        if ($filters['to'] && Schema::hasColumn('bank_statements', 'data_movimento')) {
            $query->whereDate('data_movimento', '<=', Carbon::parse((string) $filters['to'])->toDateString());
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

        $query = Payment::withTrashed()->orderBy('payment_date')->orderBy('created_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['payment']) {
            $query->whereKey($filters['payment']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['from'] && Schema::hasColumn('payments', 'payment_date')) {
            $query->whereDate('payment_date', '>=', Carbon::parse((string) $filters['from'])->toDateString());
        }

        if ($filters['to'] && Schema::hasColumn('payments', 'payment_date')) {
            $query->whereDate('payment_date', '<=', Carbon::parse((string) $filters['to'])->toDateString());
        }

        if ($filters['bank_transaction']) {
            $query->where('bank_statement_id', $filters['bank_transaction']);
        } elseif ($bankStatements->isNotEmpty()) {
            $query->where(function (Builder $query) use ($bankStatements): void {
                $ids = $bankStatements->pluck('id')->all();
                $query->whereIn('bank_statement_id', $ids)
                    ->orWhereHas('allocations', fn (Builder $allocationQuery) => $allocationQuery->withTrashed()->whereHas('financialEntry', fn (Builder $entryQuery) => $entryQuery->whereIn('bank_statement_id', $ids)));
            });
        }

        if ($filters['invoice']) {
            $query->whereHas('allocations', fn (Builder $allocationQuery) => $allocationQuery->withTrashed()->where('invoice_id', $filters['invoice']));
        }

        if ($filters['allocation']) {
            $query->whereHas('allocations', fn (Builder $allocationQuery) => $allocationQuery->withTrashed()->whereKey($filters['allocation']));
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

        $query = PaymentAllocation::withTrashed()->orderBy('allocated_at')->orderBy('created_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['allocation']) {
            $query->whereKey($filters['allocation']);
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

        $ids = $allocations->pluck('invoice_id')->filter()->unique()->values();

        if ($filters['invoice']) {
            $ids->push($filters['invoice']);
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = Invoice::query()->whereIn('id', $ids->unique()->values()->all())->orderBy('data_emissao')->orderBy('id');

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,Payment> $payments
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function fiscalRequests(array $filters, Collection $invoices, Collection $bankStatements, Collection $payments): Collection
    {
        if (! Schema::hasTable('fiscal_document_requests')) {
            return collect();
        }

        $query = FiscalDocumentRequest::withTrashed()->orderBy('created_at')->orderBy('issued_at')->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        } elseif ($invoices->isNotEmpty()) {
            $query->whereIn('invoice_id', $invoices->pluck('id')->all());
        }

        if ($filters['bank_transaction']) {
            $query->orWhere('bank_statement_id', $filters['bank_transaction']);
        } elseif ($bankStatements->isNotEmpty()) {
            $query->orWhereIn('bank_statement_id', $bankStatements->pluck('id')->all());
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        } elseif ($payments->isNotEmpty()) {
            $query->orWhereIn('user_id', $payments->pluck('user_id')->filter()->unique()->values()->all());
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(array $filters, Collection $payments, Collection $allocations, Collection $invoices, Collection $bankStatements, Collection $fiscalRequests): Collection
    {
        if (! Schema::hasTable('financial_entries')) {
            return collect();
        }

        if ($payments->isEmpty()
            && $allocations->isEmpty()
            && $invoices->isEmpty()
            && $bankStatements->isEmpty()
            && $fiscalRequests->isEmpty()
            && ! $filters['payment']
            && ! $filters['invoice']
            && ! $filters['bank_transaction']
            && ! $filters['user']) {
            return collect();
        }

        $query = FinancialEntry::query()->orderBy('data')->orderBy('created_at')->orderBy('id');

        $query->where(function (Builder $query) use ($filters, $payments, $allocations, $invoices, $bankStatements, $fiscalRequests): void {
            if ($payments->isNotEmpty()) {
                $query->whereIn('payment_id', $payments->pluck('id')->all());
            }

            if ($allocations->isNotEmpty()) {
                $query->orWhereIn('id', $allocations->pluck('financial_entry_id')->filter()->unique()->values()->all())
                    ->orWhere(function (Builder $nested) use ($allocations): void {
                        $nested->where('origem_tipo', 'payment_allocation')->whereIn('origem_id', $allocations->pluck('id')->all());
                    });
            }

            if ($invoices->isNotEmpty()) {
                $query->orWhereIn('fatura_id', $invoices->pluck('id')->all());
            }

            if ($bankStatements->isNotEmpty()) {
                $query->orWhereIn('bank_statement_id', $bankStatements->pluck('id')->all());
            }

            if ($fiscalRequests->isNotEmpty()) {
                $query->orWhereIn('fiscal_document_request_id', $fiscalRequests->pluck('id')->all());
            }

            if ($filters['payment']) {
                $query->orWhere('payment_id', $filters['payment']);
            }

            if ($filters['invoice']) {
                $query->orWhere('fatura_id', $filters['invoice']);
            }

            if ($filters['bank_transaction']) {
                $query->orWhere('bank_statement_id', $filters['bank_transaction']);
            }

            if ($filters['user']) {
                $query->orWhere('user_id', $filters['user']);
            }
        });

        return $query->get();
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

        if ($bankStatements->isEmpty()
            && $payments->isEmpty()
            && $allocations->isEmpty()
            && $invoices->isEmpty()
            && $financialEntries->isEmpty()
            && ! $filters['bank_transaction']) {
            return collect();
        }

        $query = MapaConciliacao::query()->orderBy('created_at')->orderBy('id');

        $query->where(function (Builder $query) use ($filters, $bankStatements, $payments, $allocations, $invoices, $financialEntries): void {
            if ($bankStatements->isNotEmpty()) {
                $query->whereIn('extrato_id', $bankStatements->pluck('id')->all());
            }

            if ($payments->isNotEmpty()) {
                $query->orWhereIn('payment_id', $payments->pluck('id')->all());
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

            if ($filters['bank_transaction']) {
                $query->orWhere('extrato_id', $filters['bank_transaction']);
            }
        });

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
    private function bankAllocations(array $filters, Collection $bankStatements, Collection $payments, Collection $allocations, Collection $invoices): Collection
    {
        if (! Schema::hasTable('bank_transaction_allocations')) {
            return collect();
        }

        if ($bankStatements->isEmpty()
            && $payments->isEmpty()
            && $allocations->isEmpty()
            && $invoices->isEmpty()
            && ! $filters['user']) {
            return collect();
        }

        $query = BankTransactionAllocation::query()->orderBy('committed_at')->orderBy('created_at')->orderBy('id');

        $query->where(function (Builder $query) use ($filters, $bankStatements, $payments, $allocations, $invoices): void {
            if ($bankStatements->isNotEmpty()) {
                $query->whereIn('bank_statement_id', $bankStatements->pluck('id')->all());
            }

            if ($payments->isNotEmpty()) {
                $query->orWhereIn('payment_id', $payments->pluck('id')->all());
            }

            if ($allocations->isNotEmpty()) {
                $query->orWhereIn('payment_allocation_id', $allocations->pluck('id')->all());
            }

            if ($invoices->isNotEmpty()) {
                $query->orWhereIn('invoice_id', $invoices->pluck('id')->all());
            }

            if ($filters['user']) {
                $query->orWhere('user_id', $filters['user']);
            }
        });

        return $query->get();
    }

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return list<array<string,mixed>>
     */
    private function paymentTimelineFindings(Payment $payment, Collection $bankStatements, Collection $reconciliations, Collection $bankAllocations): array
    {
        $findings = [];
        $bank = $bankStatements->firstWhere('id', $payment->bank_statement_id);
        $bankDate = $this->dateValue($bank, 'data_movimento');
        $paymentDate = $this->dateValue($payment, 'payment_date') ?? $this->dateValue($payment, 'created_at');

        if ($bank instanceof BankStatement && $bankDate && $paymentDate && $paymentDate->lt($bankDate)) {
            $diff = $this->diffDays($paymentDate, $bankDate);
            $severity = $diff > self::WARNING_AFTER_DAYS && $this->activePayment($payment) ? 'warning' : 'info';
            $findings[] = $this->finding(
                $severity,
                'payment_before_bank_transaction',
                $bank,
                $this->reconciliationFor($reconciliations, $payment),
                $payment,
                null,
                null,
                null,
                null,
                $severity === 'warning',
                'review_payment_date_before_bank_transaction',
                $this->context($bank, $this->reconciliationFor($reconciliations, $payment), $payment, null, null, null, 'payment_before_bank_transaction', $diff),
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return list<array<string,mixed>>
     */
    private function allocationTimelineFindings(PaymentAllocation $allocation, Collection $payments, Collection $invoices, Collection $fiscalRequests): array
    {
        $findings = [];
        $payment = $payments->firstWhere('id', $allocation->payment_id);
        $invoice = $invoices->firstWhere('id', $allocation->invoice_id);
        $allocationDate = $this->dateValue($allocation, 'allocated_at') ?? $this->dateValue($allocation, 'created_at');
        $paymentDate = $payment instanceof Payment ? ($this->dateValue($payment, 'payment_date') ?? $this->dateValue($payment, 'created_at')) : null;
        $invoiceDate = $invoice instanceof Invoice ? ($this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at')) : null;
        $cancelledAt = $this->dateValue($allocation, 'deleted_at');

        if ($payment instanceof Payment && $allocationDate && $paymentDate && $allocationDate->lt($paymentDate)) {
            $diff = $this->diffDays($allocationDate, $paymentDate);
            $findings[] = $this->finding(
                'warning',
                'allocation_before_payment',
                null,
                null,
                $payment,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                null,
                null,
                $this->activeAllocation($allocation),
                'review_allocation_date_before_payment',
                $this->context(null, null, $payment, $allocation, $invoice instanceof Invoice ? $invoice : null, null, 'allocation_before_payment', $diff),
            );
        }

        if ($invoice instanceof Invoice && $allocationDate && $invoiceDate && $allocationDate->lt($invoiceDate)) {
            $diff = $this->diffDays($allocationDate, $invoiceDate);
            $legacy = $this->legacyInvoice($invoice);
            $findings[] = $this->finding(
                $legacy ? 'info' : 'warning',
                'allocation_before_invoice_issue',
                null,
                null,
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice,
                null,
                null,
                ! $legacy && $this->activeAllocation($allocation),
                'review_allocation_before_invoice_issue_date',
                $this->context(null, null, $payment instanceof Payment ? $payment : null, $allocation, $invoice, null, $legacy ? 'legacy_allocation_before_invoice_issue' : 'allocation_before_invoice_issue', $diff),
            );
        }

        if ($cancelledAt && $allocationDate && $cancelledAt->lt($allocationDate)) {
            $findings[] = $this->finding(
                'warning',
                'reversal_before_original_action',
                null,
                null,
                $payment instanceof Payment ? $payment : null,
                $allocation,
                $invoice instanceof Invoice ? $invoice : null,
                null,
                null,
                true,
                'review_reversal_before_original_action',
                $this->context(null, null, $payment instanceof Payment ? $payment : null, $allocation, $invoice instanceof Invoice ? $invoice : null, null, 'allocation_deleted_before_allocated_at', $this->diffDays($cancelledAt, $allocationDate)),
            );
        }

        if ($invoice instanceof Invoice) {
            foreach ($fiscalRequests->where('invoice_id', $invoice->id) as $request) {
                $issuedAt = $this->dateValue($request, 'issued_at');
                if ($issuedAt && $cancelledAt && $cancelledAt->gt($issuedAt)) {
                    $findings[] = $this->finding(
                        filled($request->external_document_number) || filled($request->external_document_id) ? 'critical' : 'warning',
                        'cancelled_allocation_after_fiscal_issue',
                        null,
                        null,
                        $payment instanceof Payment ? $payment : null,
                        $allocation,
                        $invoice,
                        $request,
                        null,
                        true,
                        'review_cancelled_allocation_after_fiscal_issue',
                        $this->context(null, null, $payment instanceof Payment ? $payment : null, $allocation, $invoice, $request, 'cancelled_allocation_after_fiscal_issue', $this->diffDays($issuedAt, $cancelledAt)),
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return list<array<string,mixed>>
     */
    private function invoiceTimelineFindings(Invoice $invoice, Collection $payments, Collection $allocations, Collection $fiscalRequests): array
    {
        $findings = [];
        $issueDate = $this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at');
        $dueDate = $this->dateValue($invoice, 'data_vencimento');
        $receiptDate = $this->dateValue($invoice, 'recibo_emitido_em');
        $confirmedAllocations = $allocations
            ->where('invoice_id', $invoice->id)
            ->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))
            ->values();
        $firstPaymentDate = $this->firstConfirmedPaymentDate($confirmedAllocations, $payments);

        if ($issueDate && $dueDate && $dueDate->lt($issueDate)) {
            $findings[] = $this->finding(
                'warning',
                'invoice_due_before_issue',
                null,
                null,
                null,
                null,
                $invoice,
                null,
                null,
                true,
                'review_invoice_due_before_issue',
                $this->context(null, null, null, null, $invoice, null, 'invoice_due_before_issue', $this->diffDays($dueDate, $issueDate)),
            );
        }

        if ($receiptDate && (! $firstPaymentDate || $receiptDate->lt($firstPaymentDate))) {
            $findings[] = $this->finding(
                filled($invoice->numero_recibo) ? 'warning' : 'info',
                'receipt_before_payment',
                null,
                null,
                null,
                $confirmedAllocations->first(),
                $invoice,
                $fiscalRequests->firstWhere('invoice_id', $invoice->id),
                null,
                filled($invoice->numero_recibo),
                'review_receipt_before_payment',
                $this->context(null, null, null, $confirmedAllocations->first(), $invoice, $fiscalRequests->firstWhere('invoice_id', $invoice->id), 'receipt_before_payment', $firstPaymentDate ? $this->diffDays($receiptDate, $firstPaymentDate) : null),
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @return list<array<string,mixed>>
     */
    private function fiscalTimelineFindings(FiscalDocumentRequest $request, Collection $payments, Collection $allocations, Collection $invoices): array
    {
        $findings = [];
        $invoice = $invoices->firstWhere('id', $request->invoice_id);
        $invoiceAllocations = $allocations->where('invoice_id', $request->invoice_id)->values();
        $confirmedAllocations = $invoiceAllocations->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))->values();
        $firstPaymentDate = $this->firstConfirmedPaymentDate($confirmedAllocations, $payments);
        $requestDate = $this->dateValue($request, 'created_at');
        $issuedAt = $this->dateValue($request, 'issued_at');
        $invoiceDate = $invoice instanceof Invoice ? ($this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at')) : null;

        if ($this->isArchivedStaleFiscalRequest($request)) {
            $findings[] = $this->finding(
                'info',
                'stale_pending_fiscal_request_timeline_info',
                null,
                null,
                null,
                null,
                $invoice instanceof Invoice ? $invoice : null,
                $request,
                null,
                false,
                'no_action_needed_stale_pending_fiscal_request_timeline',
                $this->context(null, null, null, null, $invoice instanceof Invoice ? $invoice : null, $request, 'stale_cleanup_archived'),
            );
        }

        if (! $this->isArchivedStaleFiscalRequest($request)
            && $requestDate
            && (! $firstPaymentDate || $requestDate->lt($firstPaymentDate))
            && in_array((string) $request->status, [FiscalDocumentRequest::STATUS_PENDING, FiscalDocumentRequest::STATUS_IN_PROGRESS, FiscalDocumentRequest::STATUS_ISSUED], true)) {
            $findings[] = $this->finding(
                'warning',
                'fiscal_request_before_payment_or_allocation',
                null,
                null,
                null,
                $confirmedAllocations->first(),
                $invoice instanceof Invoice ? $invoice : null,
                $request,
                null,
                true,
                'review_fiscal_request_before_payment_confirmation',
                $this->context(null, null, null, $confirmedAllocations->first(), $invoice instanceof Invoice ? $invoice : null, $request, 'fiscal_request_before_payment_or_allocation', $firstPaymentDate ? $this->diffDays($requestDate, $firstPaymentDate) : null),
            );
        }

        if ($issuedAt && $invoiceDate && $issuedAt->lt($invoiceDate)) {
            $findings[] = $this->finding(
                filled($request->external_document_number) || filled($request->external_document_id) ? 'critical' : 'warning',
                'fiscal_issue_before_invoice',
                null,
                null,
                null,
                $confirmedAllocations->first(),
                $invoice instanceof Invoice ? $invoice : null,
                $request,
                null,
                true,
                'review_fiscal_issue_before_invoice',
                $this->context(null, null, null, $confirmedAllocations->first(), $invoice instanceof Invoice ? $invoice : null, $request, 'fiscal_issue_before_invoice', $this->diffDays($issuedAt, $invoiceDate)),
            );
        }

        if ($issuedAt && (! $firstPaymentDate || $issuedAt->lt($firstPaymentDate))) {
            $findings[] = $this->finding(
                filled($request->external_document_number) || filled($request->external_document_id) ? 'warning' : 'info',
                'receipt_before_payment',
                null,
                null,
                null,
                $confirmedAllocations->first(),
                $invoice instanceof Invoice ? $invoice : null,
                $request,
                null,
                filled($request->external_document_number) || filled($request->external_document_id),
                'review_receipt_before_payment',
                $this->context(null, null, null, $confirmedAllocations->first(), $invoice instanceof Invoice ? $invoice : null, $request, 'fiscal_document_before_payment', $firstPaymentDate ? $this->diffDays($issuedAt, $firstPaymentDate) : null),
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @return list<array<string,mixed>>
     */
    private function financialEntryTimelineFindings(FinancialEntry $entry, Collection $payments, Collection $allocations, Collection $invoices, Collection $bankStatements, Collection $fiscalRequests): array
    {
        $entryDate = $this->dateValue($entry, 'data') ?? $this->dateValue($entry, 'created_at');
        $sourceDate = null;
        $payment = $payments->firstWhere('id', $entry->payment_id);
        $allocation = $allocations->firstWhere('financial_entry_id', $entry->id)
            ?? ($entry->origem_tipo === 'payment_allocation' ? $allocations->firstWhere('id', $entry->origem_id) : null);
        $invoice = $invoices->firstWhere('id', $entry->fatura_id);
        $bank = $bankStatements->firstWhere('id', $entry->bank_statement_id);
        $request = $fiscalRequests->firstWhere('id', $entry->fiscal_document_request_id);

        if ($allocation instanceof PaymentAllocation) {
            $sourceDate = $this->dateValue($allocation, 'allocated_at') ?? $this->dateValue($allocation, 'created_at');
        } elseif ($payment instanceof Payment) {
            $sourceDate = $this->dateValue($payment, 'payment_date') ?? $this->dateValue($payment, 'created_at');
        } elseif ($invoice instanceof Invoice) {
            $sourceDate = $this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at');
        } elseif ($bank instanceof BankStatement) {
            $sourceDate = $this->dateValue($bank, 'data_movimento') ?? $this->dateValue($bank, 'created_at');
        } elseif ($request instanceof FiscalDocumentRequest) {
            $sourceDate = $this->dateValue($request, 'created_at');
        }

        if ($entryDate && $sourceDate && $entryDate->lt($sourceDate)) {
            if ($allocation instanceof PaymentAllocation
                && $this->isEconomicDateBeforeTechnicalAllocation($entry, $payment instanceof Payment ? $payment : null, $allocation, $invoice instanceof Invoice ? $invoice : null, $bank instanceof BankStatement ? $bank : null, $allocations, $fiscalRequests)) {
                return [
                    $this->finding(
                        'info',
                        'financial_entry_economic_date_before_technical_allocation',
                        $bank instanceof BankStatement ? $bank : null,
                        null,
                        $payment instanceof Payment ? $payment : null,
                        $allocation,
                        $invoice instanceof Invoice ? $invoice : null,
                        $request instanceof FiscalDocumentRequest ? $request : null,
                        $entry,
                        false,
                        'no_action_needed_financial_entry_uses_economic_date_before_technical_allocation',
                        $this->context($bank instanceof BankStatement ? $bank : null, null, $payment instanceof Payment ? $payment : null, $allocation, $invoice instanceof Invoice ? $invoice : null, $request instanceof FiscalDocumentRequest ? $request : null, 'economic_date_before_technical_allocation', $this->diffDays($entryDate, $sourceDate), $entry),
                    ),
                ];
            }

            return [
                $this->finding(
                    'warning',
                    'financial_entry_before_source',
                    $bank instanceof BankStatement ? $bank : null,
                    null,
                    $payment instanceof Payment ? $payment : null,
                    $allocation instanceof PaymentAllocation ? $allocation : null,
                    $invoice instanceof Invoice ? $invoice : null,
                    $request instanceof FiscalDocumentRequest ? $request : null,
                    $entry,
                    true,
                    'review_financial_entry_before_source',
                    $this->context($bank instanceof BankStatement ? $bank : null, null, $payment instanceof Payment ? $payment : null, $allocation instanceof PaymentAllocation ? $allocation : null, $invoice instanceof Invoice ? $invoice : null, $request instanceof FiscalDocumentRequest ? $request : null, 'financial_entry_before_source', $this->diffDays($entryDate, $sourceDate), $entry),
                ),
            ];
        }

        return [];
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     */
    private function isEconomicDateBeforeTechnicalAllocation(FinancialEntry $entry, ?Payment $payment, PaymentAllocation $allocation, ?Invoice $invoice, ?BankStatement $bank, Collection $allocations, Collection $fiscalRequests): bool
    {
        if (! $invoice instanceof Invoice) {
            return false;
        }

        if (($payment instanceof Payment && ! $this->activePayment($payment)) || ! $this->activeAllocation($allocation)) {
            return false;
        }

        $entryDate = $this->dateValue($entry, 'data');
        $allocationDate = $this->dateValue($allocation, 'allocated_at') ?? $this->dateValue($allocation, 'created_at');
        $allocationCreatedAt = $this->dateValue($allocation, 'created_at');
        $allocationAllocatedAt = $this->dateValue($allocation, 'allocated_at');
        $entryCreatedAt = $this->dateValue($entry, 'created_at');
        $paymentDate = $this->dateValue($payment, 'payment_date');
        $invoiceIssueDate = $this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at');
        $invoiceDueDate = $this->dateValue($invoice, 'data_vencimento');

        if (! $entryDate || ! $allocationDate || ! $entryDate->lt($allocationDate) || ! $entryCreatedAt || ! $allocationCreatedAt) {
            return false;
        }

        if (! $entryCreatedAt->isSameDay($allocationCreatedAt)) {
            return false;
        }

        if ($invoiceIssueDate instanceof Carbon && $invoiceIssueDate->gt($entryDate)) {
            return false;
        }

        if ($invoiceDueDate instanceof Carbon && $invoiceDueDate->gt($allocationDate)) {
            return false;
        }

        if (! $this->monetaryChainIsCoherent($payment, $allocation, $invoice, $allocations)) {
            return false;
        }

        if (! $this->fiscalTimelineIsCoherentForEconomicEntry($invoice, $entryDate, $invoiceIssueDate, $fiscalRequests)) {
            return false;
        }

        return $allocationCreatedAt->gte($allocationDate)
            || ($allocationAllocatedAt instanceof Carbon && $allocationCreatedAt->equalTo($allocationAllocatedAt))
            || $allocationCreatedAt->isSameDay($entryCreatedAt);
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     */
    private function monetaryChainIsCoherent(?Payment $payment, PaymentAllocation $allocation, Invoice $invoice, Collection $allocations): bool
    {
        $allocationAmount = $this->money($allocation->amount);
        $invoicePaidAmount = $this->money($invoice->valor_pago);
        $invoiceTotal = $this->money($invoice->valor_total);
        $invoiceOpen = $this->money($invoice->valor_em_aberto);

        if ($allocationAmount <= 0) {
            return false;
        }

        if ($payment instanceof Payment) {
            $paymentAmount = $this->money($payment->amount);
            $paymentAllocatedAmount = $this->money($payment->allocated_amount);

            if ($paymentAmount <= 0 || $allocationAmount - $paymentAmount > 0.01) {
                return false;
            }

            $paymentAllocationSum = $this->money($allocations
                ->where('payment_id', $payment->id)
                ->filter(fn (PaymentAllocation $candidate): bool => $this->activeAllocation($candidate))
                ->sum(fn (PaymentAllocation $candidate): float => (float) $candidate->amount));

            if ($paymentAllocatedAmount > 0 && abs($paymentAllocationSum - $paymentAllocatedAmount) > 0.01) {
                return false;
            }
        }

        $invoiceAllocationSum = $this->money($allocations
            ->where('invoice_id', $invoice->id)
            ->filter(fn (PaymentAllocation $candidate): bool => $this->activeAllocation($candidate))
            ->sum(fn (PaymentAllocation $candidate): float => (float) $candidate->amount));

        if ($invoicePaidAmount > 0 && abs($invoiceAllocationSum - $invoicePaidAmount) > 0.01) {
            return false;
        }

        if ((string) $invoice->estado_pagamento === 'pago' && (abs($invoicePaidAmount - $invoiceTotal) > 0.01 || abs($invoiceOpen) > 0.01)) {
            return false;
        }

        return true;
    }

    /**
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     */
    private function fiscalTimelineIsCoherentForEconomicEntry(Invoice $invoice, Carbon $entryDate, ?Carbon $invoiceIssueDate, Collection $fiscalRequests): bool
    {
        $receiptIssuedAt = $this->dateValue($invoice, 'recibo_emitido_em');

        if ($receiptIssuedAt instanceof Carbon
            && (($invoiceIssueDate instanceof Carbon && $receiptIssuedAt->lt($invoiceIssueDate)) || $receiptIssuedAt->lt($entryDate))) {
            return false;
        }

        return ! $fiscalRequests
            ->where('invoice_id', $invoice->id)
            ->contains(function (FiscalDocumentRequest $request) use ($entryDate, $invoiceIssueDate): bool {
                $issuedAt = $this->dateValue($request, 'issued_at');

                if (! $issuedAt) {
                    return false;
                }

                return ($invoiceIssueDate instanceof Carbon && $issuedAt->lt($invoiceIssueDate))
                    || $issuedAt->lt($entryDate);
            });
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     */
    private function firstConfirmedPaymentDate(Collection $allocations, Collection $payments): ?Carbon
    {
        return $allocations
            ->map(function (PaymentAllocation $allocation) use ($payments): ?Carbon {
                $payment = $payments->firstWhere('id', $allocation->payment_id);
                $allocationDate = $this->dateValue($allocation, 'allocated_at') ?? $this->dateValue($allocation, 'created_at');
                $paymentDate = $payment instanceof Payment ? ($this->dateValue($payment, 'payment_date') ?? $this->dateValue($payment, 'created_at')) : null;

                return $paymentDate ?? $allocationDate;
            })
            ->filter()
            ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
            ->first();
    }

    private function activePayment(Payment $payment): bool
    {
        return $payment->deleted_at === null && (string) $payment->status !== Payment::STATUS_CANCELLED;
    }

    private function activeAllocation(PaymentAllocation $allocation): bool
    {
        return $allocation->deleted_at === null && (string) $allocation->status === PaymentAllocation::STATUS_CONFIRMED;
    }

    private function legacyInvoice(Invoice $invoice): bool
    {
        return in_array((string) $invoice->origem_tipo, ['monthly_fee_legacy', 'legacy', 'membership_fee_legacy'], true)
            || str_contains((string) $invoice->origem_tipo, 'legacy');
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     */
    private function isHistoricalIncomplete(Invoice $invoice, Collection $payments, Collection $allocations, Collection $fiscalRequests): bool
    {
        $issueDate = $this->dateValue($invoice, 'data_emissao') ?? $this->dateValue($invoice, 'created_at');
        $ageDays = $issueDate ? $this->diffDays($issueDate, Carbon::now()->startOfDay()) : null;

        if ($ageDays === null || $ageDays <= self::HISTORICAL_AFTER_DAYS) {
            return false;
        }

        if (! $this->legacyInvoice($invoice) && ! in_array((string) $invoice->estado_pagamento, ['cancelado', 'pago'], true)) {
            return false;
        }

        $hasIncompleteDates = $payments->whereIn('id', $allocations->where('invoice_id', $invoice->id)->pluck('payment_id')->all())
            ->contains(fn (Payment $payment): bool => $payment->payment_date === null)
            || $allocations->where('invoice_id', $invoice->id)->contains(fn (PaymentAllocation $allocation): bool => $allocation->allocated_at === null)
            || $fiscalRequests->where('invoice_id', $invoice->id)->contains(fn (FiscalDocumentRequest $request): bool => $request->created_at === null);

        return $hasIncompleteDates;
    }

    private function isArchivedStaleFiscalRequest(FiscalDocumentRequest $request): bool
    {
        return (bool) data_get($request->metadata, 'stale_cleanup') === true
            && in_array(data_get($request->metadata, 'stale_cleanup_version'), ['a3-6', 'a4-6'], true)
            && blank($request->external_document_number)
            && blank($request->external_document_id)
            && $request->issued_at === null;
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

    private function diffDays(Carbon $from, Carbon $to): int
    {
        return (int) round($from->diffInDays($to, false));
    }

    /**
     * @param Collection<int,MapaConciliacao> $reconciliations
     */
    private function reconciliationFor(Collection $reconciliations, ?Payment $payment = null, ?PaymentAllocation $allocation = null, ?Invoice $invoice = null): ?MapaConciliacao
    {
        return $reconciliations->first(function (MapaConciliacao $map) use ($payment, $allocation, $invoice): bool {
            return ($payment instanceof Payment && $map->payment_id === $payment->id)
                || ($allocation instanceof PaymentAllocation && $map->payment_allocation_id === $allocation->id)
                || ($invoice instanceof Invoice && $map->fatura_id === $invoice->id);
        });
    }

    /**
     * @param Collection<int,FinancialEntry> $entries
     */
    private function financialEntryFor(Collection $entries, ?Payment $payment = null, ?PaymentAllocation $allocation = null, ?Invoice $invoice = null, ?FiscalDocumentRequest $request = null): ?FinancialEntry
    {
        return $entries->first(function (FinancialEntry $entry) use ($payment, $allocation, $invoice, $request): bool {
            return ($allocation instanceof PaymentAllocation && ($entry->id === $allocation->financial_entry_id || ($entry->origem_tipo === 'payment_allocation' && $entry->origem_id === $allocation->id)))
                || ($payment instanceof Payment && $entry->payment_id === $payment->id)
                || ($invoice instanceof Invoice && $entry->fatura_id === $invoice->id)
                || ($request instanceof FiscalDocumentRequest && $entry->fiscal_document_request_id === $request->id);
        });
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
        ?FiscalDocumentRequest $fiscalRequest,
        ?FinancialEntry $financialEntry,
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
            'invoice_id' => $invoice?->id ? (string) $invoice->id : ($allocation?->invoice_id ? (string) $allocation->invoice_id : ($fiscalRequest?->invoice_id ? (string) $fiscalRequest->invoice_id : null)),
            'fiscal_request_id' => $fiscalRequest?->id ? (string) $fiscalRequest->id : null,
            'financial_entry_id' => $financialEntry?->id ? (string) $financialEntry->id : null,
            'user_id' => $payment?->user_id ? (string) $payment->user_id : ($invoice?->user_id ? (string) $invoice->user_id : ($fiscalRequest?->user_id ? (string) $fiscalRequest->user_id : null)),
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'context' => $context,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function context(
        ?BankStatement $bankStatement,
        ?MapaConciliacao $reconciliation,
        ?Payment $payment,
        ?PaymentAllocation $allocation,
        ?Invoice $invoice,
        ?FiscalDocumentRequest $fiscalRequest,
        string $reason,
        ?int $dateDiffDays = null,
        ?FinancialEntry $financialEntry = null,
    ): array {
        return [
            'bank_date' => $this->dateValue($bankStatement, 'data_movimento')?->toDateString(),
            'reconciliation_date' => $this->dateValue($reconciliation, 'created_at')?->toDateString(),
            'payment_date' => $this->dateValue($payment, 'payment_date')?->toDateString(),
            'allocation_date' => $this->dateValue($allocation, 'allocated_at')?->toDateString(),
            'invoice_issue_date' => $this->dateValue($invoice, 'data_emissao')?->toDateString(),
            'invoice_due_date' => $this->dateValue($invoice, 'data_vencimento')?->toDateString(),
            'fiscal_request_created_at' => $this->dateValue($fiscalRequest, 'created_at')?->toDateString(),
            'fiscal_issued_at' => $this->dateValue($fiscalRequest, 'issued_at')?->toDateString(),
            'receipt_issued_at' => $this->dateValue($invoice, 'recibo_emitido_em')?->toDateString(),
            'financial_entry_date' => $this->dateValue($financialEntry, 'data')?->toDateString(),
            'allocation_created_at' => $this->dateValue($allocation, 'created_at')?->toDateString(),
            'allocation_allocated_at' => $this->dateValue($allocation, 'allocated_at')?->toDateString(),
            'financial_entry_created_at' => $this->dateValue($financialEntry, 'created_at')?->toDateString(),
            'technical_batch_same_day' => ($this->dateValue($allocation, 'created_at') instanceof Carbon && $this->dateValue($financialEntry, 'created_at') instanceof Carbon)
                ? $this->dateValue($allocation, 'created_at')->isSameDay($this->dateValue($financialEntry, 'created_at'))
                : null,
            'cancelled_or_deleted_at' => $this->dateValue($allocation, 'deleted_at')?->toDateString() ?? $this->dateValue($payment, 'cancelled_at')?->toDateString() ?? $this->dateValue($payment, 'deleted_at')?->toDateString(),
            'date_diff_days' => $dateDiffDays,
            'classification_reason' => $reason,
        ];
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
                (string) ($finding['fiscal_request_id'] ?? ''),
                (string) ($finding['financial_entry_id'] ?? ''),
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
            'bank_date_columns' => $this->columns('bank_statements', ['data_movimento', 'created_at', 'updated_at']),
            'reconciliation_date_columns' => [
                'mapa_conciliacao' => $this->columns('mapa_conciliacao', ['created_at', 'updated_at']),
                'bank_transaction_allocations' => $this->columns('bank_transaction_allocations', ['committed_at', 'created_at', 'updated_at']),
            ],
            'payment_date_columns' => $this->columns('payments', ['payment_date', 'cancelled_at', 'created_at', 'updated_at', 'deleted_at']),
            'allocation_date_columns' => $this->columns('payment_allocations', ['allocated_at', 'created_at', 'updated_at', 'deleted_at']),
            'invoice_date_columns' => $this->columns('invoices', ['data_fatura', 'data_emissao', 'data_vencimento', 'data_pagamento', 'recibo_emitido_em', 'created_at', 'updated_at']),
            'fiscal_date_columns' => $this->columns('fiscal_document_requests', ['paid_at', 'due_at', 'issued_at', 'handled_at', 'created_at', 'updated_at', 'deleted_at']),
            'financial_entry_date_columns' => $this->columns('financial_entries', ['data', 'data_pagamento', 'data_liquidacao', 'created_at', 'updated_at']),
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

    /**
     * @param Collection<int,BankStatement> $bankStatements
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Invoice> $invoices
     * @param Collection<int,FiscalDocumentRequest> $fiscalRequests
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $bankStatements, Collection $reconciliations, Collection $bankAllocations, Collection $payments, Collection $allocations, Collection $invoices, Collection $fiscalRequests, array $findings): array
    {
        return [
            'total_timelines_scanned' => max($allocations->count(), $payments->count(), $invoices->count(), $fiscalRequests->count(), $bankStatements->count()),
            'total_bank_timelines' => $bankStatements->count(),
            'total_payment_timelines' => $payments->count(),
            'total_allocation_timelines' => $allocations->count(),
            'total_invoice_timelines' => $invoices->count(),
            'total_fiscal_timelines' => $fiscalRequests->count(),
            'total_reconciliation_timelines' => $reconciliations->count() + $bankAllocations->count(),
            'clean_timeline_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'clean_financial_timeline')),
            'historical_incomplete_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'historical_timeline_incomplete')),
            'economic_date_before_technical_allocation_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['code'] === 'financial_entry_economic_date_before_technical_allocation')),
            'actionable_financial_entry_timeline_count' => count(array_filter($findings, static fn (array $finding): bool => str_starts_with((string) $finding['code'], 'financial_entry_') && (bool) ($finding['actionable'] ?? false))),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'actionable_count' => count(array_filter($findings, static fn (array $finding): bool => (bool) ($finding['actionable'] ?? false))),
            'payments_with_findings' => collect($findings)->pluck('payment_id')->filter()->unique()->count(),
            'allocations_with_findings' => collect($findings)->pluck('allocation_id')->filter()->unique()->count(),
            'invoices_with_findings' => collect($findings)->pluck('invoice_id')->filter()->unique()->count(),
            'fiscal_requests_with_findings' => collect($findings)->pluck('fiscal_request_id')->filter()->unique()->count(),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
