<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyConsistencyService
{
    private const ACTIVE_FISCAL_STATUSES = [
        FiscalDocumentRequest::STATUS_PENDING,
        FiscalDocumentRequest::STATUS_IN_PROGRESS,
        FiscalDocumentRequest::STATUS_ISSUED,
    ];

    public function __construct(
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
    ) {
    }

    public function audit(): array
    {
        $invoices = $this->loadInvoicesWithConfirmedAllocations();

        $invoiceStateMismatches = [];
        $paidInvoicesWithoutActiveFiscalRequest = [];
        $softDeletedFiscalRequests = [];

        foreach ($invoices as $invoice) {
            $snapshot = $this->buildInvoiceSnapshot($invoice);

            if ($this->invoiceStateIsInconsistent($invoice, $snapshot)) {
                $invoiceStateMismatches[] = $this->formatInvoiceMismatch($invoice, $snapshot);
            }

            if ($snapshot['status'] === 'pago' && ! $snapshot['has_active_fiscal_request']) {
                $paidInvoicesWithoutActiveFiscalRequest[] = $this->formatPaidInvoiceWithoutFiscalRequest($invoice, $snapshot);
            }

            if ($snapshot['soft_deleted_fiscal_requests_count'] > 0) {
                $softDeletedFiscalRequests[] = $this->formatSoftDeletedFiscalRequestFinding($invoice, $snapshot);
            }
        }

        $paymentAudit = $this->buildPaymentAudit();

        return [
            'invoice_state_mismatches' => $invoiceStateMismatches,
            'paid_invoices_without_active_fiscal_request' => $paidInvoicesWithoutActiveFiscalRequest,
            'soft_deleted_fiscal_requests_with_confirmed_allocations' => $softDeletedFiscalRequests,
            'orphan_reconciliation_payments' => $paymentAudit['orphan_reconciliation_payments'],
            'orphan_manual_payments' => $paymentAudit['orphan_manual_payments'],
            'payments_with_allocated_amount_mismatch' => $paymentAudit['payments_with_allocated_amount_mismatch'],
            'payments_without_account_credit' => array_merge(
                $paymentAudit['orphan_reconciliation_payments'],
                $paymentAudit['orphan_manual_payments']
            ),
            'payments_without_confirmed_allocations' => array_values(array_filter(
                array_merge(
                    $paymentAudit['orphan_reconciliation_payments'],
                    $paymentAudit['orphan_manual_payments'],
                    $paymentAudit['payments_with_allocated_amount_mismatch']
                ),
                fn (array $row) => ($row['confirmed_allocations_count'] ?? 0) === 0
            )),
            'summary' => [
                'invoice_state_mismatches' => count($invoiceStateMismatches),
                'paid_invoices_without_active_fiscal_request' => count($paidInvoicesWithoutActiveFiscalRequest),
                'soft_deleted_fiscal_requests_with_confirmed_allocations' => count($softDeletedFiscalRequests),
                'orphan_reconciliation_payments' => count($paymentAudit['orphan_reconciliation_payments']),
                'orphan_manual_payments' => count($paymentAudit['orphan_manual_payments']),
                'payments_with_allocated_amount_mismatch' => count($paymentAudit['payments_with_allocated_amount_mismatch']),
                'payments_without_account_credit' => count($paymentAudit['orphan_reconciliation_payments'])
                    + count($paymentAudit['orphan_manual_payments']),
                'payments_without_confirmed_allocations' => count(array_filter(
                    array_merge(
                        $paymentAudit['orphan_reconciliation_payments'],
                        $paymentAudit['orphan_manual_payments'],
                        $paymentAudit['payments_with_allocated_amount_mismatch']
                    ),
                    fn (array $row) => ($row['confirmed_allocations_count'] ?? 0) === 0
                )),
            ],
        ];
    }

    public function repair(bool $commit = false): array
    {
        $invoices = $this->loadInvoicesWithConfirmedAllocations();
        $plans = [];

        foreach ($invoices as $invoice) {
            $snapshot = $this->buildInvoiceSnapshot($invoice);
            $plan = $this->buildInvoiceRepairPlan($invoice, $snapshot);

            if (! $plan['requires_action']) {
                continue;
            }

            $plans[] = $commit
                ? $this->executeInvoiceRepairPlan($invoice, $plan)
                : $plan;
        }

        return [
            'dry_run' => ! $commit,
            'invoices' => $plans,
            'summary' => [
                'planned' => count($plans),
                'eligible' => count(array_filter($plans, fn (array $plan) => $plan['eligible'])),
                'skipped' => count(array_filter($plans, fn (array $plan) => ! $plan['eligible'])),
                'committed' => $commit
                    ? count(array_filter($plans, fn (array $plan) => ($plan['executed'] ?? false) === true))
                    : 0,
            ],
            'audit' => $this->audit(),
        ];
    }

    public function repairPayments(bool $commit = false, bool $cancelManualOrphans = false): array
    {
        $plans = $this->buildPaymentRepairPlans($cancelManualOrphans);

        if ($commit) {
            $plans = array_map(fn (array $plan) => $this->executePaymentRepairPlan($plan), $plans);
        }

        return [
            'dry_run' => ! $commit,
            'cancel_manual_orphans' => $cancelManualOrphans,
            'payments' => $plans,
            'summary' => [
                'planned' => count($plans),
                'eligible' => count(array_filter($plans, fn (array $plan) => $plan['eligible'])),
                'skipped' => count(array_filter($plans, fn (array $plan) => ! $plan['eligible'])),
                'committed' => $commit
                    ? count(array_filter($plans, fn (array $plan) => ($plan['executed'] ?? false) === true))
                    : 0,
                'cancelled' => $commit
                    ? count(array_filter($plans, fn (array $plan) => array_key_exists('cancel_payment', $plan['changes']) && ($plan['executed'] ?? false) === true))
                    : 0,
                'rebalanced' => $commit
                    ? count(array_filter($plans, fn (array $plan) => array_key_exists('allocated_amount', $plan['changes']) && ($plan['executed'] ?? false) === true))
                    : 0,
            ],
            'audit' => $this->audit(),
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function loadInvoicesWithConfirmedAllocations(): Collection
    {
        return Invoice::query()
            ->whereHas('paymentAllocations', function ($query): void {
                $query->confirmed();
            })
            ->with([
                'user',
                'items',
                'paymentAllocations' => function ($query): void {
                    $query->confirmed()
                        ->with('payment')
                        ->orderByDesc('allocated_at')
                        ->orderByDesc('created_at');
                },
                'fiscalDocumentRequests' => function ($query): void {
                    $query->withTrashed()->orderByDesc('created_at');
                },
            ])
            ->orderBy('data_vencimento')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function loadPaymentsForAudit(): Collection
    {
        return Payment::query()
            ->confirmed()
            ->with([
                'bankStatement',
                'allocations' => function ($query): void {
                    $query->confirmed();
                },
                'credits' => function ($query): void {
                    $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                },
            ])
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->get();
    }

    private function buildInvoiceSnapshot(Invoice $invoice): array
    {
        $allocations = $invoice->paymentAllocations
            ->filter(fn (PaymentAllocation $allocation) => $allocation->status === PaymentAllocation::STATUS_CONFIRMED)
            ->values();

        $paidAmount = round((float) $allocations->sum(fn (PaymentAllocation $allocation) => (float) $allocation->amount), 2);
        $totalAmount = round((float) $invoice->valor_total, 2);
        $outstandingAmount = round(max($totalAmount - $paidAmount, 0), 2);

        $latestAllocation = $allocations
            ->first(fn (PaymentAllocation $allocation) => $allocation->payment?->status === Payment::STATUS_CONFIRMED)
            ?? $allocations->first();

        $latestPayment = $latestAllocation?->payment;

        $status = 'pendente';
        if ($invoice->estado_pagamento === 'cancelado') {
            $status = 'cancelado';
        } elseif ($outstandingAmount <= 0.009) {
            $status = 'pago';
        } elseif ($paidAmount > 0) {
            $status = 'parcial';
        } elseif ($invoice->data_vencimento && $invoice->data_vencimento->isPast()) {
            $status = 'vencido';
        }

        $activeFiscalRequest = $invoice->fiscalDocumentRequests
            ->first(fn (FiscalDocumentRequest $request) => ! $request->trashed()
                && $request->provider === FiscalDocumentRequest::PROVIDER_WINTOUCH
                && in_array($request->status, self::ACTIVE_FISCAL_STATUSES, true));

        $softDeletedFiscalRequestsCount = $invoice->fiscalDocumentRequests
            ->filter(fn (FiscalDocumentRequest $request) => $request->trashed())
            ->count();

        $paymentDate = $status === 'pago'
            ? ($latestPayment?->payment_date?->toDateString()
                ?? optional($latestAllocation?->allocated_at)?->toDateString()
                ?? now()->toDateString())
            : null;

        return [
            'paid_amount' => $paidAmount,
            'outstanding_amount' => $outstandingAmount,
            'status' => $status,
            'payment_date' => $paymentDate,
            'payment_method' => $latestPayment?->method,
            'latest_payment_id' => $latestPayment?->id,
            'latest_payment_reference' => $latestPayment?->reference,
            'allocation_count' => $allocations->count(),
            'has_active_fiscal_request' => $activeFiscalRequest !== null,
            'active_fiscal_request_id' => $activeFiscalRequest?->id,
            'soft_deleted_fiscal_requests_count' => $softDeletedFiscalRequestsCount,
        ];
    }

    private function buildPaymentAudit(): array
    {
        $orphanReconciliationPayments = [];
        $orphanManualPayments = [];
        $paymentsWithAllocatedAmountMismatch = [];

        foreach ($this->loadPaymentsForAudit() as $payment) {
            $snapshot = $this->buildPaymentSnapshot($payment);

            if ($snapshot['is_orphan_reconciliation']) {
                $orphanReconciliationPayments[] = $this->formatPaymentFinding($payment, $snapshot);
            }

            if ($snapshot['is_orphan_manual']) {
                $orphanManualPayments[] = $this->formatPaymentFinding($payment, $snapshot);
            }

            if ($snapshot['has_balance_mismatch']) {
                $paymentsWithAllocatedAmountMismatch[] = $this->formatPaymentFinding($payment, $snapshot);
            }
        }

        return [
            'orphan_reconciliation_payments' => $orphanReconciliationPayments,
            'orphan_manual_payments' => $orphanManualPayments,
            'payments_with_allocated_amount_mismatch' => $paymentsWithAllocatedAmountMismatch,
        ];
    }

    private function invoiceStateIsInconsistent(Invoice $invoice, array $snapshot): bool
    {
        return ! $this->amountEquals($invoice->valor_pago, $snapshot['paid_amount'])
            || ! $this->amountEquals($invoice->valor_em_aberto, $snapshot['outstanding_amount'])
            || (string) $invoice->estado_pagamento !== $snapshot['status'];
    }

    private function buildInvoiceRepairPlan(Invoice $invoice, array $snapshot): array
    {
        $changes = [];

        if (! $this->amountEquals($invoice->valor_pago, $snapshot['paid_amount'])) {
            $changes['valor_pago'] = [
                'from' => round((float) ($invoice->valor_pago ?? 0), 2),
                'to' => $snapshot['paid_amount'],
            ];
        }

        if (! $this->amountEquals($invoice->valor_em_aberto, $snapshot['outstanding_amount'])) {
            $changes['valor_em_aberto'] = [
                'from' => round((float) ($invoice->valor_em_aberto ?? 0), 2),
                'to' => $snapshot['outstanding_amount'],
            ];
        }

        if ((string) $invoice->estado_pagamento !== $snapshot['status']) {
            $changes['estado_pagamento'] = [
                'from' => $invoice->estado_pagamento,
                'to' => $snapshot['status'],
            ];
        }

        $currentPaymentDate = optional($invoice->data_pagamento)?->toDateString();
        if ($currentPaymentDate !== $snapshot['payment_date']) {
            $changes['data_pagamento'] = [
                'from' => $currentPaymentDate,
                'to' => $snapshot['payment_date'],
            ];
        }

        if (($invoice->metodo_pagamento ?: null) !== $snapshot['payment_method']) {
            $changes['metodo_pagamento'] = [
                'from' => $invoice->metodo_pagamento,
                'to' => $snapshot['payment_method'],
            ];
        }

        if ($snapshot['status'] === 'pago' && ! $snapshot['has_active_fiscal_request']) {
            $changes['fiscal_document_request'] = [
                'action' => 'create',
                'soft_deleted_candidates' => $snapshot['soft_deleted_fiscal_requests_count'],
            ];
        }

        $blockedReasons = [];
        if ($invoice->estado_pagamento === 'pago' && $snapshot['status'] !== 'pago') {
            $blockedReasons[] = 'Downgrade de invoice paga nao e aplicado automaticamente por seguranca.';

            if ($this->fiscalDocumentRequestService->invoiceHasRegisteredDocument($invoice)) {
                $blockedReasons[] = 'Invoice paga com documento fiscal registado exige intervencao manual.';
            }
        }

        return [
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'expected_status' => $snapshot['status'],
            'current_status' => $invoice->estado_pagamento,
            'expected_paid_amount' => $snapshot['paid_amount'],
            'expected_outstanding_amount' => $snapshot['outstanding_amount'],
            'current_paid_amount' => round((float) ($invoice->valor_pago ?? 0), 2),
            'current_outstanding_amount' => round((float) ($invoice->valor_em_aberto ?? 0), 2),
            'allocation_count' => $snapshot['allocation_count'],
            'latest_payment_id' => $snapshot['latest_payment_id'],
            'latest_payment_reference' => $snapshot['latest_payment_reference'],
            'has_active_fiscal_request' => $snapshot['has_active_fiscal_request'],
            'soft_deleted_fiscal_requests_count' => $snapshot['soft_deleted_fiscal_requests_count'],
            'changes' => $changes,
            'requires_action' => $changes !== [],
            'eligible' => $changes !== [] && $blockedReasons === [],
            'blocked_reasons' => $blockedReasons,
            'executed' => false,
        ];
    }

    private function executeInvoiceRepairPlan(Invoice $invoice, array $plan): array
    {
        if (! $plan['eligible']) {
            return $plan;
        }

        return DB::transaction(function () use ($invoice, $plan) {
            $invoice = $invoice->fresh(['user', 'items', 'paymentAllocations.payment', 'fiscalDocumentRequests']);
            $snapshot = $this->buildInvoiceSnapshot($invoice);

            $invoice->forceFill([
                'valor_pago' => $snapshot['paid_amount'],
                'valor_em_aberto' => $snapshot['outstanding_amount'],
                'estado_pagamento' => $snapshot['status'],
                'data_pagamento' => $snapshot['payment_date'],
                'metodo_pagamento' => $snapshot['payment_method'],
            ]);
            $invoice->save();

            if ($snapshot['status'] === 'pago') {
                $this->fiscalDocumentRequestService->createFromInvoice($invoice->fresh(['user', 'items']), [
                    'paid_at' => $snapshot['payment_date'],
                ]);
            }

            $plan['executed'] = true;

            return $plan;
        });
    }

    private function buildPaymentRepairPlans(bool $cancelManualOrphans): array
    {
        $plans = [];

        foreach ($this->loadPaymentsForAudit() as $payment) {
            $snapshot = $this->buildPaymentSnapshot($payment);
            $plan = $this->buildPaymentRepairPlan($payment, $snapshot, $cancelManualOrphans);

            if (! $plan['requires_action']) {
                continue;
            }

            $plans[] = $plan;
        }

        return $plans;
    }

    private function buildPaymentSnapshot(Payment $payment): array
    {
        $confirmedAllocations = $payment->allocations
            ->filter(fn (PaymentAllocation $allocation) => $allocation->status === PaymentAllocation::STATUS_CONFIRMED)
            ->values();
        $activeCredits = $payment->credits
            ->filter(fn (AccountCredit $credit) => $credit->status !== AccountCredit::STATUS_CANCELLED)
            ->values();

        $realAllocatedAmount = round((float) $confirmedAllocations->sum('amount'), 2);
        $realCreditedAmount = round((float) $activeCredits->sum('amount'), 2);
        $realUnallocatedAmount = round(max((float) $payment->amount - $realAllocatedAmount - $realCreditedAmount, 0), 2);
        $storedAllocatedAmount = round((float) ($payment->allocated_amount ?? 0), 2);
        $storedUnallocatedAmount = round((float) ($payment->unallocated_amount ?? 0), 2);

        $bankStatement = $payment->bankStatement;
        $bankStatementIsUntreated = $bankStatement === null || $this->bankStatementIsUntreated($bankStatement);

        $hasNoConfirmedAllocations = $confirmedAllocations->isEmpty();
        $hasNoActiveCredits = $activeCredits->isEmpty();
        $isFullyUnallocated = $this->amountEquals($realUnallocatedAmount, $payment->amount);

        $isOrphanReconciliation = in_array($payment->source, [Payment::SOURCE_RECONCILIATION, Payment::SOURCE_BANK_STATEMENT], true)
            && $payment->status === Payment::STATUS_CONFIRMED
            && $hasNoConfirmedAllocations
            && $hasNoActiveCredits
            && $isFullyUnallocated
            && $bankStatementIsUntreated;

        $isOrphanManual = $payment->source === Payment::SOURCE_MANUAL
            && $payment->status === Payment::STATUS_CONFIRMED
            && $hasNoConfirmedAllocations
            && $hasNoActiveCredits
            && $payment->bank_statement_id === null;

        return [
            'real_allocated_amount' => $realAllocatedAmount,
            'real_unallocated_amount' => $realUnallocatedAmount,
            'confirmed_allocations_count' => $confirmedAllocations->count(),
            'active_credits_count' => $activeCredits->count(),
            'bank_statement_id' => $bankStatement?->id,
            'bank_statement_status' => $bankStatement?->conciliacao_status,
            'bank_statement_conciliado' => $bankStatement?->conciliado,
            'bank_statement_valor_conciliado' => round((float) ($bankStatement?->valor_conciliado ?? 0), 2),
            'has_balance_mismatch' => ! $this->amountEquals($storedAllocatedAmount, $realAllocatedAmount)
                || ! $this->amountEquals($storedUnallocatedAmount, $realUnallocatedAmount),
            'is_orphan_reconciliation' => $isOrphanReconciliation,
            'is_orphan_manual' => $isOrphanManual,
        ];
    }

    private function buildPaymentRepairPlan(Payment $payment, array $snapshot, bool $cancelManualOrphans): array
    {
        $changes = [];
        $blockedReasons = [];

        if (! $this->amountEquals($payment->allocated_amount, $snapshot['real_allocated_amount'])) {
            $changes['allocated_amount'] = [
                'from' => round((float) ($payment->allocated_amount ?? 0), 2),
                'to' => $snapshot['real_allocated_amount'],
            ];
        }

        if (! $this->amountEquals($payment->unallocated_amount, $snapshot['real_unallocated_amount'])) {
            $changes['unallocated_amount'] = [
                'from' => round((float) ($payment->unallocated_amount ?? 0), 2),
                'to' => $snapshot['real_unallocated_amount'],
            ];
        }

        if ($snapshot['is_orphan_reconciliation']) {
            $changes['cancel_payment'] = [
                'reason' => 'Pagamento de reconciliacao orfao sem allocations confirmadas nem creditos ativos.',
            ];
        }

        if ($snapshot['is_orphan_manual']) {
            if ($cancelManualOrphans) {
                $changes['cancel_payment'] = [
                    'reason' => 'Pagamento manual orfao sem allocations confirmadas, sem bank statement e sem creditos ativos.',
                ];
            } else {
                $blockedReasons[] = 'Pagamento manual orfao exige --cancel-manual-orphans para cancelamento.';
            }
        }

        return [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'source' => $payment->source,
            'status' => $payment->status,
            'bank_statement_id' => $snapshot['bank_statement_id'],
            'confirmed_allocations_count' => $snapshot['confirmed_allocations_count'],
            'active_credits_count' => $snapshot['active_credits_count'],
            'changes' => $changes,
            'requires_action' => $changes !== [] || $blockedReasons !== [],
            'eligible' => $changes !== [] && $blockedReasons === [],
            'blocked_reasons' => $blockedReasons,
            'executed' => false,
        ];
    }

    private function executePaymentRepairPlan(array $plan): array
    {
        if (! $plan['eligible']) {
            return $plan;
        }

        return DB::transaction(function () use ($plan) {
            $payment = Payment::query()
                ->with([
                    'bankStatement',
                    'allocations' => function ($query): void {
                        $query->confirmed();
                    },
                    'credits' => function ($query): void {
                        $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                    },
                ])
                ->findOrFail($plan['payment_id']);

            $snapshot = $this->buildPaymentSnapshot($payment);

            $payment->forceFill([
                'allocated_amount' => $snapshot['real_allocated_amount'],
                'unallocated_amount' => $snapshot['real_unallocated_amount'],
            ]);

            if (array_key_exists('cancel_payment', $plan['changes'])) {
                $payment->forceFill([
                    'status' => Payment::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'notes' => $this->appendLegacyRepairNote(
                        $payment->notes,
                        $plan['changes']['cancel_payment']['reason']
                    ),
                ]);
            }

            $payment->save();

            $plan['executed'] = true;

            return $plan;
        });
    }

    private function formatInvoiceMismatch(Invoice $invoice, array $snapshot): array
    {
        return [
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'current_status' => $invoice->estado_pagamento,
            'expected_status' => $snapshot['status'],
            'current_paid_amount' => round((float) ($invoice->valor_pago ?? 0), 2),
            'expected_paid_amount' => $snapshot['paid_amount'],
            'current_outstanding_amount' => round((float) ($invoice->valor_em_aberto ?? 0), 2),
            'expected_outstanding_amount' => $snapshot['outstanding_amount'],
            'allocation_count' => $snapshot['allocation_count'],
            'latest_payment_id' => $snapshot['latest_payment_id'],
        ];
    }

    private function formatPaidInvoiceWithoutFiscalRequest(Invoice $invoice, array $snapshot): array
    {
        return [
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'expected_status' => $snapshot['status'],
            'paid_amount' => $snapshot['paid_amount'],
            'payment_date' => $snapshot['payment_date'],
            'latest_payment_id' => $snapshot['latest_payment_id'],
            'soft_deleted_fiscal_requests_count' => $snapshot['soft_deleted_fiscal_requests_count'],
        ];
    }

    private function formatSoftDeletedFiscalRequestFinding(Invoice $invoice, array $snapshot): array
    {
        return [
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'allocation_count' => $snapshot['allocation_count'],
            'expected_status' => $snapshot['status'],
            'active_fiscal_request_id' => $snapshot['active_fiscal_request_id'],
            'soft_deleted_fiscal_requests_count' => $snapshot['soft_deleted_fiscal_requests_count'],
        ];
    }

    private function formatPaymentFinding(Payment $payment, array $snapshot): array
    {
        return [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'source' => $payment->source,
            'status' => $payment->status,
            'amount' => round((float) $payment->amount, 2),
            'allocated_amount' => round((float) ($payment->allocated_amount ?? 0), 2),
            'expected_allocated_amount' => $snapshot['real_allocated_amount'],
            'unallocated_amount' => round((float) ($payment->unallocated_amount ?? 0), 2),
            'expected_unallocated_amount' => $snapshot['real_unallocated_amount'],
            'payment_date' => optional($payment->payment_date)?->toDateString(),
            'method' => $payment->method,
            'reference' => $payment->reference,
            'bank_statement_id' => $snapshot['bank_statement_id'],
            'bank_statement_status' => $snapshot['bank_statement_status'],
            'bank_statement_conciliado' => $snapshot['bank_statement_conciliado'],
            'bank_statement_valor_conciliado' => $snapshot['bank_statement_valor_conciliado'],
            'confirmed_allocations_count' => $snapshot['confirmed_allocations_count'],
            'active_credits_count' => $snapshot['active_credits_count'],
        ];
    }

    private function bankStatementIsUntreated(BankStatement $bankStatement): bool
    {
        return ! (bool) $bankStatement->conciliado
            && (($bankStatement->conciliacao_status ?? 'unreconciled') === 'unreconciled')
            && $this->amountEquals($bankStatement->valor_conciliado, 0);
    }

    private function appendLegacyRepairNote(?string $existingNotes, string $reason): string
    {
        $note = '[legacy-repair] ' . $reason;

        if (blank($existingNotes)) {
            return $note;
        }

        if (str_contains($existingNotes, $note)) {
            return $existingNotes;
        }

        return $existingNotes . PHP_EOL . $note;
    }

    private function amountEquals(mixed $left, mixed $right): bool
    {
        return abs(round((float) $left, 2) - round((float) $right, 2)) <= 0.009;
    }
}