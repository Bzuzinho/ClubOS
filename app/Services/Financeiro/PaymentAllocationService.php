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
                $createdCredit = $this->createOrUpdateCredit($payment, $createdBy, $options);
                $payment->forceFill([
                    'unallocated_amount' => 0,
                ])->save();
                $payment = $this->syncPaymentBalances($payment->fresh());
                $this->createOrUpdateCreditFinancialEntry($payment, $createdCredit);
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

    private function createOrUpdateCreditFinancialEntry(Payment $payment, AccountCredit $credit): FinancialEntry
    {
        $entry = FinancialEntry::query()->firstOrNew([
            'origem_tipo' => 'account_credit',
            'origem_id' => $credit->id,
        ]);

        $entry->fill([
            'data' => $payment->payment_date ?? now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Credito em Conta Corrente',
            'descricao' => 'Excedente convertido em credito de conta corrente',
            'documento_ref' => $payment->reference,
            'valor' => $credit->amount,
            'centro_custo_id' => null,
            'user_id' => $credit->user_id,
            'fatura_id' => null,
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

    private function createOrUpdateCredit(Payment $payment, ?string $createdBy, array $options = []): AccountCredit
    {
        $amount = round((float) $payment->unallocated_amount, 2);

        $credit = AccountCredit::query()->firstOrNew([
            'payment_id' => $payment->id,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);

        $credit->fill([
            'user_id' => $payment->user_id,
            'family_id' => $payment->family_id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'source' => 'overpayment',
            'status' => AccountCredit::STATUS_AVAILABLE,
            'description' => $options['credit_description'] ?? 'Excedente de pagamento convertido em credito.',
            'created_by' => $createdBy,
        ]);
        $credit->save();

        return $credit->refresh();
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
}