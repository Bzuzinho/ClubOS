<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\Familia;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentAllocationService
{
    public function __construct(
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
        private readonly AccountCreditService $accountCreditService,
    ) {
    }

    public function createPayment(array $data): Payment
    {
        $amount = round(abs((float) ($data['amount'] ?? 0)), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'O valor do pagamento deve ser superior a zero.',
            ]);
        }

        $familyId = $this->resolveFamilyId(
            $data['user_id'] ?? null,
            $data['family_id'] ?? null,
        );

        $paymentMethod = $this->resolvePaymentMethod($data['method'] ?? null);

        if ($paymentMethod && $paymentMethod->requer_linha_bancaria && empty($data['bank_statement_id'])) {
            throw ValidationException::withMessages([
                'bank_statement_id' => 'O metodo de pagamento selecionado requer uma linha de extrato bancario.',
                'method' => 'O metodo de pagamento selecionado requer uma linha de extrato bancario.',
            ]);
        }

        return Payment::create([
            'user_id' => $data['user_id'] ?? null,
            'family_id' => $familyId,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'amount' => $amount,
            'allocated_amount' => 0,
            'unallocated_amount' => $amount,
            'payment_date' => $data['payment_date'] ?? null,
            'method' => $paymentMethod?->codigo,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? Payment::SOURCE_MANUAL,
            'status' => $data['status'] ?? Payment::STATUS_CONFIRMED,
            'created_by' => $data['created_by'] ?? null,
            'cancelled_by' => $data['cancelled_by'] ?? null,
            'cancelled_at' => $data['cancelled_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    private function resolvePaymentMethod(?string $rawMethod): ?PaymentMethod
    {
        if (!is_string($rawMethod) || trim($rawMethod) === '') {
            return null;
        }

        $normalizedInput = $this->normalizePaymentMethodValue($rawMethod);

        $matched = PaymentMethod::query()
            ->get()
            ->first(function (PaymentMethod $paymentMethod) use ($normalizedInput): bool {
                return $this->normalizePaymentMethodValue($paymentMethod->codigo) === $normalizedInput
                    || $this->normalizePaymentMethodValue($paymentMethod->nome) === $normalizedInput;
            });

        if (!$matched) {
            throw ValidationException::withMessages([
                'method' => 'O metodo de pagamento selecionado nao existe.',
            ]);
        }

        if (!$matched->ativo) {
            throw ValidationException::withMessages([
                'method' => 'O metodo de pagamento selecionado esta inativo.',
            ]);
        }

        return $matched;
    }

    private function normalizePaymentMethodValue(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->lower()
            ->trim()
            ->replaceMatches('/[^a-z0-9]+/', '-');
    }

    public function allocatePayment(Payment $payment, array $allocations, array $options = []): Payment
    {
        if ($payment->status === Payment::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'payment' => 'Nao e possivel alocar um pagamento cancelado.',
            ]);
        }

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Indique pelo menos uma alocacao.',
            ]);
        }

        return DB::transaction(function () use ($payment, $allocations, $options) {
            $payment->refresh();

            $bankStatement = isset($options['bank_statement']) && $options['bank_statement'] instanceof BankStatement
                ? $options['bank_statement']->fresh()
                : ($payment->bankStatement?->fresh());
            $createCredit = (bool) ($options['create_credit'] ?? false);
            $createdBy = $options['created_by'] ?? null;
            $requestedTotal = round(collect($allocations)->sum(fn ($allocation) => abs((float) ($allocation['amount'] ?? 0))), 2);
            $availableAmount = round((float) $payment->unallocated_amount, 2);

            if ($requestedTotal <= 0) {
                throw ValidationException::withMessages([
                    'allocations' => 'O valor total alocado deve ser superior a zero.',
                ]);
            }

            if ($requestedTotal - $availableAmount > 0.009) {
                throw ValidationException::withMessages([
                    'allocations' => 'As alocacoes excedem o saldo disponivel do pagamento.',
                ]);
            }

            $invoiceIds = collect($allocations)
                ->pluck('invoice_id')
                ->filter()
                ->unique()
                ->values();

            $invoices = Invoice::query()
                ->whereIn('id', $invoiceIds)
                ->get()
                ->keyBy('id');

            $previousStatuses = [];
            foreach ($allocations as $allocation) {
                $invoiceId = $allocation['invoice_id'] ?? null;
                $amount = round(abs((float) ($allocation['amount'] ?? 0)), 2);

                if (!$invoiceId || !$invoices->has($invoiceId)) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Foi indicada uma fatura invalida.',
                    ]);
                }

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Cada alocacao deve ter um valor superior a zero.',
                    ]);
                }

                $invoice = $invoices->get($invoiceId)->fresh();
                $previousStatuses[$invoice->id] = $invoice->estado_pagamento;

                if ($invoice->estado_pagamento === 'cancelado') {
                    throw ValidationException::withMessages([
                        'allocations' => 'Nao e possivel pagar uma fatura cancelada.',
                    ]);
                }

                $invoice = $this->repairStaleManualPaymentState($invoice, [
                    'cancelled_by' => $createdBy,
                    'cancelled_at' => now(),
                ]);
                $invoices->put($invoice->id, $invoice);

                $openAmount = $this->getInvoiceOutstandingAmount($invoice);
                if ($amount - $openAmount > 0.009) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Uma das alocacoes excede o valor em aberto da fatura.',
                    ]);
                }
            }

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation['invoice_id'])->fresh();
                $allocationModel = PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => round(abs((float) $allocation['amount']), 2),
                    'status' => PaymentAllocation::STATUS_CONFIRMED,
                    'allocated_at' => $options['allocated_at'] ?? now(),
                    'created_by' => $createdBy,
                    'notes' => $allocation['notes'] ?? ($options['notes'] ?? null),
                    'metadata' => $allocation['metadata'] ?? null,
                ]);

                $entry = $this->createOrUpdateAllocationFinancialEntry($payment, $allocationModel, $invoice);

                if ($bankStatement) {
                    $this->createOrUpdateReconciliationMap(
                        payment: $payment,
                        allocation: $allocationModel,
                        invoice: $invoice,
                        bankStatement: $bankStatement,
                        entry: $entry,
                        previousStatus: $previousStatuses[$invoice->id] ?? $invoice->estado_pagamento,
                        options: $options,
                    );
                }
            }

            $payment = $this->syncPaymentBalances($payment->fresh());

            $createdCredit = null;
            if ($createCredit && (float) $payment->unallocated_amount > 0) {
                $createdCredit = $this->accountCreditService->createFromPaymentOverpayment($payment, null, array_merge($options, [
                    'created_by' => $createdBy,
                ]));
                $payment = $this->syncPaymentBalances($payment->fresh());
            }

            foreach ($invoiceIds as $invoiceId) {
                $invoice = $this->recalculateInvoicePaymentStatus($invoices->get($invoiceId)->fresh());

                if (
                    $invoice->estado_pagamento === 'pago'
                    && ($previousStatuses[$invoice->id] ?? null) !== 'pago'
                ) {
                    $this->fiscalDocumentRequestService->createFromInvoice($invoice, [
                        'paid_at' => $invoice->data_pagamento,
                        'bank_statement_id' => $bankStatement?->id,
                        'created_by' => $createdBy,
                    ]);
                }
            }

            if ($bankStatement) {
                $this->syncBankStatementStatus($bankStatement->fresh());
            }

            return $payment->fresh(['allocations.invoice', 'credits', 'bankStatement']);
        });
    }

    public function createFromBankStatement(BankStatement $bankStatement, array $allocations, array $options = []): Payment
    {
        $bankStatement = $bankStatement->fresh();

        if ($this->isBankStatementFullyReconciled($bankStatement)) {
            throw ValidationException::withMessages([
                'bank_statement_id' => 'A linha de extrato ja se encontra totalmente conciliada.',
            ]);
        }

        $payment = Payment::query()
            ->confirmed()
            ->where('bank_statement_id', $bankStatement->id)
            ->latest('created_at')
            ->first();

        if (!$payment) {
            $resolvedUserId = $options['user_id'] ?? $this->resolveUserIdFromAllocations($allocations);

            $payment = $this->createPayment([
                'user_id' => $resolvedUserId,
                'family_id' => $options['family_id'] ?? null,
                'bank_statement_id' => $bankStatement->id,
                'amount' => abs((float) $bankStatement->valor),
                'payment_date' => $bankStatement->data_movimento,
                'method' => $options['method'] ?? 'transferencia',
                'reference' => $options['reference'] ?? $bankStatement->referencia,
                'description' => $options['description'] ?? $bankStatement->descricao,
                'source' => $options['source'] ?? Payment::SOURCE_BANK_STATEMENT,
                'status' => Payment::STATUS_CONFIRMED,
                'created_by' => $options['created_by'] ?? null,
                'notes' => $options['notes'] ?? null,
                'metadata' => array_merge((array) ($options['metadata'] ?? []), [
                    'bank_statement_reference' => $bankStatement->referencia,
                ]),
            ]);
        }

        $payment = $this->allocatePayment($payment, $allocations, array_merge($options, [
            'bank_statement' => $bankStatement,
        ]));

        $this->reconciliationRepositoryService->storeFromConfirmedReconciliation(
            $bankStatement,
            $payment,
            $options['created_by'] ?? null,
        );

        return $payment;
    }

    public function reconcileLegacyPaidInvoices(
        BankStatement $bankStatement,
        iterable $invoices,
        array $options = [],
    ): Payment {
        $invoiceIds = collect($invoices)
            ->map(fn ($invoice) => $invoice instanceof Invoice ? $invoice->id : $invoice)
            ->filter()
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            throw ValidationException::withMessages([
                'invoices' => 'Indique pelo menos uma mensalidade antiga para conciliar.',
            ]);
        }

        if ($invoiceIds->count() === 1) {
            return $this->reconcileLegacyPaidInvoice(
                $bankStatement,
                Invoice::query()->findOrFail($invoiceIds->first()),
                $options,
            );
        }

        return DB::transaction(function () use ($bankStatement, $invoiceIds, $options): Payment {
            $bankStatement = BankStatement::query()
                ->whereKey($bankStatement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedInvoices = Invoice::query()
                ->with('user.families:id')
                ->whereIn('id', $invoiceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedInvoices->count() !== $invoiceIds->count()) {
                throw ValidationException::withMessages([
                    'invoices' => 'Uma ou mais mensalidades antigas já não estão disponíveis.',
                ]);
            }

            if ((float) $bankStatement->valor <= 0 || $this->isBankStatementFullyReconciled($bankStatement)) {
                throw ValidationException::withMessages([
                    'bank_statement' => 'A linha bancária não está disponível para conciliar estes pagamentos.',
                ]);
            }

            $statementAmount = round((float) (
                $bankStatement->valor_por_conciliar
                ?? abs((float) $bankStatement->valor)
            ), 2);
            $invoiceAmounts = $lockedInvoices->mapWithKeys(function (Invoice $invoice): array {
                $invoiceAmount = round((float) $invoice->valor_total, 2);
                $trackedPaidAmount = round(max(
                    (float) ($invoice->valor_pago ?? 0),
                    $invoiceAmount - (float) ($invoice->valor_em_aberto ?? $invoiceAmount),
                ), 2);

                return [$invoice->id => $trackedPaidAmount];
            });

            if (
                $lockedInvoices->contains(fn (Invoice $invoice): bool => $invoice->estado_pagamento !== 'pago')
                || $invoiceAmounts->contains(fn (float $amount): bool => $amount <= 0.009)
                || abs(round((float) $invoiceAmounts->sum(), 2) - $statementAmount) > 0.009
            ) {
                throw ValidationException::withMessages([
                    'invoices' => 'As mensalidades antigas deixaram de corresponder integralmente ao movimento bancário.',
                ]);
            }

            $alreadyReconciledInvoiceIds = MapaConciliacao::query()
                ->whereIn('fatura_id', $invoiceIds)
                ->where('status', 'confirmado')
                ->pluck('fatura_id');

            if ($alreadyReconciledInvoiceIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'invoices' => 'Uma ou mais mensalidades antigas já se encontram conciliadas.',
                ]);
            }

            $legacyAllocationsByInvoice = PaymentAllocation::query()
                ->confirmed()
                ->whereIn('invoice_id', $invoiceIds)
                ->whereHas('payment', function ($paymentQuery): void {
                    $paymentQuery
                        ->confirmed()
                        ->whereNull('bank_statement_id');
                })
                ->with([
                    'payment.allocations' => function ($allocationQuery): void {
                        $allocationQuery->confirmed();
                    },
                ])
                ->lockForUpdate()
                ->get()
                ->groupBy('invoice_id');

            $selectedAllocations = collect();
            foreach ($invoiceIds as $invoiceId) {
                $candidates = collect($legacyAllocationsByInvoice->get($invoiceId, collect()))
                    ->filter(fn (PaymentAllocation $allocation): bool =>
                        abs((float) $allocation->amount - (float) $invoiceAmounts->get($invoiceId)) <= 0.009
                    )
                    ->values();

                if ($candidates->count() > 1) {
                    throw ValidationException::withMessages([
                        'invoices' => 'Existem vários pagamentos manuais possíveis para uma das mensalidades. Confirme a associação manualmente.',
                    ]);
                }

                if ($candidates->isNotEmpty()) {
                    $selectedAllocations->push($candidates->first());
                }
            }

            $existingPayments = $selectedAllocations
                ->map(fn (PaymentAllocation $allocation) => $allocation->payment)
                ->filter()
                ->unique('id')
                ->values();

            foreach ($existingPayments as $legacyPayment) {
                $hasAllocationOutsideSelection = $legacyPayment->allocations
                    ->contains(fn (PaymentAllocation $allocation): bool =>
                        !$invoiceIds->contains($allocation->invoice_id)
                    );

                if ($hasAllocationOutsideSelection) {
                    throw ValidationException::withMessages([
                        'invoices' => 'Um pagamento manual inclui outras mensalidades e não pode ser agregado automaticamente.',
                    ]);
                }
            }

            $primaryInvoice = $lockedInvoices->get($invoiceIds->first());
            $payment = $existingPayments->first();

            if ($payment) {
                $payment->forceFill([
                    'user_id' => $options['user_id'] ?? $payment->user_id ?? $primaryInvoice?->user_id,
                    'family_id' => $options['family_id'] ?? $payment->family_id,
                    'bank_statement_id' => $bankStatement->id,
                    'amount' => $statementAmount,
                    'payment_date' => $bankStatement->data_movimento,
                    'method' => $payment->method ?: ($options['method'] ?? 'transferencia'),
                    'reference' => $bankStatement->referencia ?: $payment->reference,
                    'description' => $bankStatement->descricao ?: $payment->description,
                    'source' => Payment::SOURCE_RECONCILIATION,
                    'status' => Payment::STATUS_CONFIRMED,
                    'created_by' => $options['created_by'] ?? $payment->created_by,
                    'notes' => $options['notes'] ?? $payment->notes,
                    'metadata' => array_merge((array) ($payment->metadata ?? []), [
                        'legacy_family_payments_merged' => true,
                        'legacy_invoice_ids' => $invoiceIds->all(),
                        'linked_bank_statement_id' => $bankStatement->id,
                        'linked_at' => now()->toIso8601String(),
                    ]),
                ]);
                $payment->save();
            } else {
                $payment = $this->createPayment([
                    'user_id' => $options['user_id'] ?? $primaryInvoice?->user_id,
                    'family_id' => $options['family_id'] ?? null,
                    'bank_statement_id' => $bankStatement->id,
                    'amount' => $statementAmount,
                    'payment_date' => $bankStatement->data_movimento,
                    'method' => $options['method'] ?? 'transferencia',
                    'reference' => $bankStatement->referencia,
                    'description' => $bankStatement->descricao,
                    'source' => Payment::SOURCE_RECONCILIATION,
                    'created_by' => $options['created_by'] ?? null,
                    'notes' => $options['notes'] ?? 'Normalização de mensalidades familiares pagas antes do fluxo canónico de conciliação.',
                    'metadata' => [
                        'legacy_family_payment_normalized' => true,
                        'legacy_invoice_ids' => $invoiceIds->all(),
                        'linked_bank_statement_id' => $bankStatement->id,
                        'linked_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            foreach ($invoiceIds as $invoiceId) {
                $invoice = $lockedInvoices->get($invoiceId);
                $amount = round((float) $invoiceAmounts->get($invoiceId), 2);
                $allocation = $selectedAllocations
                    ->first(fn (PaymentAllocation $candidate): bool => $candidate->invoice_id === $invoiceId);

                if ($allocation) {
                    if ($allocation->payment_id !== $payment->id) {
                        $allocation->forceFill([
                            'payment_id' => $payment->id,
                            'metadata' => array_merge((array) ($allocation->metadata ?? []), [
                                'legacy_payment_merged_into' => $payment->id,
                            ]),
                        ])->save();
                    }
                } else {
                    $allocation = PaymentAllocation::query()->create([
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $amount,
                        'status' => PaymentAllocation::STATUS_CONFIRMED,
                        'allocated_at' => $bankStatement->data_movimento ?? now(),
                        'created_by' => $options['created_by'] ?? null,
                        'notes' => 'Alocação criada a partir de um pagamento manual anterior ao fluxo canónico.',
                        'metadata' => [
                            'legacy_family_payment_normalized' => true,
                        ],
                    ]);
                }

                $canonicalEntry = FinancialEntry::query()
                    ->where('origem_tipo', 'payment_allocation')
                    ->where('origem_id', $allocation->id)
                    ->lockForUpdate()
                    ->first();
                $legacyEntries = FinancialEntry::query()
                    ->where('fatura_id', $invoice->id)
                    ->where('valor', '>=', $amount - 0.009)
                    ->where('valor', '<=', $amount + 0.009)
                    ->where(function ($entryQuery): void {
                        $entryQuery
                            ->whereNull('bank_statement_id')
                            ->orWhereNull('payment_id');
                    })
                    ->where(function ($entryQuery): void {
                        $entryQuery
                            ->whereNull('origem_tipo')
                            ->orWhere('origem_tipo', 'manual');
                    })
                    ->lockForUpdate()
                    ->get();

                if (!$canonicalEntry && $legacyEntries->count() > 1) {
                    throw ValidationException::withMessages([
                        'invoices' => 'Existem vários lançamentos antigos para uma das mensalidades. Confirme a associação manualmente.',
                    ]);
                }

                $entry = $canonicalEntry ?? $legacyEntries->first() ?? new FinancialEntry();
                $entry->forceFill([
                    'data' => $bankStatement->data_movimento ?? $invoice->data_pagamento ?? now()->toDateString(),
                    'tipo' => 'receita',
                    'categoria' => 'Pagamento de Fatura',
                    'descricao' => $entry->descricao ?: sprintf(
                        'Pagamento conciliado da mensalidade de %s',
                        $invoice->user?->nome_completo ?? $invoice->user?->name ?? $invoice->id,
                    ),
                    'documento_ref' => $bankStatement->referencia ?: $entry->documento_ref,
                    'valor' => $amount,
                    'valor_pago' => $amount,
                    'valor_em_aberto' => 0,
                    'estado' => 'pago',
                    'data_pagamento' => $bankStatement->data_movimento,
                    'data_liquidacao' => $bankStatement->data_movimento,
                    'centro_custo_id' => $invoice->centro_custo_id,
                    'user_id' => $invoice->user_id,
                    'fatura_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'bank_statement_id' => $bankStatement->id,
                    'origem_tipo' => 'payment_allocation',
                    'origem_modulo' => 'financeiro',
                    'origem_id' => $allocation->id,
                    'metodo_pagamento' => $payment->method,
                ]);
                $entry->save();

                $this->createOrUpdateReconciliationMap(
                    payment: $payment,
                    allocation: $allocation,
                    invoice: $invoice,
                    bankStatement: $bankStatement,
                    entry: $entry,
                    previousStatus: 'pago',
                    options: array_merge($options, [
                        'map_rule' => 'legacy_paid_family_invoices_link',
                        'map_metadata' => [
                            'legacy_paid_invoice' => true,
                            'legacy_family_payment' => true,
                            'suggestion_id' => $options['suggestion_id'] ?? null,
                        ],
                    ]),
                );

                $this->recalculateInvoicePaymentStatus($invoice->fresh());
            }

            foreach ($existingPayments->where('id', '!=', $payment->id) as $mergedPayment) {
                $mergedPayment->forceFill([
                    'status' => Payment::STATUS_CANCELLED,
                    'cancelled_by' => $options['created_by'] ?? null,
                    'cancelled_at' => now(),
                    'metadata' => array_merge((array) ($mergedPayment->metadata ?? []), [
                        'legacy_payment_merged_into' => $payment->id,
                        'merged_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            $payment = $this->syncPaymentBalances($payment->fresh());
            $this->syncBankStatementStatus($bankStatement->fresh());
            $this->reconciliationRepositoryService->storeFromConfirmedReconciliation(
                $bankStatement->fresh(),
                $payment,
                $options['created_by'] ?? null,
            );

            return $payment->fresh(['allocations.invoice', 'bankStatement']);
        });
    }

    public function reconcileLegacyPaidInvoice(
        BankStatement $bankStatement,
        Invoice $invoice,
        array $options = [],
    ): Payment {
        return DB::transaction(function () use ($bankStatement, $invoice, $options): Payment {
            $bankStatement = BankStatement::query()
                ->whereKey($bankStatement->id)
                ->lockForUpdate()
                ->firstOrFail();
            $invoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $bankStatement->valor <= 0 || $this->isBankStatementFullyReconciled($bankStatement)) {
                throw ValidationException::withMessages([
                    'bank_statement' => 'A linha bancária não está disponível para conciliar este pagamento.',
                ]);
            }

            $statementAmount = round((float) (
                $bankStatement->valor_por_conciliar
                ?? abs((float) $bankStatement->valor)
            ), 2);
            $invoiceAmount = round((float) $invoice->valor_total, 2);
            $trackedPaidAmount = round(max(
                (float) ($invoice->valor_pago ?? 0),
                $invoiceAmount - (float) ($invoice->valor_em_aberto ?? $invoiceAmount),
            ), 2);

            if (
                $invoice->estado_pagamento !== 'pago'
                || abs($invoiceAmount - $statementAmount) > 0.009
                || abs($trackedPaidAmount - $statementAmount) > 0.009
            ) {
                throw ValidationException::withMessages([
                    'invoice' => 'A mensalidade antiga deixou de corresponder integralmente ao movimento bancário.',
                ]);
            }

            $legacyAllocations = PaymentAllocation::query()
                ->confirmed()
                ->where('invoice_id', $invoice->id)
                ->whereHas('payment', function ($paymentQuery): void {
                    $paymentQuery
                        ->confirmed()
                        ->whereNull('bank_statement_id');
                })
                ->with([
                    'payment.allocations' => function ($allocationQuery): void {
                        $allocationQuery->confirmed();
                    },
                ])
                ->lockForUpdate()
                ->get()
                ->filter(function (PaymentAllocation $allocation) use ($statementAmount): bool {
                    $payment = $allocation->payment;

                    return $payment
                        && abs((float) $allocation->amount - $statementAmount) <= 0.009
                        && abs((float) $payment->amount - $statementAmount) <= 0.009
                        && $payment->allocations->count() === 1;
                })
                ->values();

            if ($legacyAllocations->count() > 1) {
                throw ValidationException::withMessages([
                    'invoice' => 'Existem vários pagamentos manuais possíveis para esta mensalidade. Confirme a associação manualmente.',
                ]);
            }

            $allocation = $legacyAllocations->first();
            $payment = $allocation?->payment;
            $legacyPaymentSnapshot = $payment ? [
                'payment_id' => $payment->id,
                'source' => $payment->source,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'method' => $payment->method,
                'reference' => $payment->reference,
            ] : null;

            if ($payment) {
                $payment->forceFill([
                    'bank_statement_id' => $bankStatement->id,
                    'payment_date' => $bankStatement->data_movimento,
                    'method' => $payment->method ?: ($options['method'] ?? 'transferencia'),
                    'reference' => $bankStatement->referencia ?: $payment->reference,
                    'description' => $bankStatement->descricao ?: $payment->description,
                    'source' => Payment::SOURCE_RECONCILIATION,
                    'created_by' => $options['created_by'] ?? $payment->created_by,
                    'notes' => $options['notes'] ?? $payment->notes,
                    'metadata' => array_merge((array) ($payment->metadata ?? []), [
                        'legacy_manual_payment_linked' => true,
                        'legacy_payment_snapshot' => $legacyPaymentSnapshot,
                        'linked_bank_statement_id' => $bankStatement->id,
                        'linked_at' => now()->toIso8601String(),
                    ]),
                ]);
                $payment->save();
            } else {
                $payment = $this->createPayment([
                    'user_id' => $invoice->user_id,
                    'bank_statement_id' => $bankStatement->id,
                    'amount' => $statementAmount,
                    'payment_date' => $bankStatement->data_movimento,
                    'method' => $options['method'] ?? 'transferencia',
                    'reference' => $bankStatement->referencia,
                    'description' => $bankStatement->descricao,
                    'source' => Payment::SOURCE_RECONCILIATION,
                    'created_by' => $options['created_by'] ?? null,
                    'notes' => $options['notes'] ?? 'Normalização de mensalidade paga antes do fluxo canónico de conciliação.',
                    'metadata' => [
                        'legacy_invoice_payment_normalized' => true,
                        'legacy_invoice_id' => $invoice->id,
                        'linked_bank_statement_id' => $bankStatement->id,
                        'linked_at' => now()->toIso8601String(),
                    ],
                ]);

                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $statementAmount,
                    'status' => PaymentAllocation::STATUS_CONFIRMED,
                    'allocated_at' => $bankStatement->data_movimento ?? now(),
                    'created_by' => $options['created_by'] ?? null,
                    'notes' => 'Alocação criada a partir de um pagamento manual anterior ao fluxo canónico.',
                    'metadata' => [
                        'legacy_invoice_payment_normalized' => true,
                    ],
                ]);
            }

            $canonicalEntry = FinancialEntry::query()
                ->where('origem_tipo', 'payment_allocation')
                ->where('origem_id', $allocation->id)
                ->lockForUpdate()
                ->first();

            $legacyEntries = FinancialEntry::query()
                ->where('fatura_id', $invoice->id)
                ->where('valor', '>=', $statementAmount - 0.009)
                ->where('valor', '<=', $statementAmount + 0.009)
                ->where(function ($entryQuery): void {
                    $entryQuery
                        ->whereNull('bank_statement_id')
                        ->orWhereNull('payment_id');
                })
                ->where(function ($entryQuery): void {
                    $entryQuery
                        ->whereNull('origem_tipo')
                        ->orWhere('origem_tipo', 'manual');
                })
                ->lockForUpdate()
                ->get();

            if (!$canonicalEntry && $legacyEntries->count() > 1) {
                throw ValidationException::withMessages([
                    'invoice' => 'Existem vários lançamentos antigos para esta mensalidade. Confirme a associação manualmente.',
                ]);
            }

            $entry = $canonicalEntry ?? $legacyEntries->first() ?? new FinancialEntry();
            $entry->forceFill([
                'data' => $bankStatement->data_movimento ?? $invoice->data_pagamento ?? now()->toDateString(),
                'tipo' => 'receita',
                'categoria' => 'Pagamento de Fatura',
                'descricao' => $entry->descricao ?: sprintf(
                    'Pagamento conciliado da mensalidade de %s',
                    $invoice->user?->nome_completo ?? $invoice->user?->name ?? $invoice->id,
                ),
                'documento_ref' => $bankStatement->referencia ?: $entry->documento_ref,
                'valor' => $statementAmount,
                'valor_pago' => $statementAmount,
                'valor_em_aberto' => 0,
                'estado' => 'pago',
                'data_pagamento' => $bankStatement->data_movimento,
                'data_liquidacao' => $bankStatement->data_movimento,
                'centro_custo_id' => $invoice->centro_custo_id,
                'user_id' => $invoice->user_id,
                'fatura_id' => $invoice->id,
                'payment_id' => $payment->id,
                'bank_statement_id' => $bankStatement->id,
                'origem_tipo' => 'payment_allocation',
                'origem_modulo' => 'financeiro',
                'origem_id' => $allocation->id,
                'metodo_pagamento' => $payment->method,
            ]);
            $entry->save();

            $this->createOrUpdateReconciliationMap(
                payment: $payment,
                allocation: $allocation,
                invoice: $invoice,
                bankStatement: $bankStatement,
                entry: $entry,
                previousStatus: 'pago',
                options: array_merge($options, [
                    'map_rule' => 'legacy_paid_invoice_link',
                    'map_metadata' => [
                        'legacy_paid_invoice' => true,
                        'legacy_payment_reused' => $legacyPaymentSnapshot !== null,
                        'suggestion_id' => $options['suggestion_id'] ?? null,
                    ],
                ]),
            );

            $payment = $this->syncPaymentBalances($payment->fresh());
            $this->recalculateInvoicePaymentStatus($invoice->fresh());
            $this->syncBankStatementStatus($bankStatement->fresh());
            $this->reconciliationRepositoryService->storeFromConfirmedReconciliation(
                $bankStatement->fresh(),
                $payment,
                $options['created_by'] ?? null,
            );

            return $payment->fresh(['allocations.invoice', 'bankStatement']);
        });
    }

    public function recalculateInvoicePaymentStatus(Invoice $invoice): Invoice
    {
        $paidAmount = $this->getInvoicePaidAmount($invoice);
        $totalAmount = round((float) $invoice->valor_total, 2);
        $outstandingAmount = round(max($totalAmount - $paidAmount, 0), 2);
        $latestAllocation = PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->with('payment')
            ->orderByDesc('allocated_at')
            ->orderByDesc('created_at')
            ->first();

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

        $invoice->forceFill([
            'valor_pago' => $paidAmount,
            'valor_em_aberto' => $outstandingAmount,
            'estado_pagamento' => $status,
            'data_pagamento' => $status === 'pago' ? ($latestAllocation?->payment?->payment_date ?? now()->toDateString()) : null,
            'metodo_pagamento' => $latestAllocation?->payment?->method,
            'referencia_pagamento' => $latestAllocation?->payment?->reference ?: $invoice->referencia_pagamento,
            'pagamento_observacoes' => $latestAllocation?->payment?->notes,
        ]);
        $invoice->save();

        return $invoice->refresh();
    }

    public function reverseInvoicePayments(Invoice $invoice, array $options = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $options) {
            $invoice = $invoice->fresh();
            $allocations = PaymentAllocation::query()
                ->confirmed()
                ->where('invoice_id', $invoice->id)
                ->with(['payment.bankStatement'])
                ->get();

            if ($allocations->isEmpty()) {
                $invoice->forceFill([
                    'valor_pago' => 0,
                    'valor_em_aberto' => round((float) $invoice->valor_total, 2),
                    'data_pagamento' => null,
                    'metodo_pagamento' => null,
                    'referencia_pagamento' => null,
                    'pagamento_observacoes' => null,
                ]);
                $invoice->save();

                return $invoice->refresh();
            }

            $paymentIds = [];
            $bankStatementIds = [];

            foreach ($allocations as $allocation) {
                if ($allocation->payment_id) {
                    $paymentIds[] = $allocation->payment_id;
                }

                if ($allocation->payment?->bank_statement_id) {
                    $bankStatementIds[] = $allocation->payment->bank_statement_id;
                }

                MapaConciliacao::query()
                    ->where('payment_allocation_id', $allocation->id)
                    ->delete();

                FinancialEntry::query()
                    ->where('origem_tipo', 'payment_allocation')
                    ->where('origem_id', $allocation->id)
                    ->delete();

                $allocation->forceFill([
                    'status' => PaymentAllocation::STATUS_CANCELLED,
                ]);
                $allocation->save();
                $allocation->delete();
            }

            foreach (array_values(array_unique($paymentIds)) as $paymentId) {
                $payment = Payment::query()->find($paymentId);

                if (!$payment) {
                    continue;
                }

                $payment = $this->syncPaymentBalances($payment->fresh());
                $hasConfirmedAllocations = PaymentAllocation::query()
                    ->confirmed()
                    ->where('payment_id', $payment->id)
                    ->exists();
                $hasActiveCredits = AccountCredit::query()
                    ->where('payment_id', $payment->id)
                    ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
                    ->exists();

                if (!$hasConfirmedAllocations && !$hasActiveCredits && $payment->source === Payment::SOURCE_MANUAL) {
                    $payment->forceFill([
                        'status' => Payment::STATUS_CANCELLED,
                        'cancelled_by' => $options['cancelled_by'] ?? null,
                        'cancelled_at' => $options['cancelled_at'] ?? now(),
                    ]);
                    $payment->save();
                }
            }

            foreach (array_values(array_unique($bankStatementIds)) as $bankStatementId) {
                $bankStatement = BankStatement::query()->find($bankStatementId);

                if ($bankStatement) {
                    $this->syncBankStatementStatus($bankStatement->fresh());
                }
            }

            $invoice->forceFill([
                'valor_pago' => 0,
                'valor_em_aberto' => round((float) $invoice->valor_total, 2),
                'data_pagamento' => null,
                'metodo_pagamento' => null,
                'referencia_pagamento' => null,
                'pagamento_observacoes' => null,
            ]);
            $invoice->save();

            return $invoice->refresh();
        });
    }

    public function reopenInvoice(Invoice $invoice, string $targetStatus, array $options = []): Invoice
    {
        if (! in_array($targetStatus, ['pendente', 'vencido'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A fatura so pode ser reaberta para pendente ou vencido.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $targetStatus, $options) {
            $invoice = $invoice->fresh();

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'invoice' => 'Fatura invalida.',
                ]);
            }

            if ($invoice->estado_pagamento === 'cancelado') {
                throw ValidationException::withMessages([
                    'estado_pagamento' => 'Nao e possivel reabrir uma fatura cancelada.',
                ]);
            }

            if (
                $this->fiscalDocumentRequestService->invoiceHasRegisteredDocument($invoice)
                || filled($invoice->numero_recibo)
            ) {
                throw ValidationException::withMessages([
                    'estado_pagamento' => FiscalDocumentRequestService::INVOICE_STATUS_CHANGE_BLOCK_MESSAGE,
                ]);
            }

            $affectedPaymentIds = PaymentAllocation::query()
                ->confirmed()
                ->where('invoice_id', $invoice->id)
                ->pluck('payment_id')
                ->filter()
                ->unique()
                ->values();

            $invoice = $this->reverseInvoicePayments($invoice, [
                'cancelled_by' => $options['created_by'] ?? null,
                'cancelled_at' => $options['cancelled_at'] ?? now(),
            ]);

            foreach ($affectedPaymentIds as $paymentId) {
                $payment = Payment::query()
                    ->with([
                        'allocations' => function ($query): void {
                            $query->confirmed();
                        },
                        'credits' => function ($query): void {
                            $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                        },
                    ])
                    ->find($paymentId);

                if ($payment) {
                    $this->cancelOrphanPaymentIfSafe(
                        $payment,
                        $options['created_by'] ?? null,
                        'Pagamento revertido por reabertura canonica de fatura.'
                    );
                }
            }

            $invoice->forceFill([
                'estado_pagamento' => $targetStatus,
                'valor_pago' => 0,
                'valor_em_aberto' => round((float) $invoice->valor_total, 2),
                'data_pagamento' => null,
                'metodo_pagamento' => null,
                'referencia_pagamento' => null,
                'pagamento_observacoes' => null,
                'numero_recibo' => null,
                'recibo_emitido_em' => null,
            ]);
            $invoice->save();

            $this->fiscalDocumentRequestService->deletePendingForInvoice($invoice);

            return $invoice->refresh();
        });
    }

    private function repairStaleManualPaymentState(Invoice $invoice, array $options = []): Invoice
    {
        if (in_array($invoice->estado_pagamento, ['pago', 'parcial', 'cancelado'], true)) {
            return $invoice;
        }

        $confirmedAllocations = PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->with('payment')
            ->get();

        if ($confirmedAllocations->isEmpty()) {
            return $invoice;
        }

        $hasOnlyManualPayments = $confirmedAllocations->every(
            fn (PaymentAllocation $allocation) => $allocation->payment?->source === Payment::SOURCE_MANUAL
                && $allocation->payment?->status === Payment::STATUS_CONFIRMED
        );

        if (!$hasOnlyManualPayments) {
            return $invoice;
        }

        return $this->reverseInvoicePayments($invoice, $options);
    }

    private function getInvoicePaidAmount(Invoice $invoice): float
    {
        $allocationPaid = round((float) PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);
        $legacyEntryPaid = round((float) $this->invoicePaymentEntriesQuery()
            ->where('fatura_id', $invoice->id)
            ->sum('valor'), 2);
        $trackedPaid = in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            ? round((float) ($invoice->valor_pago ?? 0), 2)
            : 0.0;

        return round(max($allocationPaid, $legacyEntryPaid, $trackedPaid), 2);
    }

    private function getInvoiceOutstandingAmount(Invoice $invoice): float
    {
        return round(max((float) $invoice->valor_total - $this->getInvoicePaidAmount($invoice), 0), 2);
    }

    private function invoicePaymentEntriesQuery()
    {
        return FinancialEntry::query()
            ->where(function ($query): void {
                $query
                    ->where('origem_tipo', 'payment_allocation')
                    ->orWhere('origem_tipo', 'account_credit_usage')
                    ->orWhere('origem_tipo', 'manual')
                    ->orWhere(function ($legacyQuery): void {
                        $legacyQuery
                            ->whereNull('origem_tipo')
                            ->where('tipo', 'receita')
                            ->where('categoria', 'Pagamento de Fatura');
                    });
            });
    }

    private function syncPaymentBalances(Payment $payment): Payment
    {
        $allocatedAmount = round((float) PaymentAllocation::query()
            ->confirmed()
            ->where('payment_id', $payment->id)
            ->sum('amount'), 2);
        $creditedAmount = round((float) AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->sum('amount'), 2);
        $payment->forceFill([
            'allocated_amount' => $allocatedAmount,
            'unallocated_amount' => round(max((float) $payment->amount - $allocatedAmount - $creditedAmount, 0), 2),
        ]);
        $payment->save();

        return $payment->refresh();
    }

    private function createOrUpdateAllocationFinancialEntry(Payment $payment, PaymentAllocation $allocation, Invoice $invoice): FinancialEntry
    {
        $entry = FinancialEntry::query()->firstOrNew([
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
        ]);

        $entry->fill([
            'data' => $payment->payment_date ?? now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Pagamento de Fatura',
            'descricao' => sprintf(
                'Pagamento alocado a fatura %s - %s',
                $invoice->tipo,
                $invoice->user?->nome_completo ?? $invoice->user?->name ?? $invoice->id,
            ),
            'documento_ref' => $payment->reference,
            'valor' => $allocation->amount,
            'centro_custo_id' => $invoice->centro_custo_id,
            'user_id' => $payment->user_id ?? $invoice->user_id,
            'fatura_id' => $invoice->id,
            'metodo_pagamento' => $payment->method,
        ]);
        $entry->save();

        return $entry->refresh();
    }

    private function createOrUpdateReconciliationMap(
        Payment $payment,
        PaymentAllocation $allocation,
        Invoice $invoice,
        BankStatement $bankStatement,
        FinancialEntry $entry,
        ?string $previousStatus,
        array $options = [],
    ): MapaConciliacao {
        $mapa = MapaConciliacao::query()->firstOrNew([
            'payment_allocation_id' => $allocation->id,
        ]);

        $suggestionId = $options['suggestion_id'] ?? null;
        $suggestion = $suggestionId
            ? BankReconciliationSuggestion::query()->find($suggestionId)
            : null;

        $mapa->fill([
            'extrato_id' => $bankStatement->id,
            'lancamento_id' => $entry->id,
            'fatura_id' => $invoice->id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'bank_reconciliation_suggestion_id' => $suggestion?->id,
            'estado_fatura_anterior' => $previousStatus,
            'valor_conciliado' => $allocation->amount,
            'status' => 'confirmado',
            'regra_usada' => $options['map_rule'] ?? ($payment->source === Payment::SOURCE_BANK_STATEMENT
                ? 'bank_statement_allocation'
                : 'manual_payment_allocation'),
            'score' => $options['suggestion_score'] ?? $suggestion?->score,
            'metadata' => $options['map_metadata'] ?? null,
        ]);
        $mapa->save();

        return $mapa->refresh();
    }

    private function syncBankStatementStatus(BankStatement $bankStatement): BankStatement
    {
        $treatedAmount = round((float) Payment::query()
            ->confirmed()
            ->where('bank_statement_id', $bankStatement->id)
            ->get()
            ->sum(fn (Payment $payment) => (float) $payment->amount - (float) $payment->unallocated_amount), 2);
        $statementAmount = round(abs((float) $bankStatement->valor), 2);
        $remainingAmount = round(max($statementAmount - $treatedAmount, 0), 2);

        $status = 'unreconciled';
        if ($treatedAmount > 0 && $remainingAmount > 0.009) {
            $status = 'partial';
        } elseif ($statementAmount > 0 && $remainingAmount <= 0.009) {
            $status = 'reconciled';
        }

        $bankStatement->forceFill([
            'valor_conciliado' => $treatedAmount,
            'valor_por_conciliar' => $remainingAmount,
            'conciliacao_status' => $status,
            'conciliado' => $status === 'reconciled',
        ]);
        $bankStatement->save();

        return $bankStatement->refresh();
    }

    private function resolveFamilyId(?string $userId, ?string $familyId): ?string
    {
        if ($familyId) {
            return Familia::query()->whereKey($familyId)->value('id');
        }

        if (!$userId) {
            return null;
        }

        return User::query()
            ->whereKey($userId)
            ->with('families:id')
            ->first()?->families
            ->first()?->id;
    }

    private function resolveUserIdFromAllocations(array $allocations): ?string
    {
        $invoiceIds = collect($allocations)->pluck('invoice_id')->filter()->unique();
        if ($invoiceIds->isEmpty()) {
            return null;
        }

        $userIds = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->pluck('user_id')
            ->filter()
            ->unique();

        return $userIds->count() === 1 ? $userIds->first() : null;
    }

    private function isBankStatementFullyReconciled(BankStatement $bankStatement): bool
    {
        if (($bankStatement->conciliacao_status ?? null) === 'reconciled') {
            return true;
        }

        return (bool) $bankStatement->conciliado
            && round((float) ($bankStatement->valor_por_conciliar ?? 0), 2) <= 0.009;
    }

    private function cancelOrphanPaymentIfSafe(Payment $payment, ?string $cancelledBy, string $reason): void
    {
        if ($payment->status !== Payment::STATUS_CONFIRMED) {
            return;
        }

        if ($payment->allocations->isNotEmpty() || $payment->credits->isNotEmpty()) {
            return;
        }

        if (! in_array($payment->source, [
            Payment::SOURCE_MANUAL,
            Payment::SOURCE_RECONCILIATION,
            Payment::SOURCE_BANK_STATEMENT,
        ], true)) {
            return;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_CANCELLED,
            'cancelled_by' => $cancelledBy,
            'cancelled_at' => now(),
            'notes' => $this->appendRepairNote($payment->notes, $reason),
        ]);
        $payment->save();
    }

    private function appendRepairNote(?string $existingNotes, string $reason): string
    {
        $existingNotes = trim((string) $existingNotes);

        if ($existingNotes === '') {
            return $reason;
        }

        if (str_contains($existingNotes, $reason)) {
            return $existingNotes;
        }

        return $existingNotes . "\n" . $reason;
    }
}
