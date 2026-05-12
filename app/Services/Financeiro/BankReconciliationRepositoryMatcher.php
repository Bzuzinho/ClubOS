<?php

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class BankReconciliationRepositoryMatcher
{
    public function __construct(
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
    ) {
    }

    public function generateSuggestions(BankStatement $bankStatement): Collection
    {
        $matches = $this->reconciliationRepositoryService->findMatches($bankStatement);

        return $matches
            ->map(function ($match) use ($bankStatement) {
                $matchedUserIds = collect($match->matched_user_ids ?? [])
                    ->push($match->primary_user_id)
                    ->filter()
                    ->unique()
                    ->values();
                $candidateInvoices = Invoice::query()
                    ->whereIn('user_id', $matchedUserIds)
                    ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
                    ->where('tipo', 'mensalidade')
                    ->orderBy('data_vencimento')
                    ->orderBy('data_emissao')
                    ->get();
                $statementAmount = round(abs((float) $bankStatement->valor), 2);
                $remainingAmount = $statementAmount;
                $allocations = [];

                foreach ($candidateInvoices as $invoice) {
                    if ($remainingAmount <= 0.009) {
                        break;
                    }

                    $openAmount = round((float) ($invoice->valor_em_aberto ?? $invoice->valor_total), 2);
                    if ($openAmount <= 0) {
                        continue;
                    }

                    $allocatedAmount = round(min($openAmount, $remainingAmount), 2);
                    $allocations[] = [
                        'invoice_id' => $invoice->id,
                        'amount' => $allocatedAmount,
                        'partial' => $allocatedAmount + 0.009 < $openAmount,
                    ];
                    $remainingAmount = round($remainingAmount - $allocatedAmount, 2);
                }

                $score = 85 + min((int) (($match->match_count ?? 1) - 1), 10);

                return [
                    'repository_id' => $match->id,
                    'score' => $score,
                    'allocations' => $allocations,
                    'remaining_amount' => $remainingAmount,
                    'should_create_credit' => $remainingAmount > 0.009,
                    'matched_user_ids' => $matchedUserIds->all(),
                ];
            })
            ->filter(fn (array $suggestion) => $suggestion['score'] >= 85 && $suggestion['allocations'] !== [])
            ->values();
    }
}