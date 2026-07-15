<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialSettlementService
{
    public const MOVEMENT_STATUS_CHANGE_BLOCK_MESSAGE = 'Este movimento já tem documento fiscal emitido. Para reabrir é necessário anular/cancelar o documento fiscal.';

    public function __construct(
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly FinancialBalanceService $financialBalanceService,
        private readonly FiscalEmissionQueueService $fiscalEmissionQueueService,
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
    ) {
    }

    public function settleInvoices(array $allocations, array $options = []): Payment
    {
        if (!empty($options['bank_statement_id'])) {
            $bankStatement = BankStatement::query()->findOrFail($options['bank_statement_id']);

            return $this->paymentAllocationService->createFromBankStatement($bankStatement, $allocations, $options);
        }

        $payment = $this->paymentAllocationService->createPayment([
            'amount' => $options['amount'] ?? collect($allocations)->sum('amount'),
            'payment_date' => $options['payment_date'] ?? now()->toDateString(),
            'method' => $options['method'] ?? null,
            'reference' => $options['reference'] ?? null,
            'description' => $options['description'] ?? null,
            'source' => $options['source'] ?? Payment::SOURCE_MANUAL,
            'user_id' => $options['user_id'] ?? null,
            'family_id' => $options['family_id'] ?? null,
            'created_by' => $options['created_by'] ?? null,
            'notes' => $options['notes'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        return $this->paymentAllocationService->allocatePayment($payment, $allocations, $options);
    }

    public function settleFinancialEntries(array $allocations, array $options = []): Payment
    {
        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Indique pelo menos uma alocacao.',
            ]);
        }

        return DB::transaction(function () use ($allocations, $options) {
            $payment = $this->resolvePayment($allocations, $options);
            $bankStatement = !empty($options['bank_statement_id'])
                ? BankStatement::query()->findOrFail($options['bank_statement_id'])
                : null;

            foreach ($allocations as $allocation) {
                $financialEntry = FinancialEntry::query()->findOrFail($allocation['financial_entry_id']);
                $amount = round(abs((float) ($allocation['amount'] ?? 0)), 2);

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Cada alocacao deve ter um valor superior a zero.',
                    ]);
                }

                $openAmount = $this->financialBalanceService->getFinancialEntryOutstandingAmount($financialEntry);
                if ($amount - $openAmount > 0.009) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Uma das alocacoes excede o valor em aberto do movimento.',
                    ]);
                }

                $paymentAllocation = PaymentAllocation::query()->create([
                    'payment_id' => $payment->id,
                    'financial_entry_id' => $financialEntry->id,
                    'amount' => $amount,
                    'status' => PaymentAllocation::STATUS_CONFIRMED,
                    'allocated_at' => $options['allocated_at'] ?? now(),
                    'created_by' => $options['created_by'] ?? null,
                    'notes' => $allocation['notes'] ?? ($options['notes'] ?? null),
                    'metadata' => $allocation['metadata'] ?? null,
                ]);

                $financialEntry = $this->financialBalanceService->recalculateFinancialEntry($financialEntry);

                if ($bankStatement) {
                    $this->createOrUpdateReconciliationMap(
                        payment: $payment,
                        allocation: $paymentAllocation,
                        financialEntry: $financialEntry,
                        bankStatement: $bankStatement,
                        options: $options,
                    );
                }

                $this->syncLegacyMovement($financialEntry, $options);

                if ($financialEntry->estado === 'pago') {
                    $this->fiscalEmissionQueueService->queueFinancialEntry($financialEntry, [
                        'paid_at' => $financialEntry->data_pagamento,
                        'bank_statement_id' => $bankStatement?->id,
                        'created_by' => $options['created_by'] ?? null,
                    ]);
                }
            }

            $payment = $this->syncPaymentBalances($payment->fresh());

            $shouldCreateCredit = (bool) ($options['create_credit'] ?? false)
                || ((float) $payment->unallocated_amount > 0 && ($payment->user_id || $payment->family_id));

            if ($shouldCreateCredit && (float) $payment->unallocated_amount > 0) {
                app(AccountCreditService::class)->createFromPaymentOverpayment($payment, null, $options);
                $payment = $this->syncPaymentBalances($payment->fresh());
            }

            if ($bankStatement) {
                $this->syncBankStatementStatus($bankStatement->fresh());
                $this->reconciliationRepositoryService->storeFromConfirmedReconciliation(
                    $bankStatement,
                    $payment,
                    $options['created_by'] ?? null,
                );
            }

            return $payment->fresh(['allocations.financialEntry', 'credits', 'bankStatement']);
        });
    }

    public function settleFinancialEntry(FinancialEntry $financialEntry, array $options = []): array
    {
        $financialEntry = $financialEntry->fresh();
        $openAmount = $this->financialBalanceService->getFinancialEntryOutstandingAmount($financialEntry);
        $amount = round(abs((float) ($options['amount'] ?? $openAmount)), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'O valor da liquidacao deve ser superior a zero.',
            ]);
        }

        $payment = $this->settleFinancialEntries([
            [
                'financial_entry_id' => $financialEntry->id,
                'amount' => $amount,
                'notes' => $options['notes'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ],
        ], array_merge([
            'amount' => $options['payment_amount'] ?? $amount,
            'payment_date' => $options['payment_date'] ?? now()->toDateString(),
            'method' => $options['method'] ?? $financialEntry->metodo_pagamento,
            'reference' => $options['reference'] ?? $financialEntry->documento_ref,
            'description' => $options['description'] ?? $financialEntry->descricao,
            'user_id' => $options['user_id'] ?? $financialEntry->user_id,
            'bank_statement_id' => $options['bank_statement_id'] ?? $financialEntry->bank_statement_id,
        ], $options));

        return [
            'financial_entry' => $financialEntry->fresh(),
            'payment' => $payment,
            'bank_statement' => !empty($options['bank_statement_id'])
                ? BankStatement::query()->find($options['bank_statement_id'])?->fresh()
                : null,
        ];
    }

    public function settleMovement(Movement $movement, array $options = []): array
    {
        $movement = $movement->fresh();
        $financialEntry = $this->findOrCreateFinancialEntryForMovement($movement, $options);
        $payment = $this->settleFinancialEntries([
            [
                'financial_entry_id' => $financialEntry->id,
                'amount' => abs((float) $movement->valor_total),
                'notes' => $options['notes'] ?? null,
            ],
        ], array_merge($options, [
            'amount' => abs((float) $movement->valor_total),
            'payment_date' => $options['payment_date'] ?? optional($movement->data_emissao)?->toDateString() ?? now()->toDateString(),
            'reference' => $options['reference'] ?? ($options['numero_recibo'] ?? null),
            'description' => $options['description'] ?? $movement->observacoes,
            'user_id' => $movement->user_id,
        ]));

        return [
            'movement' => $movement->fresh(),
            'financial_entry' => $financialEntry->fresh(),
            'payment' => $payment,
        ];
    }

    public function reopenMovement(Movement $movement, string $targetStatus, array $options = []): array
    {
        if (! in_array($targetStatus, ['pendente', 'vencido'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'O movimento so pode ser reaberto para pendente ou vencido.',
            ]);
        }

        return DB::transaction(function () use ($movement, $targetStatus, $options): array {
            $movement = $movement->fresh();

            if (! $movement) {
                throw ValidationException::withMessages([
                    'movimento' => 'Movimento invalido.',
                ]);
            }

            if (! in_array($movement->estado_pagamento, ['pago', 'parcial', 'pago_parcial'], true)) {
                throw ValidationException::withMessages([
                    'estado_pagamento' => 'A reabertura canonica so esta disponivel para movimentos pagos ou parciais.',
                ]);
            }

            $financialEntries = FinancialEntry::query()
                ->where('origem_tipo', 'movement')
                ->where('origem_id', $movement->id)
                ->orderByDesc('created_at')
                ->get();

            if ($financialEntries->isEmpty()) {
                throw ValidationException::withMessages([
                    'movimento' => 'Nao foi encontrada a entrada financeira associada ao movimento.',
                ]);
            }

            $entryIds = $financialEntries->pluck('id')->all();

            if ($this->movementHasRegisteredFiscalDocument($movement, $entryIds)) {
                throw ValidationException::withMessages([
                    'estado_pagamento' => self::MOVEMENT_STATUS_CHANGE_BLOCK_MESSAGE,
                ]);
            }

            $allocations = PaymentAllocation::query()
                ->confirmed()
                ->whereIn('financial_entry_id', $entryIds)
                ->get();

            $affectedPaymentIds = $allocations
                ->pluck('payment_id')
                ->filter()
                ->unique()
                ->values();

            $affectedBankStatementIds = Payment::query()
                ->whereIn('id', $affectedPaymentIds)
                ->whereNotNull('bank_statement_id')
                ->pluck('bank_statement_id')
                ->filter()
                ->unique()
                ->values();

            foreach ($allocations as $allocation) {
                MapaConciliacao::query()
                    ->where('payment_allocation_id', $allocation->id)
                    ->delete();

                $allocation->forceFill([
                    'status' => PaymentAllocation::STATUS_CANCELLED,
                ]);
                $allocation->save();
                $allocation->delete();
            }

            $updatedEntries = $financialEntries->map(function (FinancialEntry $entry): FinancialEntry {
                $entry = $this->financialBalanceService->recalculateFinancialEntry($entry->fresh());
                $this->deletePendingFiscalRequestsForFinancialEntry($entry);

                return $entry;
            });

            $payments = Payment::query()
                ->with([
                    'allocations' => function ($query): void {
                        $query->confirmed();
                    },
                    'credits' => function ($query): void {
                        $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                    },
                ])
                ->whereIn('id', $affectedPaymentIds)
                ->get();

            foreach ($payments as $payment) {
                $payment = $this->syncPaymentBalances($payment->fresh());
                $payment->load([
                    'allocations' => function ($query): void {
                        $query->confirmed();
                    },
                    'credits' => function ($query): void {
                        $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                    },
                ]);

                $this->cancelOrphanPaymentIfSafe(
                    $payment,
                    $options['created_by'] ?? null,
                    'Pagamento revertido por reabertura canonica de movimento.'
                );
            }

            $updatedBankStatements = $affectedBankStatementIds
                ->map(fn ($bankStatementId) => BankStatement::query()->find($bankStatementId))
                ->filter()
                ->map(fn (BankStatement $bankStatement) => $this->syncBankStatementStatus($bankStatement->fresh()))
                ->values();

            $movement->forceFill([
                'estado_pagamento' => $targetStatus,
                'estado_conciliacao' => 'nao_conciliado',
                'numero_recibo' => null,
                'referencia_pagamento' => null,
                'metodo_pagamento' => null,
            ]);
            $movement->save();

            app(MovementDocumentControlService::class)->refresh($movement->fresh());

            return [
                'movement' => $movement->fresh(),
                'financial_entry' => $updatedEntries->firstWhere('id', $financialEntries->first()->id)?->fresh(),
                'payments' => $payments->map(fn (Payment $payment) => $payment->fresh())->all(),
                'bank_statements' => $updatedBankStatements->all(),
            ];
        });
    }

    public function findOrCreateFinancialEntryForMovement(Movement $movement, array $options = []): FinancialEntry
    {
        $financialEntry = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->latest('created_at')
            ->first();

        $financialEntry ??= new FinancialEntry();
        $financialEntry->fill([
            'data' => optional($movement->data_emissao)?->toDateString() ?? now()->toDateString(),
            'tipo' => $movement->classificacao,
            'categoria' => $options['categoria'] ?? 'Movimento Financeiro',
            'descricao' => $options['description'] ?? trim((string) ($movement->observacoes ?: ('Movimento ' . $movement->tipo))),
            'documento_ref' => $options['reference'] ?? ($options['numero_recibo'] ?? $movement->numero_recibo),
            'valor' => abs((float) $movement->valor_total),
            'valor_pago' => $financialEntry->exists ? $financialEntry->valor_pago : 0,
            'valor_em_aberto' => $financialEntry->exists ? $financialEntry->valor_em_aberto : abs((float) $movement->valor_total),
            'estado' => $financialEntry->exists ? $financialEntry->estado : $this->normalizeFinancialEntryState($movement->estado_pagamento),
            'centro_custo_id' => $movement->centro_custo_id,
            'user_id' => $movement->user_id,
            'entidade_nome' => $movement->nome_manual ?: ($movement->classificacao === 'receita' ? 'BSCN Receita' : 'BSCN Despesa'),
            'documento_original' => $movement->documento_original,
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
            'metodo_pagamento' => $options['method'] ?? $movement->metodo_pagamento,
            'comprovativo' => $options['comprovativo'] ?? $movement->comprovativo,
        ]);
        $financialEntry->save();

        return $financialEntry->refresh();
    }

    private function resolvePayment(array $allocations, array $options): Payment
    {
        if (!empty($options['bank_statement_id'])) {
            $existing = Payment::query()
                ->confirmed()
                ->where('bank_statement_id', $options['bank_statement_id'])
                ->latest('created_at')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return $this->paymentAllocationService->createPayment([
            'user_id' => $options['user_id'] ?? $this->resolveSingleUserId($allocations),
            'family_id' => $options['family_id'] ?? null,
            'bank_statement_id' => $options['bank_statement_id'] ?? null,
            'amount' => $options['amount'] ?? collect($allocations)->sum('amount'),
            'payment_date' => $options['payment_date'] ?? now()->toDateString(),
            'method' => $options['method'] ?? null,
            'reference' => $options['reference'] ?? null,
            'description' => $options['description'] ?? null,
            'source' => $options['source'] ?? (!empty($options['bank_statement_id']) ? Payment::SOURCE_BANK_STATEMENT : Payment::SOURCE_MANUAL),
            'status' => Payment::STATUS_CONFIRMED,
            'created_by' => $options['created_by'] ?? null,
            'notes' => $options['notes'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);
    }

    private function resolveSingleUserId(array $allocations): ?string
    {
        $userIds = collect($allocations)
            ->pluck('financial_entry_id')
            ->filter()
            ->map(fn (string $entryId) => FinancialEntry::query()->whereKey($entryId)->value('user_id'))
            ->filter()
            ->unique()
            ->values();

        return $userIds->count() === 1 ? $userIds->first() : null;
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
        ])->save();

        return $payment->refresh();
    }

    private function createOrUpdateReconciliationMap(
        Payment $payment,
        PaymentAllocation $allocation,
        FinancialEntry $financialEntry,
        BankStatement $bankStatement,
        array $options = [],
    ): MapaConciliacao {
        $mapa = MapaConciliacao::query()->firstOrNew([
            'payment_allocation_id' => $allocation->id,
        ]);

        $mapa->fill([
            'extrato_id' => $bankStatement->id,
            'lancamento_id' => $financialEntry->id,
            'movimento_id' => $financialEntry->origem_tipo === 'movement' ? $financialEntry->origem_id : null,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'estado_movimento_anterior' => $options['previous_status'] ?? null,
            'valor_conciliado' => $allocation->amount,
            'status' => 'confirmado',
            'regra_usada' => $options['map_rule'] ?? ($payment->source === Payment::SOURCE_BANK_STATEMENT
                ? 'bank_statement_settlement'
                : 'manual'),
            'score' => $options['suggestion_score'] ?? null,
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
        ])->save();

        return $bankStatement->refresh();
    }

    private function movementHasRegisteredFiscalDocument(Movement $movement, array $entryIds): bool
    {
        if (filled($movement->numero_recibo)) {
            return true;
        }

        return FiscalDocumentRequest::query()
            ->whereIn('financial_entry_id', $entryIds)
            ->where(function ($query): void {
                $query
                    ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                    ->orWhere(function ($documentQuery): void {
                        $documentQuery
                            ->whereNotNull('external_document_number')
                            ->where('external_document_number', '!=', '');
                    });
            })
            ->exists();
    }

    private function deletePendingFiscalRequestsForFinancialEntry(FinancialEntry $financialEntry): void
    {
        FiscalDocumentRequest::query()
            ->where('financial_entry_id', $financialEntry->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_document_number')
                    ->orWhere('external_document_number', '');
            })
            ->delete();
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

    private function syncLegacyMovement(FinancialEntry $financialEntry, array $options = []): void
    {
        if ($financialEntry->origem_tipo !== 'movement' || !$financialEntry->origem_id) {
            return;
        }

        $movement = Movement::query()->find($financialEntry->origem_id);
        if (!$movement) {
            return;
        }

        $movement->fill([
            'estado_pagamento' => $this->normalizeMovementState($financialEntry->estado),
            'estado_conciliacao' => !empty($options['bank_statement_id']) ? 'conciliado' : ($movement->estado_conciliacao ?: 'nao_conciliado'),
            'numero_recibo' => $options['numero_recibo'] ?? $movement->numero_recibo,
            'metodo_pagamento' => $financialEntry->metodo_pagamento,
            'comprovativo' => $options['comprovativo'] ?? $movement->comprovativo,
        ]);
        $movement->save();

        app(MovementDocumentControlService::class)->refresh($movement->fresh());
    }

    private function normalizeFinancialEntryState(?string $movementState): string
    {
        return match ($movementState) {
            'pago' => 'pago',
            'pago_parcial', 'parcial' => 'parcial',
            'cancelado' => 'cancelado',
            default => 'pendente',
        };
    }

    private function normalizeMovementState(?string $entryState): string
    {
        return match ($entryState) {
            'pago' => 'pago',
            'parcial' => 'pago_parcial',
            'cancelado' => 'cancelado',
            default => 'por_pagar',
        };
    }
}
