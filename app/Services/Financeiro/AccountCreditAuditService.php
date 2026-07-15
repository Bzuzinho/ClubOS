<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class AccountCreditAuditService
{
    private const VERSION = 'a4-2-account-credit-audit-v1';
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $credits = $this->credits($filters);
        $payments = $this->payments($credits);
        $allocations = $this->allocations($payments);
        $financialEntries = $this->financialEntries($credits);

        $findings = [];

        if ($credits->isEmpty()) {
            $findings[] = $this->finding(
                severity: 'info',
                code: 'account_credit_no_records',
                credit: null,
                amount: 0,
                availableBalance: 0,
                usedAmount: 0,
                status: 'none',
                recommendation: 'no_action_needed_no_account_credit_records',
            );
        }

        foreach ($credits as $credit) {
            array_push(
                $findings,
                ...$this->creditFindings(
                    credit: $credit,
                    payments: $payments,
                    allocations: $allocations,
                    financialEntries: $financialEntries,
                ),
            );
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'schema_detected' => $this->schemaDetected(),
            'detected_models' => $this->detectedModels(),
            'summary' => $this->summary($credits, $findings),
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
            'credit' => $this->normalizeNullableString($options['credit'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'payment' => $this->normalizeNullableString($options['payment'] ?? null),
            'include_deleted' => (bool) ($options['include_deleted'] ?? false),
            'only_open' => (bool) ($options['only_open'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,AccountCredit>
     */
    private function credits(array $filters): Collection
    {
        $query = AccountCredit::withTrashed()
            ->orderBy('created_at')
            ->orderBy('id');

        if (! $filters['include_deleted']) {
            $query->whereNull('deleted_at');
        }

        if ($filters['credit']) {
            $query->whereKey($filters['credit']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['payment']) {
            $query->where('payment_id', $filters['payment']);
        }

        if ($filters['invoice']) {
            $paymentIds = PaymentAllocation::withTrashed()
                ->where('invoice_id', $filters['invoice'])
                ->pluck('payment_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $query->whereIn('payment_id', $paymentIds);
        }

        if ($filters['from']) {
            $query->whereDate('created_at', '>=', Carbon::parse((string) $filters['from'])->toDateString());
        }

        if ($filters['to']) {
            $query->whereDate('created_at', '<=', Carbon::parse((string) $filters['to'])->toDateString());
        }

        if ($filters['only_open']) {
            $query->where('remaining_amount', '>', self::TOLERANCE)
                ->whereIn('status', [AccountCredit::STATUS_AVAILABLE, AccountCredit::STATUS_PARTIALLY_USED]);
        }

        return $query->get();
    }

    /**
     * @param Collection<int,AccountCredit> $credits
     * @return Collection<string,Payment>
     */
    private function payments(Collection $credits): Collection
    {
        $paymentIds = $credits->pluck('payment_id')->filter()->unique()->values();

        if ($paymentIds->isEmpty()) {
            return collect();
        }

        return Payment::withTrashed()
            ->whereIn('id', $paymentIds->all())
            ->get()
            ->keyBy('id');
    }

    /**
     * @param Collection<string,Payment> $payments
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(Collection $payments): Collection
    {
        if ($payments->isEmpty()) {
            return collect();
        }

        return PaymentAllocation::withTrashed()
            ->whereIn('payment_id', $payments->keys()->all())
            ->orderBy('allocated_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,AccountCredit> $credits
     * @return Collection<int,FinancialEntry>
     */
    private function financialEntries(Collection $credits): Collection
    {
        $creditIds = $credits->pluck('id')->filter()->unique()->values();

        if ($creditIds->isEmpty()) {
            return collect();
        }

        return FinancialEntry::query()
            ->where('origem_tipo', 'account_credit')
            ->whereIn('origem_id', $creditIds->all())
            ->orderBy('data')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<string,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,FinancialEntry> $financialEntries
     * @return list<array<string,mixed>>
     */
    private function creditFindings(AccountCredit $credit, Collection $payments, Collection $allocations, Collection $financialEntries): array
    {
        $findings = [];
        $amount = $this->money($credit->amount);
        $availableBalance = $this->money($credit->remaining_amount);
        $usedAmount = $this->money($amount - $availableBalance);
        $status = (string) $credit->status;
        $payment = $credit->payment_id ? $payments->get($credit->payment_id) : null;
        $creditEntries = $financialEntries->where('origem_id', $credit->id);

        if ($amount < -self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'account_credit_negative_amount', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_original_amount');
        }

        if ($availableBalance < -self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'account_credit_negative_balance', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_available_balance');
        }

        if ($availableBalance - $amount > self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'account_credit_balance_exceeds_amount', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_balance_formula');
        }

        if ($usedAmount - $amount > self::TOLERANCE) {
            $findings[] = $this->finding('critical', 'account_credit_used_amount_exceeds_amount', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_used_amount');
        }

        if ($status === AccountCredit::STATUS_AVAILABLE && $availableBalance <= self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'account_credit_status_balance_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_available_credit_without_available_balance');
        }

        if ($status === AccountCredit::STATUS_PARTIALLY_USED && ($availableBalance <= self::TOLERANCE || $availableBalance - $amount >= -self::TOLERANCE)) {
            $findings[] = $this->finding('warning', 'account_credit_status_balance_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_partially_used_credit_balance');
        }

        if ($status === AccountCredit::STATUS_USED && $availableBalance > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'account_credit_status_balance_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_used_credit_with_available_balance');
        }

        if ($status === AccountCredit::STATUS_CANCELLED && $availableBalance > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'account_credit_cancelled_with_available_balance', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_cancelled_credit_available_balance');
        }

        if ($this->requiresPaymentOrigin($credit) && blank($credit->payment_id)) {
            $findings[] = $this->finding('warning', 'account_credit_origin_missing', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_origin_payment');
        }

        if (filled($credit->payment_id) && ! $payment instanceof Payment) {
            $findings[] = $this->finding('critical', 'account_credit_origin_not_found', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_payment_reference');
        }

        if ($payment instanceof Payment) {
            array_push(
                $findings,
                ...$this->paymentOriginFindings($credit, $payment, $allocations, $amount, $availableBalance, $usedAmount, $status),
            );
        }

        if ($status !== AccountCredit::STATUS_CANCELLED && $credit->deleted_at === null) {
            if ($creditEntries->isEmpty()) {
                $findings[] = $this->finding('warning', 'account_credit_open_without_usage_trace', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_financial_entry_trace');
            } elseif ($creditEntries->count() > 1) {
                $findings[] = $this->finding('warning', 'account_credit_duplicate_usage', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_duplicate_account_credit_financial_entries', [
                    'financial_entry_ids' => $creditEntries->pluck('id')->values()->all(),
                ]);
            } else {
                $entry = $creditEntries->first();
                if ($entry instanceof FinancialEntry && abs($this->money($entry->valor) - $amount) > self::TOLERANCE) {
                    $findings[] = $this->finding('warning', 'account_credit_balance_differs_from_usages', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_financial_entry_amount', [
                        'financial_entry_id' => (string) $entry->id,
                        'financial_entry_amount' => $this->money($entry->valor),
                    ]);
                }
            }
        }

        if ($usedAmount > self::TOLERANCE && ! $this->schemaDetected()['usage_tables_detected']) {
            $findings[] = $this->finding('info', 'account_credit_open_without_usage_trace', $credit, $amount, $availableBalance, $usedAmount, $status, 'no_action_possible_account_credit_usage_table_not_present');
        }

        return $findings;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return list<array<string,mixed>>
     */
    private function paymentOriginFindings(AccountCredit $credit, Payment $payment, Collection $allocations, float $amount, float $availableBalance, float $usedAmount, string $status): array
    {
        $findings = [];
        $activeCredit = $status !== AccountCredit::STATUS_CANCELLED && $credit->deleted_at === null;

        if ($payment->deleted_at !== null) {
            $findings[] = $this->finding('warning', 'account_credit_origin_soft_deleted', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_soft_deleted_payment_origin', [
                'payment_id' => (string) $payment->id,
            ]);
        }

        if ($activeCredit && $payment->status === Payment::STATUS_CANCELLED) {
            $findings[] = $this->finding('warning', 'account_credit_payment_origin_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_active_credit_from_cancelled_payment', [
                'payment_status' => $payment->status,
            ]);
        }

        if ($credit->user_id !== null && $payment->user_id !== null && $credit->user_id !== $payment->user_id) {
            $findings[] = $this->finding('warning', 'account_credit_payment_origin_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_user_against_payment_user', [
                'payment_user_id' => (string) $payment->user_id,
            ]);
        }

        if ($credit->family_id !== null && $payment->family_id !== null && $credit->family_id !== $payment->family_id) {
            $findings[] = $this->finding('warning', 'account_credit_payment_origin_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_account_credit_family_against_payment_family', [
                'payment_family_id' => (string) $payment->family_id,
            ]);
        }

        $confirmedAllocationSum = $this->money($allocations
            ->where('payment_id', $payment->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount));
        $activeCreditSum = $this->money(AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->whereNull('deleted_at')
            ->sum('amount'));
        $expectedUnallocated = $this->money((float) $payment->amount - $confirmedAllocationSum - $activeCreditSum);
        $paymentUnallocated = $this->money($payment->unallocated_amount);

        if (abs($paymentUnallocated - $expectedUnallocated) > self::TOLERANCE) {
            $findings[] = $this->finding('warning', 'account_credit_payment_unallocated_mismatch', $credit, $amount, $availableBalance, $usedAmount, $status, 'review_payment_unallocated_amount_against_account_credits', [
                'payment_id' => (string) $payment->id,
                'payment_amount' => $this->money($payment->amount),
                'payment_unallocated_amount' => $paymentUnallocated,
                'confirmed_allocation_sum' => $confirmedAllocationSum,
                'active_credit_sum' => $activeCreditSum,
                'expected_unallocated_amount' => $expectedUnallocated,
            ]);
        }

        return $findings;
    }

    private function requiresPaymentOrigin(AccountCredit $credit): bool
    {
        return in_array((string) $credit->source, ['', 'overpayment', 'bank_statement', 'payment'], true);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $credits, array $findings): array
    {
        return [
            'total_account_credits_scanned' => $credits->count(),
            'total_credit_amount_scanned' => $this->money($credits->sum(fn (AccountCredit $credit): float => (float) $credit->amount)),
            'total_available_balance_scanned' => $this->money($credits->sum(fn (AccountCredit $credit): float => (float) $credit->remaining_amount)),
            'total_used_amount_scanned' => $this->money($credits->sum(fn (AccountCredit $credit): float => (float) $credit->amount - (float) $credit->remaining_amount)),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'credits_with_findings' => collect($findings)->pluck('account_credit_id')->filter()->unique()->count(),
            'users_with_findings' => collect($findings)->pluck('user_id')->filter()->unique()->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaDetected(): array
    {
        $usageTables = collect([
            'account_credit_usages',
            'account_credit_applications',
            'credit_usages',
            'credit_applications',
        ])->filter(fn (string $table): bool => Schema::hasTable($table))->values()->all();

        return [
            'account_credits_table' => Schema::hasTable('account_credits'),
            'account_credits_columns' => Schema::hasTable('account_credits')
                ? Schema::getColumnListing('account_credits')
                : [],
            'soft_deletes_supported' => Schema::hasColumn('account_credits', 'deleted_at'),
            'payment_origin_supported' => Schema::hasColumn('account_credits', 'payment_id'),
            'source_supported' => Schema::hasColumn('account_credits', 'source'),
            'usage_tables_detected' => $usageTables !== [],
            'usage_tables' => $usageTables,
            'financial_entry_origin_supported' => Schema::hasColumn('financial_entries', 'origem_tipo')
                && Schema::hasColumn('financial_entries', 'origem_id'),
            'refund_reversal_entities_detected' => $this->refundReversalEntitiesDetected(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function detectedModels(): array
    {
        return [
            'account_credit' => Schema::hasTable('account_credits') && class_exists(AccountCredit::class),
            'payment' => Schema::hasTable('payments') && class_exists(Payment::class),
            'payment_allocation' => Schema::hasTable('payment_allocations') && class_exists(PaymentAllocation::class),
            'financial_entry' => Schema::hasTable('financial_entries') && class_exists(FinancialEntry::class),
            'refund_reversal_entities_detected' => $this->refundReversalEntitiesDetected(),
        ];
    }

    /**
     * @return list<string>
     */
    private function refundReversalEntitiesDetected(): array
    {
        return collect([
            'Refund' => class_exists('App\\Models\\Refund'),
            'Reversal' => class_exists('App\\Models\\Reversal'),
            'PaymentReversal' => class_exists('App\\Models\\PaymentReversal'),
            'CreditNote' => class_exists('App\\Models\\CreditNote'),
        ])->filter()->keys()->values()->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $code, ?AccountCredit $credit, float $amount, float $availableBalance, float $usedAmount, string $status, string $recommendation, array $context = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'account_credit_id' => $credit?->id ? (string) $credit->id : null,
            'user_id' => $credit?->user_id ? (string) $credit->user_id : null,
            'invoice_id' => null,
            'payment_id' => $credit?->payment_id ? (string) $credit->payment_id : null,
            'amount' => $this->money($amount),
            'available_balance' => $this->money($availableBalance),
            'used_amount' => $this->money($usedAmount),
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
