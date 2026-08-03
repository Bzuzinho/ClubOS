<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    private const UNRECONCILE_FISCAL_BLOCK_MESSAGE = 'Existe documento fiscal emitido. E necessario anular/cancelar fiscalmente antes de desconciliar.';

    public function __construct(
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly FinancialBalanceService $financialBalanceService,
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
        private readonly ReconciliationAliasService $reconciliationAliasService,
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
    ) {
    }

    public function reconcile(BankStatement $bankStatement, array $payload, array $options = []): array
    {
        $items = collect($payload['itens'] ?? []);
        $createdPayments = collect();
        $updatedInvoices = collect();
        $updatedMovements = collect();
        $createdEntries = collect();

        if ($items->isEmpty()) {
            $manualEntry = FinancialEntry::query()->create([
                'data' => $bankStatement->data_movimento,
                'tipo' => $payload['tipo'],
                'categoria' => $payload['categoria'] ?? 'Conciliacao Manual',
                'descricao' => $payload['descricao'] ?? $bankStatement->descricao,
                'documento_ref' => $bankStatement->referencia,
                'valor' => abs((float) $bankStatement->valor),
                'valor_pago' => 0,
                'valor_em_aberto' => abs((float) $bankStatement->valor),
                'estado' => 'pendente',
                'centro_custo_id' => $payload['centro_custo_id'],
                'user_id' => $payload['user_id'] ?? null,
                'entidade_nome' => $payload['entidade_nome'] ?? null,
                'bank_statement_id' => $bankStatement->id,
                'origem_tipo' => 'manual',
                'origem_modulo' => 'financeiro',
                'origem_id' => null,
                'metodo_pagamento' => $payload['metodo_pagamento'] ?? 'transferencia',
            ]);

            $payment = $this->financialSettlementService->settleFinancialEntries([
                [
                    'financial_entry_id' => $manualEntry->id,
                    'amount' => abs((float) $bankStatement->valor),
                ],
            ], array_merge($options, [
                'bank_statement_id' => $bankStatement->id,
                'amount' => abs((float) $bankStatement->valor),
                'payment_date' => $bankStatement->data_movimento,
                'method' => $payload['metodo_pagamento'] ?? 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $bankStatement->descricao,
                'user_id' => $payload['user_id'] ?? null,
                'create_credit' => (bool) ($payload['create_credit'] ?? false),
                'map_metadata' => [
                    'created_manual_entry' => true,
                ],
            ]));

            $bankStatement->forceFill([
                'lancamento_id' => $manualEntry->id,
            ])->save();

            return [
                'bank_statement' => $bankStatement->fresh(),
                'payments' => [$payment],
                'entries' => [$manualEntry->fresh()],
                'invoices' => [],
                'movements' => [],
            ];
        }

        $invoiceAllocations = [];
        $entryAllocations = [];

        foreach ($items as $item) {
            $type = $item['tipo'] ?? null;
            $id = $item['id'] ?? null;
            $amount = round(abs((float) ($item['valor'] ?? 0)), 2);

            if ($amount <= 0) {
                continue;
            }

            if ($type === 'fatura') {
                $invoiceAllocations[] = [
                    'invoice_id' => $id,
                    'amount' => $amount,
                ];
                continue;
            }

            if ($type === 'movimento') {
                $movement = Movement::query()->find($id);
                if (!$movement) {
                    throw ValidationException::withMessages([
                        'itens' => 'Foi indicado um movimento invalido.',
                    ]);
                }

                $entry = $this->financialSettlementService->findOrCreateFinancialEntryForMovement($movement, [
                    'categoria' => 'Movimento Financeiro',
                    'method' => $payload['metodo_pagamento'] ?? 'transferencia',
                ]);
                $createdEntries->push($entry);
                $entryAllocations[] = [
                    'financial_entry_id' => $entry->id,
                    'amount' => $amount,
                ];
                continue;
            }

            if ($type === 'financial_entry') {
                $entry = FinancialEntry::query()->find($id);
                if (!$entry) {
                    throw ValidationException::withMessages([
                        'itens' => 'Foi indicada uma entrada financeira invalida.',
                    ]);
                }

                $createdEntries->push($entry);
                $entryAllocations[] = [
                    'financial_entry_id' => $entry->id,
                    'amount' => $amount,
                ];
            }
        }

        if ($invoiceAllocations !== []) {
            $payment = $this->financialSettlementService->settleInvoices($invoiceAllocations, array_merge($options, [
                'bank_statement_id' => $bankStatement->id,
                'method' => $payload['metodo_pagamento'] ?? 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $bankStatement->descricao,
                'create_credit' => (bool) ($payload['create_credit'] ?? false),
            ]));
            $createdPayments->push($payment);
            $updatedInvoices = $payment->allocations->pluck('invoice')->filter()->values();
        }

        if ($entryAllocations !== []) {
            $payment = $this->financialSettlementService->settleFinancialEntries($entryAllocations, array_merge($options, [
                'bank_statement_id' => $bankStatement->id,
                'method' => $payload['metodo_pagamento'] ?? 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $bankStatement->descricao,
                'create_credit' => (bool) ($payload['create_credit'] ?? false),
                'user_id' => $payload['user_id'] ?? null,
            ]));
            $createdPayments->push($payment);
            $updatedMovements = collect($payment->allocations)
                ->pluck('financialEntry')
                ->filter()
                ->filter(fn (FinancialEntry $entry) => $entry->origem_tipo === 'movement' && $entry->origem_id)
                ->map(fn (FinancialEntry $entry) => Movement::query()->find($entry->origem_id))
                ->filter()
                ->values();
        }

        $resolvedEntries = $createdEntries
            ->merge(collect($createdPayments)->flatMap(fn ($payment) => $payment->allocations->pluck('financialEntry')))
            ->filter()
            ->unique('id')
            ->values();

        if ($resolvedEntries->count() === 1) {
            $bankStatement->forceFill([
                'lancamento_id' => $resolvedEntries->first()->id,
            ])->save();
        }

        return [
            'bank_statement' => $bankStatement->fresh(),
            'payments' => $createdPayments->values()->all(),
            'entries' => $resolvedEntries->all(),
            'invoices' => $updatedInvoices->all(),
            'movements' => $updatedMovements->all(),
        ];
    }

    public function unreconcile(BankStatement $bankStatement, array $options = []): array
    {
        // Remove alocações de recibos relacionadas antes de desconciliar
        $this->cleanupReceiptAllocationsFor($bankStatement);

        return DB::transaction(function () use ($bankStatement, $options): array {
            $bankStatement = $bankStatement->fresh();
            $maps = MapaConciliacao::query()
                ->where('extrato_id', $bankStatement->id)
                ->with(['lancamento'])
                ->get();

            $payments = Payment::query()
                ->confirmed()
                ->where('bank_statement_id', $bankStatement->id)
                ->with('credits')
                ->get();

            $paymentIds = $payments->pluck('id');

            $this->ensureCreditsCanBeUnreconciled($paymentIds);

            $allocations = PaymentAllocation::query()
                ->confirmed()
                ->whereIn('payment_id', $paymentIds)
                ->with(['payment', 'invoice', 'financialEntry'])
                ->get();

            $affectedInvoiceIds = [];
            $affectedEntryIds = [];
            $removedEntryIds = [];

            foreach ($allocations as $allocation) {
                if ($allocation->invoice_id) {
                    $affectedInvoiceIds[$allocation->invoice_id] = $allocation->invoice_id;
                }

                if ($allocation->financial_entry_id) {
                    $affectedEntryIds[$allocation->financial_entry_id] = $allocation->financial_entry_id;
                }

                if ($allocation->financialEntry?->origem_tipo === 'movement' && $allocation->financialEntry->id) {
                    $affectedEntryIds[$allocation->financialEntry->id] = $allocation->financialEntry->id;
                }
            }

            $this->ensureFiscalDocumentsCanBeUnreconciled(
                array_values($affectedInvoiceIds),
                array_values($affectedEntryIds),
            );

            foreach ($payments as $payment) {
                $this->reconciliationAliasService->forgetFromUnreconciledPayment($bankStatement, $payment);
                $this->reconciliationRepositoryService->forgetFromUnreconciledPayment($bankStatement, $payment);
            }

            foreach ($allocations as $allocation) {

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

            $this->cancelCreditsForPayments($payments, $removedEntryIds);

            $manualEntries = $maps
                ->filter(fn (MapaConciliacao $mapa) => (bool) data_get($mapa->metadata, 'created_manual_entry', false))
                ->pluck('lancamento')
                ->filter(fn ($entry) => $entry instanceof FinancialEntry)
                ->unique('id');

            foreach ($manualEntries as $entry) {
                $removedEntryIds[] = $entry->id;
                unset($affectedEntryIds[$entry->id]);
                $entry->delete();
            }

            MapaConciliacao::query()
                ->where('extrato_id', $bankStatement->id)
                ->delete();

            $updatedInvoices = collect($affectedInvoiceIds)
                ->map(fn ($invoiceId) => Invoice::query()->find($invoiceId))
                ->filter()
                ->map(function (Invoice $invoice): Invoice {
                    $invoice->forceFill([
                        'valor_pago' => 0,
                        'valor_em_aberto' => round((float) $invoice->valor_total, 2),
                        'data_pagamento' => null,
                        'metodo_pagamento' => null,
                        'referencia_pagamento' => null,
                        'pagamento_observacoes' => null,
                        'estado_pagamento' => 'pendente',
                    ]);
                    $invoice->save();

                    $invoice = $this->paymentAllocationService->recalculateInvoicePaymentStatus($invoice->fresh());

                    if ($invoice->estado_pagamento !== 'pago') {
                        $this->fiscalDocumentRequestService->deletePendingForInvoice($invoice);
                    }

                    return $invoice;
                })
                ->values();

            $updatedEntries = collect($affectedEntryIds)
                ->map(fn ($entryId) => FinancialEntry::query()->find($entryId))
                ->filter()
                ->map(function (FinancialEntry $entry): FinancialEntry {
                    $entry = $this->financialBalanceService->recalculateFinancialEntry($entry->fresh());
                    $this->deletePendingFiscalRequestsForFinancialEntry($entry);

                    return $entry;
                })
                ->values();

            $updatedMovements = $updatedEntries
                ->map(fn (FinancialEntry $entry) => $this->syncMovementFromFinancialEntry($entry))
                ->filter()
                ->values();

            foreach ($payments as $payment) {
                $this->syncPaymentBalances($payment->fresh());
            }

            $bankStatement = $this->syncBankStatementStatus($bankStatement->fresh());
            $bankStatement->forceFill([
                'lancamento_id' => null,
            ])->save();

            $this->rejectActiveSuggestionsForStatement($bankStatement, $options['created_by'] ?? null);

            return [
                'bank_statement' => $bankStatement->fresh(),
                'payments' => $payments->map(fn (Payment $payment) => $payment->fresh())->all(),
                'entries' => $updatedEntries->all(),
                'invoices' => $updatedInvoices->all(),
                'movements' => $updatedMovements->all(),
                'removed_entry_ids' => array_values(array_unique($removedEntryIds)),
            ];
        });
    }

    private function ensureCreditsCanBeUnreconciled(Collection $paymentIds): void
    {
        if ($paymentIds->isEmpty()) {
            return;
        }

        $hasUsedCredits = AccountCredit::query()
            ->whereIn('payment_id', $paymentIds)
            ->whereIn('status', [
                AccountCredit::STATUS_PARTIALLY_USED,
                AccountCredit::STATUS_USED,
            ])
            ->exists();

        if ($hasUsedCredits) {
            throw ValidationException::withMessages([
                'extrato' => 'Nao e possivel desconciliar um extrato com credito de conta corrente ja utilizado.',
            ]);
        }
    }

    private function ensureFiscalDocumentsCanBeUnreconciled(array $invoiceIds, array $financialEntryIds): void
    {
        $hasIssuedInvoiceDocument = $invoiceIds !== []
            && FiscalDocumentRequest::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->whereNotNull('external_document_number')
                ->where('external_document_number', '!=', '')
                ->exists();

        if ($hasIssuedInvoiceDocument) {
            throw ValidationException::withMessages([
                'extrato' => self::UNRECONCILE_FISCAL_BLOCK_MESSAGE,
            ]);
        }

        $hasIssuedEntryDocument = $financialEntryIds !== []
            && FiscalDocumentRequest::query()
                ->whereIn('financial_entry_id', $financialEntryIds)
                ->whereNotNull('external_document_number')
                ->where('external_document_number', '!=', '')
                ->exists();

        if ($hasIssuedEntryDocument) {
            throw ValidationException::withMessages([
                'extrato' => self::UNRECONCILE_FISCAL_BLOCK_MESSAGE,
            ]);
        }
    }

    private function cancelCreditsForPayments(Collection $payments, array &$removedEntryIds): void
    {
        foreach ($payments as $payment) {
            $credits = $payment->credits
                ->filter(fn (AccountCredit $credit) => $credit->status !== AccountCredit::STATUS_CANCELLED);

            foreach ($credits as $credit) {
                $credit->forceFill([
                    'status' => AccountCredit::STATUS_CANCELLED,
                    'remaining_amount' => 0,
                ]);
                $credit->save();
                $credit->delete();

                FinancialEntry::query()
                    ->where('origem_tipo', 'account_credit')
                    ->where('origem_id', $credit->id)
                    ->get()
                    ->each(function (FinancialEntry $entry) use (&$removedEntryIds): void {
                        $removedEntryIds[] = $entry->id;
                        $entry->delete();
                    });
            }
        }
    }

    private function deletePendingFiscalRequestsForFinancialEntry(FinancialEntry $financialEntry): void
    {
        if ($financialEntry->estado === 'pago') {
            return;
        }

        FiscalDocumentRequest::query()
            ->where('financial_entry_id', $financialEntry->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_document_number')
                    ->orWhere('external_document_number', '');
            })
            ->delete();
    }

    /**
     * Remove todas as alocações de recibos associadas a um extrato bancário.
     * Restaura os recibos importados ao estado anterior.
     *
     * @param  \App\Models\BankStatement  $statement
     * @return void
     */
    private function cleanupReceiptAllocationsFor(BankStatement $statement): void
    {
        $allocations = \App\Models\BankTransactionAllocation::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', \App\Models\BankTransactionAllocation::STATUS_CONFIRMED)
            ->get();

        foreach ($allocations as $allocation) {
            // Marca como cancelada
            $allocation->update([
                'status' => \App\Models\BankTransactionAllocation::STATUS_CANCELLED,
            ]);

            // Se existir recibo relacionado, volta a estado anterior
            if ($allocation->receipt_import_id) {
                $receiptImport = $allocation->receiptImport;
                if ($receiptImport) {
                    $receiptImport->update([
                        'status' => \App\Models\ReceiptImport::STATUS_PENDING,
                    ]);
                }
            }
        }
    }

    private function rejectActiveSuggestionsForStatement(BankStatement $bankStatement, ?string $userId = null): void
    {
        BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $bankStatement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->update([
                'status' => BankReconciliationSuggestion::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => 'Sugestao invalidada por desconciliacao manual do extrato.',
            ]);
    }

    private function syncMovementFromFinancialEntry(FinancialEntry $financialEntry): ?Movement
    {
        if ($financialEntry->origem_tipo !== 'movement' || !$financialEntry->origem_id) {
            return null;
        }

        $movement = Movement::query()->find($financialEntry->origem_id);
        if (!$movement) {
            return null;
        }

        $movement->fill([
            'estado_pagamento' => $financialEntry->estado,
            'metodo_pagamento' => $financialEntry->metodo_pagamento,
        ]);
        $movement->save();

        return $movement->fresh();
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

        return $payment->fresh();
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

        return $bankStatement->fresh();
    }
}
