<?php

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\FinancialEntry;
use App\Models\Movement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function __construct(
        private readonly FinancialSettlementService $financialSettlementService,
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
}