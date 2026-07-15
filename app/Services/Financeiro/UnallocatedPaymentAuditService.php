<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class UnallocatedPaymentAuditService
{
    private const VERSION = 'a4-4-unallocated-payment-audit-v1';
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $payments = $this->payments($filters);
        $allocations = $this->allocations($payments);
        $credits = $this->credits($payments);
        $openInvoicesByOwner = $this->openInvoicesByOwner($payments);

        $items = [];
        $findings = [];

        foreach ($payments as $payment) {
            $item = $this->item($payment, $allocations, $credits, $openInvoicesByOwner);

            if (! $this->isInScope($item)) {
                continue;
            }

            if ($filters['only_actionable'] && ! (bool) $item['actionable']) {
                continue;
            }

            $items[] = $item;
            $findings[] = $this->findingFromItem($item);
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $this->summary($payments, $items, $findings),
            'items' => $items,
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
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
            'include_cancelled' => (bool) ($options['include_cancelled'] ?? false),
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

        if (! $filters['include_cancelled']) {
            $query->where('status', '!=', Payment::STATUS_CANCELLED);
        }

        if ($filters['payment']) {
            $query->whereKey($filters['payment']);
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
     * @param Collection<int,Payment> $payments
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(Collection $payments): Collection
    {
        if ($payments->isEmpty()) {
            return collect();
        }

        return PaymentAllocation::withTrashed()
            ->whereIn('payment_id', $payments->pluck('id')->all())
            ->orderBy('allocated_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
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
     * @return array<string,float>
     */
    private function openInvoicesByOwner(Collection $payments): array
    {
        $userIds = $payments->pluck('user_id')->filter()->unique()->values();
        $familyIds = $payments->pluck('family_id')->filter()->unique()->values();

        if ($userIds->isEmpty() && $familyIds->isEmpty()) {
            return [];
        }

        $query = Invoice::query()
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->where('oculta', false)
            ->where('valor_em_aberto', '>', self::TOLERANCE)
            ->where(function (Builder $query) use ($userIds, $familyIds): void {
                if ($userIds->isNotEmpty()) {
                    $query->whereIn('user_id', $userIds->all());
                }

                if ($familyIds->isNotEmpty()) {
                    $query->orWhereHas('user.families', function (Builder $familyQuery) use ($familyIds): void {
                        $familyQuery->whereIn('familias.id', $familyIds->all());
                    });
                }
            });

        $result = [];

        foreach ($query->get() as $invoice) {
            if ($invoice->user_id) {
                $key = 'user:' . (string) $invoice->user_id;
                $result[$key] = $this->money(($result[$key] ?? 0) + (float) $invoice->valor_em_aberto);
            }

            $families = $invoice->user?->families ?? collect();
            foreach ($families as $family) {
                $key = 'family:' . (string) $family->id;
                $result[$key] = $this->money(($result[$key] ?? 0) + (float) $invoice->valor_em_aberto);
            }
        }

        return $result;
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,AccountCredit> $credits
     * @param array<string,float> $openInvoicesByOwner
     * @return array<string,mixed>
     */
    private function item(Payment $payment, Collection $allocations, Collection $credits, array $openInvoicesByOwner): array
    {
        $paymentAllocations = $allocations->where('payment_id', $payment->id);
        $confirmedAllocations = $paymentAllocations
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at');
        $activeCredits = $credits
            ->where('payment_id', $payment->id)
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->whereNull('deleted_at');

        $amount = $this->money($payment->amount);
        $allocatedAmount = $this->money($payment->allocated_amount);
        $unallocatedAmount = $this->money($payment->unallocated_amount);
        $confirmedAllocated = $this->money($confirmedAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount));
        $creditAmount = $this->money($activeCredits->sum(fn (AccountCredit $credit): float => (float) $credit->amount));
        $creditRemaining = $this->money($activeCredits->sum(fn (AccountCredit $credit): float => (float) $credit->remaining_amount));
        $grossUnallocated = $this->money($amount - $confirmedAllocated);
        $computedUnallocated = $this->money(max($amount - $confirmedAllocated - $creditAmount, 0));
        $openInvoiceAmount = $this->ownerOpenInvoiceAmount($payment, $openInvoicesByOwner);

        [$classification, $severity, $actionable, $recommendation, $context] = $this->classify(
            payment: $payment,
            amount: $amount,
            allocatedAmount: $allocatedAmount,
            unallocatedAmount: $unallocatedAmount,
            confirmedAllocated: $confirmedAllocated,
            creditAmount: $creditAmount,
            creditRemaining: $creditRemaining,
            grossUnallocated: $grossUnallocated,
            computedUnallocated: $computedUnallocated,
            openInvoiceAmount: $openInvoiceAmount,
            activeAllocationCount: $confirmedAllocations->count(),
        );

        return [
            'payment_id' => (string) $payment->id,
            'user_id' => $payment->user_id ? (string) $payment->user_id : null,
            'family_id' => $payment->family_id ? (string) $payment->family_id : null,
            'status' => (string) $payment->status,
            'source' => (string) $payment->source,
            'method' => $payment->method ? (string) $payment->method : null,
            'payment_date' => $payment->payment_date?->toDateString(),
            'amount' => $amount,
            'allocated_amount' => $allocatedAmount,
            'unallocated_amount' => $unallocatedAmount,
            'computed_confirmed_allocated_amount' => $confirmedAllocated,
            'computed_unallocated_amount' => $computedUnallocated,
            'existing_account_credit_amount' => $creditAmount,
            'existing_account_credit_remaining_amount' => $creditRemaining,
            'open_invoice_amount_for_owner' => $openInvoiceAmount,
            'classification' => $classification,
            'balance_classification' => (string) ($context['balance_classification'] ?? $classification),
            'severity' => $severity,
            'actionable' => $actionable,
            'recommendation' => $recommendation,
            'context' => $context,
        ];
    }

    /**
     * @return array{0:string,1:string,2:bool,3:string,4:array<string,mixed>}
     */
    private function classify(
        Payment $payment,
        float $amount,
        float $allocatedAmount,
        float $unallocatedAmount,
        float $confirmedAllocated,
        float $creditAmount,
        float $creditRemaining,
        float $grossUnallocated,
        float $computedUnallocated,
        float $openInvoiceAmount,
        int $activeAllocationCount,
    ): array {
        $context = [
            'gross_unallocated_before_credit' => $grossUnallocated,
            'active_confirmed_allocation_count' => $activeAllocationCount,
        ];

        if ($payment->status === Payment::STATUS_CANCELLED && $activeAllocationCount === 0) {
            return [
                'cancelled_stale_unallocated_payment',
                'info',
                false,
                'no_action_needed_cancelled_unallocated_payment',
                $context,
            ];
        }

        if (abs($allocatedAmount - $confirmedAllocated) > self::TOLERANCE) {
            return [
                'payment_allocated_amount_inconsistent',
                $allocatedAmount - $amount > self::TOLERANCE ? 'critical' : 'warning',
                true,
                'review_payment_allocated_amount_against_confirmed_allocations',
                array_merge($context, [
                    'expected_allocated_amount' => $confirmedAllocated,
                ]),
            ];
        }

        if (abs($unallocatedAmount - $computedUnallocated) > self::TOLERANCE) {
            return [
                'payment_unallocated_amount_inconsistent',
                $unallocatedAmount - $amount > self::TOLERANCE ? 'critical' : 'warning',
                true,
                'review_payment_unallocated_amount_against_allocations_and_credits',
                array_merge($context, [
                    'expected_unallocated_amount' => $computedUnallocated,
                    'active_account_credit_amount' => $creditAmount,
                ]),
            ];
        }

        if ($grossUnallocated <= self::TOLERANCE && $unallocatedAmount <= self::TOLERANCE) {
            return [
                'no_unallocated_balance',
                'info',
                false,
                'no_action_needed_payment_fully_allocated',
                $context,
            ];
        }

        if ($payment->status !== Payment::STATUS_CONFIRMED) {
            return [
                'non_confirmed_unallocated_payment',
                'info',
                false,
                'no_action_needed_non_confirmed_unallocated_payment',
                $context,
            ];
        }

        if ($creditAmount > self::TOLERANCE) {
            $expectedCreditRemaining = $this->money(max($creditAmount - ($grossUnallocated - $computedUnallocated), 0));
            $creditDiverges = $creditRemaining - $creditAmount > self::TOLERANCE || $creditRemaining < -self::TOLERANCE;

            return [
                'confirmed_unallocated_payment_with_credit',
                $creditDiverges ? 'warning' : 'info',
                $creditDiverges,
                $creditDiverges ? 'review_account_credit_remaining_amount_against_payment' : 'no_action_needed_existing_account_credit_covers_unallocated_payment',
                array_merge($context, [
                    'expected_credit_remaining_upper_bound' => $expectedCreditRemaining,
                ]),
            ];
        }

        if (blank($payment->user_id) && blank($payment->family_id)) {
            return [
                'unallocated_payment_without_user_or_family',
                'warning',
                true,
                'review_payment_owner_before_credit_creation',
                array_merge($context, [
                    'balance_classification' => 'confirmed_unallocated_payment_without_credit',
                ]),
            ];
        }

        if ($openInvoiceAmount > self::TOLERANCE) {
            return [
                'unallocated_payment_has_open_invoices',
                'warning',
                true,
                'review_allocate_payment_before_creating_credit',
                array_merge($context, [
                    'balance_classification' => 'confirmed_unallocated_payment_without_credit',
                ]),
            ];
        }

        return [
            'unallocated_payment_candidate_for_account_credit',
            'warning',
            true,
            'candidate_create_account_credit_from_payment',
            array_merge($context, [
                'balance_classification' => 'confirmed_unallocated_payment_without_credit',
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function isInScope(array $item): bool
    {
        return (float) $item['unallocated_amount'] > self::TOLERANCE
            || (float) data_get($item, 'context.gross_unallocated_before_credit', 0) > self::TOLERANCE
            || in_array($item['classification'], [
                'payment_unallocated_amount_inconsistent',
                'payment_allocated_amount_inconsistent',
            ], true);
    }

    /**
     * @param array<string,float> $openInvoicesByOwner
     */
    private function ownerOpenInvoiceAmount(Payment $payment, array $openInvoicesByOwner): float
    {
        $amount = 0.0;

        if ($payment->user_id) {
            $amount += $openInvoicesByOwner['user:' . (string) $payment->user_id] ?? 0;
        }

        if ($payment->family_id) {
            $amount += $openInvoicesByOwner['family:' . (string) $payment->family_id] ?? 0;
        }

        return $this->money($amount);
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $payments, array $items, array $findings): array
    {
        return [
            'total_payments_scanned' => $payments->count(),
            'total_unallocated_payments' => count($items),
            'total_unallocated_amount' => $this->money(collect($items)->sum(fn (array $item): float => (float) $item['unallocated_amount'])),
            'cancelled_stale_count' => collect($items)->where('classification', 'cancelled_stale_unallocated_payment')->count(),
            'confirmed_unallocated_count' => collect($items)
                ->where('status', Payment::STATUS_CONFIRMED)
                ->filter(fn (array $item): bool => (float) $item['unallocated_amount'] > self::TOLERANCE || (float) data_get($item, 'context.gross_unallocated_before_credit', 0) > self::TOLERANCE)
                ->count(),
            'with_existing_credit_count' => collect($items)->where('classification', 'confirmed_unallocated_payment_with_credit')->count(),
            'candidate_account_credit_count' => collect($items)->where('classification', 'unallocated_payment_candidate_for_account_credit')->count(),
            'open_invoice_allocation_candidate_count' => collect($items)->where('classification', 'unallocated_payment_has_open_invoices')->count(),
            'ownership_review_count' => collect($items)->where('classification', 'unallocated_payment_without_user_or_family')->count(),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'actionable_count' => count(array_filter($findings, static fn (array $finding): bool => (bool) $finding['actionable'])),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function findingFromItem(array $item): array
    {
        return [
            'severity' => $item['severity'],
            'code' => $item['classification'],
            'balance_code' => $item['balance_classification'],
            'payment_id' => $item['payment_id'],
            'user_id' => $item['user_id'],
            'family_id' => $item['family_id'],
            'amount' => $item['amount'],
            'unallocated_amount' => $item['unallocated_amount'],
            'actionable' => $item['actionable'],
            'recommendation' => $item['recommendation'],
            'context' => $item['context'],
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
