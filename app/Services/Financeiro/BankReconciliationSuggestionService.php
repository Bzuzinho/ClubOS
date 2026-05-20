<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationSuggestionService
{
    private const MAX_INVOICES_PER_CONTEXT = 6;
    private const MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY = 80;

    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly ReconciliationAliasService $reconciliationAliasService,
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
    ) {
    }

    public function generateForBankStatement(BankStatement $bankStatement, array $options = []): Collection
    {
        $bankStatement = $bankStatement->fresh();

        if (!$bankStatement || $this->isBankStatementFullyReconciled($bankStatement)) {
            return collect();
        }

        $existingSuggestions = $this->fetchActiveSuggestions($bankStatement);
        if (($options['force_regeneration'] ?? false) !== true
            && $this->shouldReuseExistingSuggestions($existingSuggestions)) {
            return $existingSuggestions;
        }

        $statementAmount = $this->resolveRemainingAmount($bankStatement);
        if ($statementAmount <= 0.009) {
            return collect();
        }

        $normalizedText = $this->normalizeStatementText($bankStatement);
        $contexts = $this->buildCandidateContexts($bankStatement, $normalizedText, $statementAmount);
        $suggestions = collect();
        $seenSignatures = [];

        foreach ($contexts as $context) {
            $candidateInvoices = $this->fetchOpenInvoicesForContext(
                $context['user_id'] ?? null,
                $context['family_id'] ?? null,
                $context['matched_user_ids'] ?? [],
                (bool) ($context['repository_match'] ?? false),
            );

            if ($candidateInvoices->isEmpty()) {
                continue;
            }

            $historyProfile = $this->resolvePaymentHistoryProfile($context['user_id'] ?? null, $context['family_id'] ?? null);

            foreach ($this->generateCandidateInvoiceSets($candidateInvoices, $statementAmount, $bankStatement, $context, $historyProfile) as $candidateSet) {
                $signature = $this->makeAllocationSignature($candidateSet);
                if (isset($seenSignatures[$signature])) {
                    continue;
                }

                $seenSignatures[$signature] = true;

                $scoringContext = array_merge($context, [
                    'statement_amount' => $statementAmount,
                    'normalized_text' => $normalizedText,
                    'history_profile' => $historyProfile,
                ]);
                $scoreData = $this->calculateScore($bankStatement, $candidateSet, $scoringContext);

                if (($context['repository_match'] ?? false) !== true
                    && $scoreData['score'] < self::MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY) {
                    continue;
                }

                $suggestion = $this->buildSuggestion($bankStatement, $candidateSet, array_merge($context, [
                    'statement_amount' => $statementAmount,
                    'normalized_text' => $normalizedText,
                    'history_profile' => $historyProfile,
                ]), $scoreData);

                if ($suggestion->score > 0 || ($options['include_zero_score'] ?? false)) {
                    $suggestions->push($suggestion);
                }
            }
        }

        $sortedSuggestions = $suggestions
            ->sortByDesc(fn (BankReconciliationSuggestion $suggestion) => $suggestion->score)
            ->values();

        $this->expireStaleSuggestions($bankStatement, $sortedSuggestions->pluck('id')->all());

        return $sortedSuggestions;
    }

    public function generateForUnreconciled(array $filters = []): int
    {
        $query = BankStatement::query()
            ->where(function ($nestedQuery) {
                $nestedQuery
                    ->where('conciliado', false)
                    ->orWhere('conciliacao_status', 'partial')
                    ->orWhereNull('conciliacao_status');
            });

        if (!empty($filters['date_from'])) {
            $query->whereDate('data_movimento', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('data_movimento', '<=', $filters['date_to']);
        }

        if (!empty($filters['account'])) {
            $query->where('conta', $filters['account']);
        }

        if (isset($filters['min_amount'])) {
            $query->whereRaw('ABS(valor) >= ?', [abs((float) $filters['min_amount'])]);
        }

        if (isset($filters['max_amount'])) {
            $query->whereRaw('ABS(valor) <= ?', [abs((float) $filters['max_amount'])]);
        }

        $generatedCount = 0;

        $query->orderByDesc('data_movimento')
            ->chunkById(100, function (EloquentCollection $statements) use (&$generatedCount): void {
                foreach ($statements as $statement) {
                    $generatedCount += $this->generateForBankStatement($statement)->count();
                }
            });

        return $generatedCount;
    }

    public function shouldAutoGenerateForBankStatement(BankStatement $bankStatement): bool
    {
        $bankStatement = $bankStatement->fresh();

        if (!$bankStatement || $this->isBankStatementFullyReconciled($bankStatement)) {
            return false;
        }

        return !$this->shouldReuseExistingSuggestions($this->fetchActiveSuggestions($bankStatement));
    }

    public function buildSuggestion(BankStatement $bankStatement, array $candidateInvoices, array $matchedRules, ?array $scoreData = null): BankReconciliationSuggestion
    {
        $allocations = array_map(function (array $candidate): array {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];

            return [
                'invoice_id' => $invoice->id,
                'amount' => round((float) $candidate['amount'], 2),
                'reason' => $candidate['reason'] ?? null,
            ];
        }, $candidateInvoices);

        $allocationSignature = $this->makeAllocationSignature($candidateInvoices);
        $scoreData ??= $this->calculateScore($bankStatement, $candidateInvoices, $matchedRules);
        $resolvedUserId = $matchedRules['user_id'] ?? $this->resolveSingleUserIdFromCandidates($candidateInvoices);
        $resolvedFamilyId = $matchedRules['family_id'] ?? $this->resolveFamilyId($resolvedUserId);
        $existing = BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $bankStatement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->get()
            ->first(function (BankReconciliationSuggestion $suggestion) use ($allocationSignature) {
                return data_get($suggestion->metadata, 'allocation_signature') === $allocationSignature;
            });

        $suggestion = $existing ?? new BankReconciliationSuggestion();
        $totalAllocatedAmount = round(collect($allocations)->sum('amount'), 2);
        $totalBankAmount = $this->resolveRemainingAmount($bankStatement);

        $suggestion->fill([
            'bank_statement_id' => $bankStatement->id,
            'user_id' => $resolvedUserId,
            'family_id' => $resolvedFamilyId,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => $scoreData['score'],
            'confidence_label' => $scoreData['confidence_label'],
            'total_bank_amount' => $totalBankAmount,
            'total_allocated_amount' => $totalAllocatedAmount,
            'unallocated_amount' => round(max($totalBankAmount - $totalAllocatedAmount, 0), 2),
            'suggested_allocations' => $allocations,
            'matched_rules' => $scoreData['matched_rules'],
            'explanation' => $scoreData['explanation'],
            'confirmed_by' => null,
            'confirmed_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'metadata' => array_merge((array) ($suggestion->metadata ?? []), [
                'allocation_signature' => $allocationSignature,
                'normalized_text' => $matchedRules['normalized_text'] ?? $this->normalizeStatementText($bankStatement),
                'candidate_invoice_ids' => array_column($allocations, 'invoice_id'),
            ]),
        ]);
        $suggestion->save();

        return $suggestion->refresh(['bankStatement', 'user.families', 'family']);
    }

    public function calculateScore(BankStatement $bankStatement, array $candidateInvoices, array $context): array
    {
        $statementAmount = round((float) ($context['statement_amount'] ?? $this->resolveRemainingAmount($bankStatement)), 2);
        $allocatedAmount = round(collect($candidateInvoices)->sum('amount'), 2);
        $openAmount = round(collect($candidateInvoices)->sum('open_amount'), 2);
        $score = 0;
        $rules = [];
        $explanations = [];
        $difference = round($statementAmount - $allocatedAmount, 2);
        $highestOpenInvoice = round((float) collect($candidateInvoices)->max('open_amount'), 2);

        if (count($candidateInvoices) === 1 && abs($statementAmount - $openAmount) <= 0.009) {
            $score += 70;
            $rules[] = 'exact_single_invoice_amount';
            $explanations[] = 'Valor da linha bate exatamente com uma fatura em aberto.';
        } elseif (abs($statementAmount - $openAmount) <= 0.009) {
            $score += 68;
            $rules[] = 'exact_invoice_combination';
            $explanations[] = 'Valor da linha bate exatamente com a soma das faturas sugeridas.';
        } elseif ($difference > 0 && $difference <= max(5, round($statementAmount * 0.2, 2))) {
            $score += 25;
            $rules[] = 'possible_credit_overpayment';
            $explanations[] = 'Existe um excedente pequeno que pode ser convertido em credito.';
        } elseif ($difference > 0 && $allocatedAmount > 0) {
            $score += 18;
            $rules[] = 'credit_overpayment';
            $explanations[] = 'O valor excede as faturas sugeridas e pode gerar credito apos a conciliacao.';
        } elseif ($statementAmount < $highestOpenInvoice && $allocatedAmount > 0) {
            $score += 15;
            $rules[] = 'possible_partial_payment';
            $explanations[] = 'O valor permite um pagamento parcial da fatura mais provavel.';
        } else {
            $score -= 30;
            $rules[] = 'amount_mismatch';
            $explanations[] = 'O valor nao bate diretamente com as faturas sugeridas.';
        }

        if (($context['alias_confirmed'] ?? false) === true) {
            $score += 35;
            $rules[] = 'confirmed_alias';
            $explanations[] = 'Alias bancario confirmado encontrado.';
        } elseif (($context['alias_match'] ?? false) === true) {
            $score += 15;
            $rules[] = 'alias_match';
            $explanations[] = 'Alias bancario sugerido encontrado.';
        }

        if (($context['repository_match'] ?? false) === true) {
            $score += 30;
            $rules[] = 'repository_match';
            $explanations[] = 'Existe um registo confirmado anterior desta linha bancaria para este utilizador ou familia.';
        }

        if (($context['matched_name'] ?? false) === true) {
            $score += 20;
            $rules[] = 'matched_name';
            $explanations[] = 'Descricao contem o nome do utilizador ou da familia.';
        }

        if (($context['matched_nif'] ?? false) === true) {
            $score += 30;
            $rules[] = 'matched_nif';
            $explanations[] = 'Descricao ou referencia contem o NIF do utilizador.';
        }

        if (($context['matched_member_number'] ?? false) === true) {
            $score += 30;
            $rules[] = 'matched_member_number';
            $explanations[] = 'Descricao ou referencia contem o numero de socio.';
        }

        if (($context['matched_email_or_phone'] ?? false) === true) {
            $score += 15;
            $rules[] = 'matched_email_or_phone';
            $explanations[] = 'Descricao ou referencia contem email ou telefone associado.';
        }

        if ($this->hasNearbyDueDate($bankStatement, $candidateInvoices)) {
            $score += 10;
            $rules[] = 'near_due_date';
            $explanations[] = 'Existe vencimento proximo da data do movimento.';
        }

        $periodAlignment = $this->resolveMonthlyPeriodAlignment($bankStatement, $candidateInvoices);
        if ($periodAlignment['score'] !== 0) {
            $score += $periodAlignment['score'];
            if ($periodAlignment['rule'] !== null) {
                $rules[] = $periodAlignment['rule'];
            }
            if ($periodAlignment['explanation'] !== null) {
                $explanations[] = $periodAlignment['explanation'];
            }
        }

        $monthlyMixPenalty = $this->resolveMonthlyMixPenalty($bankStatement, $candidateInvoices);
        if ($monthlyMixPenalty['score'] !== 0) {
            $score += $monthlyMixPenalty['score'];
            if ($monthlyMixPenalty['rule'] !== null) {
                $rules[] = $monthlyMixPenalty['rule'];
            }
            if ($monthlyMixPenalty['explanation'] !== null) {
                $explanations[] = $monthlyMixPenalty['explanation'];
            }
        }

        $futureMonthlyPenalty = $this->resolveFutureMonthlyPenalty($bankStatement, $candidateInvoices);
        if ($futureMonthlyPenalty['score'] !== 0) {
            $score += $futureMonthlyPenalty['score'];
            if ($futureMonthlyPenalty['rule'] !== null) {
                $rules[] = $futureMonthlyPenalty['rule'];
            }
            if ($futureMonthlyPenalty['explanation'] !== null) {
                $explanations[] = $futureMonthlyPenalty['explanation'];
            }
        }

        $historicalPriority = $this->resolveHistoricalPriorityAlignment($bankStatement, $candidateInvoices, $context);
        if ($historicalPriority['score'] !== 0) {
            $score += $historicalPriority['score'];
            if ($historicalPriority['rule'] !== null) {
                $rules[] = $historicalPriority['rule'];
            }
            if ($historicalPriority['explanation'] !== null) {
                $explanations[] = $historicalPriority['explanation'];
            }
        }

        if ($this->hasSimilarPaymentHistory($context['user_id'] ?? null, $context['family_id'] ?? null, $statementAmount)) {
            $score += 20;
            $rules[] = 'similar_payment_history';
            $explanations[] = 'Ha historico de pagamentos semelhantes para este contexto.';
        }

        if ($this->matchesRecurringMonthlyPattern($candidateInvoices, $statementAmount)) {
            $score += 15;
            $rules[] = 'recurring_monthly_pattern';
            $explanations[] = 'O valor coincide com mensalidade habitual.';
        }

        if (($context['conflict_count'] ?? 0) === 0) {
            $score += 10;
            $rules[] = 'no_conflict';
            $explanations[] = 'Nao foram encontrados conflitos fortes com outros candidatos.';
        } elseif (($context['conflict_count'] ?? 0) > 0) {
            $score -= 20;
            $rules[] = 'ambiguous_candidates';
            $explanations[] = 'Existem multiplos candidatos com a mesma pista de identidade.';
        }

        if ($this->isGenericDescription($context['normalized_text'] ?? $this->normalizeStatementText($bankStatement)) && !($context['alias_match'] ?? false)) {
            $score -= 15;
            $rules[] = 'generic_description';
            $explanations[] = 'Descricao demasiado generica sem alias associado.';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'confidence_label' => $this->resolveConfidenceLabel($score),
            'matched_rules' => array_values(array_unique($rules)),
            'explanation' => implode(' ', array_values(array_unique($explanations))),
        ];
    }

    public function confirmSuggestion(BankReconciliationSuggestion $suggestion, ?User $actor = null, array $options = []): Payment
    {
        return DB::transaction(function () use ($suggestion, $actor, $options) {
            $suggestion = $suggestion->fresh(['bankStatement', 'user.families', 'family']);

            if (!$suggestion || $suggestion->status !== BankReconciliationSuggestion::STATUS_SUGGESTED) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A sugestao ja nao esta disponivel para confirmacao.',
                ]);
            }

            $bankStatement = $suggestion->bankStatement?->fresh();
            if (!$bankStatement || $this->isBankStatementFullyReconciled($bankStatement)) {
                throw ValidationException::withMessages([
                    'bank_statement' => 'A linha bancaria ja se encontra totalmente conciliada.',
                ]);
            }

            $allocations = collect((array) $suggestion->suggested_allocations)
                ->map(function (array $allocation): array {
                    return [
                        'invoice_id' => $allocation['invoice_id'] ?? null,
                        'amount' => round(abs((float) ($allocation['amount'] ?? 0)), 2),
                        'notes' => $allocation['reason'] ?? null,
                    ];
                })
                ->filter(fn (array $allocation) => !empty($allocation['invoice_id']) && $allocation['amount'] > 0)
                ->values()
                ->all();

            if ($allocations === []) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A sugestao nao contem alocacoes validas.',
                ]);
            }

            $invoiceIds = collect($allocations)->pluck('invoice_id')->all();
            $openInvoices = Invoice::query()
                ->whereIn('id', $invoiceIds)
                ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
                ->pluck('id')
                ->all();

            if (count($openInvoices) !== count($invoiceIds)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'Uma ou mais faturas da sugestao ja nao estao em aberto.',
                ]);
            }

            $payment = $this->financialSettlementService->settleInvoices($allocations, [
                'bank_statement_id' => $bankStatement->id,
                'method' => $options['method'] ?? 'transferencia',
                'reference' => $options['reference'] ?? $bankStatement->referencia,
                'description' => $options['description'] ?? $bankStatement->descricao,
                'notes' => $options['notes'] ?? $suggestion->explanation,
                'family_id' => $options['family_id'] ?? $suggestion->family_id,
                'user_id' => $options['user_id'] ?? $suggestion->user_id,
                'create_credit' => (bool) ($options['create_credit'] ?? false),
                'created_by' => $actor?->id,
                'source' => Payment::SOURCE_RECONCILIATION,
                'suggestion_id' => $suggestion->id,
                'suggestion_score' => $suggestion->score,
                'map_rule' => 'suggestion_score',
                'metadata' => [
                    'suggestion_id' => $suggestion->id,
                    'matched_rules' => $suggestion->matched_rules,
                ],
                'map_metadata' => [
                    'matched_rules' => $suggestion->matched_rules,
                    'confidence_label' => $suggestion->confidence_label,
                ],
            ]);

            $suggestion->forceFill([
                'status' => BankReconciliationSuggestion::STATUS_CONFIRMED,
                'confirmed_by' => $actor?->id,
                'confirmed_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
            $suggestion->save();

            BankReconciliationSuggestion::query()
                ->where('bank_statement_id', $bankStatement->id)
                ->whereKeyNot($suggestion->id)
                ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
                ->update([
                    'status' => BankReconciliationSuggestion::STATUS_EXPIRED,
                ]);

            $this->reconciliationAliasService->learnFromConfirmedReconciliation(
                $bankStatement,
                $payment->user_id,
                $payment->family_id,
                $actor?->id,
            );

            return $payment->fresh(['allocations.invoice', 'credits', 'bankStatement']);
        });
    }

    public function rejectSuggestion(BankReconciliationSuggestion $suggestion, ?User $actor = null, ?string $reason = null): void
    {
        $suggestion->forceFill([
            'status' => BankReconciliationSuggestion::STATUS_REJECTED,
            'rejected_by' => $actor?->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $suggestion->save();
    }

    private function buildCandidateContexts(BankStatement $bankStatement, string $normalizedText, float $statementAmount): array
    {
        $contexts = [];

        $registerContext = function (?string $userId, ?string $familyId, array $flags) use (&$contexts): void {
            $matchedUserIds = collect((array) ($flags['matched_user_ids'] ?? []))
                ->filter()
                ->map(fn ($matchedUserId) => (string) $matchedUserId)
                ->when($userId, fn (Collection $collection) => $collection->push((string) $userId))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (!$userId && !$familyId && $matchedUserIds === []) {
                return;
            }

            $key = ($userId ?: 'none') . '|' . ($familyId ?: 'none') . '|' . implode(',', $matchedUserIds ?: ['none']);
            $existing = $contexts[$key] ?? [
                'user_id' => $userId,
                'family_id' => $familyId,
                'matched_user_ids' => [],
                'repository_match' => false,
                'alias_match' => false,
                'alias_confirmed' => false,
                'matched_name' => false,
                'matched_nif' => false,
                'matched_member_number' => false,
                'matched_email_or_phone' => false,
                'conflict_count' => 0,
            ];

            foreach ($flags as $flag => $value) {
                if ($flag === 'conflict_count') {
                    $existing['conflict_count'] = max((int) ($existing['conflict_count'] ?? 0), (int) $value);
                    continue;
                }

                if ($flag === 'matched_user_ids') {
                    $existing['matched_user_ids'] = collect(array_merge(
                        (array) ($existing['matched_user_ids'] ?? []),
                        (array) $value,
                    ))
                        ->filter()
                        ->map(fn ($matchedUserId) => (string) $matchedUserId)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();
                    continue;
                }

                if ($value === true) {
                    $existing[$flag] = true;
                }
            }

            $contexts[$key] = $existing;
        };

        $repositoryMatches = $this->reconciliationRepositoryService->findMatches($bankStatement);
        foreach ($repositoryMatches as $repositoryMatch) {
            $registerContext($repositoryMatch->primary_user_id, $repositoryMatch->family_id, [
                'repository_match' => true,
                'matched_user_ids' => (array) ($repositoryMatch->matched_user_ids ?? []),
                'conflict_count' => max($repositoryMatches->count() - 1, 0),
            ]);
        }

        if ($contexts !== []) {
            return array_values($contexts);
        }

        $aliasMatches = $this->reconciliationAliasService
            ->findPossibleMatches(trim($bankStatement->descricao . ' ' . $bankStatement->referencia));

        foreach ($aliasMatches as $alias) {
            $registerContext($alias->user_id, $alias->family_id, [
                'alias_match' => true,
                'alias_confirmed' => (bool) $alias->is_confirmed,
                'conflict_count' => max($aliasMatches->count() - 1, 0),
            ]);
        }

        foreach ($this->findUsersFromStatement($normalizedText) as $userMatch) {
            /** @var User $user */
            $user = $userMatch['user'];
            $registerContext($user->id, $userMatch['family_id'], $userMatch['flags']);
        }

        foreach ($this->findFamiliesFromStatement($normalizedText) as $familyMatch) {
            $registerContext($familyMatch['user_id'] ?? null, $familyMatch['family_id'] ?? null, $familyMatch['flags'] ?? []);
        }

        foreach ($this->findGuardianFamiliesFromStatement($normalizedText) as $guardianMatch) {
            $registerContext($guardianMatch['user_id'] ?? null, $guardianMatch['family_id'] ?? null, $guardianMatch['flags'] ?? []);
        }

        if ($contexts === []) {
            foreach ($this->buildFallbackContexts($statementAmount) as $context) {
                $registerContext($context['user_id'], $context['family_id'], $context);
            }
        }

        return array_values($contexts);
    }

    private function fetchOpenInvoicesForContext(
        ?string $userId,
        ?string $familyId,
        array $matchedUserIds = [],
        bool $repositoryMatch = false
    ): Collection
    {
        $query = Invoice::query()
            ->with(['user:id,nome_completo,name,numero_socio,nif,email,email_secundario,telemovel,contacto', 'user.families:id'])
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->where('oculta', false)
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial']);

        if ($familyId) {
            $query->whereHas('user.families', function ($familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        } elseif ($matchedUserIds !== []) {
            $query->whereIn('user_id', $matchedUserIds);
        } elseif ($userId) {
            $query->where('user_id', $userId);
        }

        return $query
            ->orderByRaw("CASE WHEN estado_pagamento = 'vencido' THEN 0 WHEN estado_pagamento = 'parcial' THEN 1 ELSE 2 END")
            ->orderBy('data_vencimento')
            ->limit($repositoryMatch ? 24 : self::MAX_INVOICES_PER_CONTEXT)
            ->get()
            ->map(function (Invoice $invoice): array {
                $openAmount = $this->getInvoiceOutstandingAmount($invoice);

                return [
                    'invoice' => $invoice,
                    'open_amount' => $openAmount,
                ];
            })
            ->filter(fn (array $invoiceData) => $invoiceData['open_amount'] > 0.009)
            ->values();
    }

    private function generateCandidateInvoiceSets(
        Collection $candidateInvoices,
        float $statementAmount,
        ?BankStatement $bankStatement = null,
        array $context = [],
        array $historyProfile = []
    ): array
    {
        if (($context['repository_match'] ?? false) === true) {
            return $this->dedupeCandidateSets(
                $this->generateRepositoryCandidateInvoiceSets($candidateInvoices->all(), $statementAmount, $bankStatement)
            );
        }

        $sets = [];
        $invoiceArray = $this->prioritizeCandidateInvoices(
            $candidateInvoices,
            $statementAmount,
            $bankStatement,
            $context,
            $historyProfile,
        )->all();

        $sets = array_merge(
            $sets,
            $this->generateHistoryFirstCandidateSets($invoiceArray, $statementAmount, $bankStatement, $historyProfile)
        );

        foreach ($invoiceArray as $candidate) {
            if (abs($candidate['open_amount'] - $statementAmount) <= 0.009) {
                $sets[] = [[
                    'invoice' => $candidate['invoice'],
                    'open_amount' => $candidate['open_amount'],
                    'amount' => $candidate['open_amount'],
                    'reason' => 'valor exato em aberto',
                ]];
            }

            if ($statementAmount > $candidate['open_amount']) {
                $sets[] = [[
                    'invoice' => $candidate['invoice'],
                    'open_amount' => $candidate['open_amount'],
                    'amount' => $candidate['open_amount'],
                    'reason' => 'fatura liquidada com excedente para credito',
                ]];
            }
        }

        $sets = array_merge($sets, $this->findBestCombinationSets($invoiceArray, $statementAmount, true));
        $sets = array_merge($sets, $this->findBestCombinationSets($invoiceArray, $statementAmount, false));

        foreach ($invoiceArray as $candidate) {
            if ($statementAmount < $candidate['open_amount']) {
                $sets[] = [[
                    'invoice' => $candidate['invoice'],
                    'open_amount' => $candidate['open_amount'],
                    'amount' => $statementAmount,
                    'reason' => 'pagamento parcial provavel',
                ]];
            }
        }

        return $this->dedupeCandidateSets($sets);
    }

    private function generateRepositoryCandidateInvoiceSets(array $invoiceArray, float $statementAmount, ?BankStatement $bankStatement): array
    {
        if (!$bankStatement?->data_movimento) {
            return [];
        }

        $statementDate = $bankStatement->data_movimento;
        $dueMonthlyInvoices = collect($invoiceArray)
            ->filter(function (array $candidate) use ($statementDate): bool {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                return $this->isMonthlyInvoice($invoice)
                    && $invoice->data_vencimento
                    && $invoice->data_vencimento->lte($statementDate);
            })
            ->sortBy(fn (array $candidate) => $candidate['invoice']->data_vencimento?->format('Y-m-d') ?? '9999-12-31')
            ->values();

        if ($dueMonthlyInvoices->isEmpty()) {
            return [];
        }

        $sets = [];
        $totalDueAmount = round($dueMonthlyInvoices->sum('open_amount'), 2);

        if (abs($totalDueAmount - $statementAmount) <= 0.009) {
            $sets[] = $dueMonthlyInvoices
                ->map(fn (array $candidate): array => [
                    'invoice' => $candidate['invoice'],
                    'open_amount' => $candidate['open_amount'],
                    'amount' => $candidate['open_amount'],
                    'reason' => 'repositorio confirmado: soma das mensalidades vencidas e pendentes',
                ])
                ->all();
        } else {
            $oldestInvoice = $dueMonthlyInvoices->first();

            if ($oldestInvoice && abs($oldestInvoice['open_amount'] - $statementAmount) <= 0.009) {
                $sets[] = [[
                    'invoice' => $oldestInvoice['invoice'],
                    'open_amount' => $oldestInvoice['open_amount'],
                    'amount' => $oldestInvoice['open_amount'],
                    'reason' => 'repositorio confirmado: mensalidade mais antiga em aberto',
                ]];
            }
        }

        return $sets;
    }

    private function dedupeCandidateSets(array $sets): array
    {
        $deduped = [];
        $seen = [];
        foreach ($sets as $set) {
            $signature = $this->makeAllocationSignature($set);
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $deduped[] = $set;
        }

        return array_slice($deduped, 0, 8);
    }

    private function prioritizeCandidateInvoices(
        Collection $candidateInvoices,
        float $statementAmount,
        ?BankStatement $bankStatement,
        array $context,
        array $historyProfile
    ): Collection {
        $statementDate = $bankStatement?->data_movimento;

        return $candidateInvoices
            ->sortBy(function (array $candidate) use ($statementAmount, $statementDate, $context, $historyProfile): array {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                $sameHistoricalOrigin = $this->candidateMatchesHistoricalOrigin($candidate, $context, $historyProfile) ? 0 : 1;
                $sameMovementDate = $statementDate && $invoice->data_vencimento
                    && $invoice->data_vencimento->isSameDay($statementDate) ? 0 : 1;
                $currentOrOverdueMonthly = $this->isCurrentOrOverdueMonthlyInvoice($invoice, $statementDate) ? 0 : 1;
                $directAmountMatch = abs($candidate['open_amount'] - $statementAmount) <= 0.009 ? 0 : 1;
                $dueDate = $invoice->data_vencimento?->format('Y-m-d') ?? '9999-12-31';

                return [
                    $sameHistoricalOrigin,
                    $sameMovementDate,
                    $currentOrOverdueMonthly,
                    $directAmountMatch,
                    $dueDate,
                    (string) $invoice->id,
                ];
            })
            ->values();
    }

    private function generateHistoryFirstCandidateSets(
        array $invoiceArray,
        float $statementAmount,
        ?BankStatement $bankStatement,
        array $historyProfile
    ): array {
        if (!($historyProfile['has_records'] ?? false) || !$bankStatement?->data_movimento) {
            return [];
        }

        $statementDate = $bankStatement->data_movimento;
        $monthlyCandidates = collect($invoiceArray)
            ->filter(function (array $candidate) use ($statementDate, $historyProfile): bool {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                return $this->isCurrentOrOverdueMonthlyInvoice($invoice, $statementDate)
                    && $this->candidateMatchesHistoricalOrigin($candidate, [], $historyProfile);
            })
            ->sortBy(fn (array $candidate) => $candidate['invoice']->data_vencimento?->format('Y-m-d') ?? '9999-12-31')
            ->values();

        if ($monthlyCandidates->isEmpty()) {
            return [];
        }

        $sets = [];

        $sameDateCandidate = $monthlyCandidates->first(function (array $candidate) use ($statementAmount, $statementDate): bool {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];

            return $invoice->data_vencimento
                && $invoice->data_vencimento->isSameDay($statementDate)
                && abs($candidate['open_amount'] - $statementAmount) <= 0.009;
        });

        if ($sameDateCandidate) {
            $sets[] = [[
                'invoice' => $sameDateCandidate['invoice'],
                'open_amount' => $sameDateCandidate['open_amount'],
                'amount' => $sameDateCandidate['open_amount'],
                'reason' => 'mensalidade com vencimento no dia do movimento',
            ]];
        }

        $runningAmount = 0.0;
        $oldestPrefix = [];

        foreach ($monthlyCandidates as $candidate) {
            $oldestPrefix[] = [
                'invoice' => $candidate['invoice'],
                'open_amount' => $candidate['open_amount'],
                'amount' => $candidate['open_amount'],
                'reason' => 'mensalidades atuais e vencidas por ordem antiga',
            ];
            $runningAmount = round($runningAmount + $candidate['open_amount'], 2);

            if (abs($runningAmount - $statementAmount) <= 0.009) {
                $sets[] = $oldestPrefix;
                break;
            }

            if ($runningAmount > $statementAmount) {
                break;
            }
        }

        return $sets;
    }

    private function findBestCombinationSets(array $invoiceArray, float $statementAmount, bool $exactOnly): array
    {
        $bestSets = [];
        $totalInvoices = count($invoiceArray);
        $maxMask = min(1 << $totalInvoices, 1 << self::MAX_INVOICES_PER_CONTEXT);

        for ($mask = 1; $mask < $maxMask; $mask++) {
            if (substr_count(decbin($mask), '1') < 2) {
                continue;
            }

            $set = [];
            $sum = 0;

            for ($index = 0; $index < $totalInvoices; $index++) {
                if (($mask & (1 << $index)) === 0) {
                    continue;
                }

                $sum += $invoiceArray[$index]['open_amount'];
                $set[] = $invoiceArray[$index];
            }

            $sum = round($sum, 2);

            if ($exactOnly && abs($sum - $statementAmount) > 0.009) {
                continue;
            }

            if (!$exactOnly && ($sum > $statementAmount || abs($sum - $statementAmount) <= 0.009)) {
                continue;
            }

            $bestSets[] = array_map(function (array $candidate): array {
                return [
                    'invoice' => $candidate['invoice'],
                    'open_amount' => $candidate['open_amount'],
                    'amount' => $candidate['open_amount'],
                    'reason' => 'combinacao de faturas em aberto',
                ];
            }, $set);
        }

        usort($bestSets, function (array $left, array $right): int {
            return count($left) <=> count($right);
        });

        return array_slice($bestSets, 0, 4);
    }

    private function findUsersFromStatement(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        $compactDigits = preg_replace('/\D+/', '', $normalizedText) ?? '';
        $operator = $this->searchOperator();
        $tokens = collect(explode(' ', $normalizedText))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->take(6)
            ->values();

        $users = User::query()
            ->with([
                'families:id,nome,responsavel_user_id',
                'responsibleFamilies:id,nome,responsavel_user_id',
                'educandos:id,nome_completo,name',
                'educandos.families:id,nome,responsavel_user_id',
            ])
            ->where(function ($query) use ($tokens, $compactDigits, $operator): void {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $query
                        ->orWhere('nome_completo', $operator, $like)
                        ->orWhere('name', $operator, $like)
                        ->orWhere('email', $operator, $like)
                        ->orWhere('email_secundario', $operator, $like);
                }

                if ($compactDigits !== '') {
                    $query
                        ->orWhere('numero_socio', 'like', '%' . $compactDigits . '%')
                        ->orWhere('nif', 'like', '%' . $compactDigits . '%')
                        ->orWhere('telemovel', 'like', '%' . $compactDigits . '%')
                        ->orWhere('contacto', 'like', '%' . $compactDigits . '%');
                }
            })
            ->limit(10)
            ->get();

        return $users->map(function (User $user) use ($normalizedText, $compactDigits, $users): array {
            $educandos = $this->getGuardianStudents($user);
            $directFamilyId = $user->families->first()?->id;
            $responsibleFamilyId = $user->responsibleFamilies->first()?->id;
            $guardianFamilyId = $educandos
                ->flatMap(fn (User $educando) => $educando->families)
                ->unique('id')
                ->first()?->id;
            $familyId = $directFamilyId ?: $responsibleFamilyId ?: $guardianFamilyId;
            $name = $this->normalizer->normalize($user->nome_completo ?: $user->name);
            $nameTokens = collect(explode(' ', $name))
                ->filter(fn (string $token) => strlen($token) >= 3)
                ->values();
            $email = $this->normalizer->normalize($user->email);
            $secondaryEmail = $this->normalizer->normalize($user->email_secundario);
            $memberNumber = (string) ($user->numero_socio ?? '');
            $nif = (string) ($user->nif ?? '');
            $phoneMatches = $compactDigits !== '' && (
                str_contains((string) ($user->telemovel ?? ''), $compactDigits)
                || str_contains((string) ($user->contacto ?? ''), $compactDigits)
            );
            $matchedName = $name !== '' && (
                str_contains($normalizedText, $name)
                || ($nameTokens->isNotEmpty() && $nameTokens->every(fn (string $token) => str_contains($normalizedText, $token)))
            );

            return [
                'user' => $user,
                'family_id' => $familyId,
                'flags' => [
                    'matched_name' => $matchedName,
                    'matched_nif' => $nif !== '' && str_contains($normalizedText, $nif),
                    'matched_member_number' => $memberNumber !== '' && str_contains($normalizedText, $memberNumber),
                    'matched_email_or_phone' => ($email !== '' && str_contains($normalizedText, $email))
                        || ($secondaryEmail !== '' && str_contains($normalizedText, $secondaryEmail))
                        || $phoneMatches,
                    'conflict_count' => max($users->count() - 1, 0),
                ],
            ];
        })->filter(function (array $match): bool {
            $flags = $match['flags'] ?? [];

            return ($flags['matched_name'] ?? false)
                || ($flags['matched_nif'] ?? false)
                || ($flags['matched_member_number'] ?? false)
                || ($flags['matched_email_or_phone'] ?? false);
        })->values()->all();
    }

    private function findFamiliesFromStatement(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        $operator = $this->searchOperator();
        $tokens = collect(explode(' ', $normalizedText))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->take(6)
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $families = Familia::query()
            ->with(['responsavel:id,nome_completo,name,email,email_secundario,telemovel,contacto', 'members:id'])
            ->where(function ($query) use ($tokens, $operator): void {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $query
                        ->orWhere('nome', $operator, $like)
                        ->orWhereHas('responsavel', function ($responsavelQuery) use ($operator, $like): void {
                            $responsavelQuery
                                ->where('nome_completo', $operator, $like)
                                ->orWhere('name', $operator, $like)
                                ->orWhere('email', $operator, $like)
                                ->orWhere('email_secundario', $operator, $like);
                        });
                }
            })
            ->limit(10)
            ->get();

        return $families->map(function (Familia $family) use ($normalizedText, $families): array {
            $familyName = $this->normalizer->normalize($family->nome);
            $responsavelName = $this->normalizer->normalize($family->responsavel?->nome_completo ?: $family->responsavel?->name);
            $matchedFamilyName = $familyName !== '' && str_contains($normalizedText, $familyName);
            $matchedResponsavel = $responsavelName !== '' && str_contains($normalizedText, $responsavelName);

            return [
                'user_id' => $family->responsavel_user_id,
                'family_id' => $family->id,
                'flags' => [
                    'matched_name' => $matchedFamilyName || $matchedResponsavel,
                    'matched_user_ids' => $family->members->pluck('id')->filter()->all(),
                    'conflict_count' => max($families->count() - 1, 0),
                ],
            ];
        })->filter(function (array $match): bool {
            return (bool) ($match['flags']['matched_name'] ?? false);
        })->values()->all();
    }

    private function findGuardianFamiliesFromStatement(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        $operator = $this->searchOperator();
        $tokens = collect(explode(' ', $normalizedText))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->take(6)
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        $guardians = User::query()
            ->with(['educandos:id,nome_completo,name', 'educandos.families:id,nome,responsavel_user_id'])
            ->whereHas('educandos')
            ->where(function ($query) use ($tokens, $operator): void {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $query
                        ->orWhere('nome_completo', $operator, $like)
                        ->orWhere('name', $operator, $like)
                        ->orWhere('email', $operator, $like)
                        ->orWhere('email_secundario', $operator, $like);
                }
            })
            ->limit(10)
            ->get();

        return $guardians->flatMap(function (User $guardian) use ($normalizedText, $guardians): Collection {
            $guardianName = $this->normalizer->normalize($guardian->nome_completo ?: $guardian->name);
            $matchedGuardian = $guardianName !== '' && str_contains($normalizedText, $guardianName);

            if (!$matchedGuardian) {
                return collect();
            }

            return $this->getGuardianStudents($guardian)
                ->flatMap(fn (User $educando) => collect($educando->families ?? []))
                ->unique('id')
                ->map(function (Familia $family) use ($guardian, $guardians): array {
                    return [
                        'user_id' => $guardian->id,
                        'family_id' => $family->id,
                        'flags' => [
                            'matched_name' => true,
                            'matched_user_ids' => [$guardian->id],
                            'conflict_count' => max($guardians->count() - 1, 0),
                        ],
                    ];
                });
        })->values()->all();
    }

    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function getGuardianStudents(User $user): Collection
    {
        if ($user->relationLoaded('educandos')) {
            return collect($user->getRelation('educandos'));
        }

        return $user->educandos()
            ->with('families:id,nome,responsavel_user_id')
            ->get();
    }

    private function buildFallbackContexts(float $statementAmount): array
    {
        $invoiceCandidates = Invoice::query()
            ->with('user.families:id')
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->where('oculta', false)
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->orderBy('data_vencimento')
            ->limit(40)
            ->get()
            ->groupBy('user_id');

        $contexts = [];

        foreach ($invoiceCandidates as $userId => $userInvoices) {
            if (!$userId) {
                continue;
            }

            $normalized = $userInvoices
                ->map(fn (Invoice $invoice) => $this->getInvoiceOutstandingAmount($invoice))
                ->filter(fn (float $amount) => $amount > 0.009)
                ->values();

            if ($normalized->isEmpty()) {
                continue;
            }

            $hasDirectAmountMatch = $normalized->contains(fn (float $amount) => abs($amount - $statementAmount) <= 0.009);
            $familyId = $userInvoices->first()?->user?->families?->first()?->id;

            if ($hasDirectAmountMatch || $normalized->sum() >= $statementAmount) {
                $contexts[] = [
                    'user_id' => $userId,
                    'family_id' => $familyId,
                    'conflict_count' => 0,
                ];
            }
        }

        return array_slice($contexts, 0, 10);
    }

    private function expireStaleSuggestions(BankStatement $bankStatement, array $activeSuggestionIds): void
    {
        BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $bankStatement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->when($activeSuggestionIds !== [], fn ($query) => $query->whereNotIn('id', $activeSuggestionIds))
            ->when($activeSuggestionIds === [], fn ($query) => $query)
            ->update([
                'status' => BankReconciliationSuggestion::STATUS_EXPIRED,
            ]);
    }

    private function fetchActiveSuggestions(BankStatement $bankStatement): Collection
    {
        return BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $bankStatement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->get()
            ->values();
    }

    private function shouldReuseExistingSuggestions(Collection $existingSuggestions): bool
    {
        if ($existingSuggestions->isEmpty()) {
            return false;
        }

        $hasHighScoreSuggestion = $existingSuggestions
            ->contains(fn (BankReconciliationSuggestion $suggestion): bool => (int) ($suggestion->score ?? 0) >= self::MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY);

        if ($hasHighScoreSuggestion) {
            return true;
        }

        return $existingSuggestions
            ->every(fn (BankReconciliationSuggestion $suggestion): bool => (int) ($suggestion->score ?? 0) > 0);
    }

    private function resolveRemainingAmount(BankStatement $bankStatement): float
    {
        if ($bankStatement->valor_por_conciliar !== null) {
            return round(abs((float) $bankStatement->valor_por_conciliar), 2);
        }

        return round(max(abs((float) $bankStatement->valor) - (float) ($bankStatement->valor_conciliado ?? 0), 0), 2);
    }

    private function normalizeStatementText(BankStatement $bankStatement): string
    {
        return $this->normalizer->normalize(trim(implode(' ', array_filter([
            $bankStatement->descricao,
            $bankStatement->referencia,
        ]))));
    }

    private function getInvoiceOutstandingAmount(Invoice $invoice): float
    {
        $trackedPaidAmount = in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            ? round((float) ($invoice->valor_pago ?? 0), 2)
            : 0.0;
        $confirmedAllocations = round((float) ($invoice->confirmed_payment_allocations_sum ?? 0), 2);
        $paidAmount = max($trackedPaidAmount, $confirmedAllocations);

        return round(max((float) $invoice->valor_total - $paidAmount, 0), 2);
    }

    private function hasNearbyDueDate(BankStatement $bankStatement, array $candidateInvoices): bool
    {
        $statementDate = $bankStatement->data_movimento;
        if (!$statementDate) {
            return false;
        }

        foreach ($candidateInvoices as $candidate) {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];
            if (!$invoice->data_vencimento) {
                continue;
            }

            if (abs($invoice->data_vencimento->diffInDays($statementDate, false)) <= 10) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{score:int, rule:?string, explanation:?string}
     */
    private function resolveMonthlyPeriodAlignment(BankStatement $bankStatement, array $candidateInvoices): array
    {
        $statementMonthKey = $bankStatement->data_movimento?->format('Y-m');
        if (!$statementMonthKey) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $closestDistance = null;

        foreach ($candidateInvoices as $candidate) {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];

            if (stripos((string) $invoice->tipo, 'mens') === false) {
                continue;
            }

            $invoiceMonthKey = $invoice->data_emissao?->format('Y-m');

            if (!$invoiceMonthKey && is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
                $invoiceMonthKey = $invoice->mes;
            }

            if (!$invoiceMonthKey) {
                continue;
            }

            $distance = $this->calculateMonthDistance($statementMonthKey, $invoiceMonthKey);
            $closestDistance = $closestDistance === null ? $distance : min($closestDistance, $distance);
        }

        if ($closestDistance === null) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        if ($closestDistance === 0) {
            return [
                'score' => 18,
                'rule' => 'matching_invoice_period',
                'explanation' => 'Existe mensalidade emitida no mesmo mes do movimento.',
            ];
        }

        if ($closestDistance === 1) {
            return [
                'score' => 10,
                'rule' => 'adjacent_invoice_period',
                'explanation' => 'Existe mensalidade emitida num mes adjacente ao movimento.',
            ];
        }

        if ($closestDistance === 2) {
            return [
                'score' => -18,
                'rule' => 'stale_invoice_period',
                'explanation' => 'A mensalidade sugerida ja esta afastada do mes do movimento.',
            ];
        }

        return [
            'score' => -25,
            'rule' => 'stale_invoice_period',
            'explanation' => 'A mensalidade sugerida esta demasiado afastada da data do movimento.',
        ];
    }

    private function calculateMonthDistance(string $statementMonthKey, string $invoiceMonthKey): int
    {
        [$statementYear, $statementMonth] = array_map('intval', explode('-', $statementMonthKey));
        [$invoiceYear, $invoiceMonth] = array_map('intval', explode('-', $invoiceMonthKey));

        return abs((($statementYear * 12) + $statementMonth) - (($invoiceYear * 12) + $invoiceMonth));
    }

    /**
     * @return array{score:int, rule:?string, explanation:?string}
     */
    private function resolveMonthlyMixPenalty(BankStatement $bankStatement, array $candidateInvoices): array
    {
        if (count($candidateInvoices) <= 1) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $statementMonthKey = $bankStatement->data_movimento?->format('Y-m');
        if (!$statementMonthKey) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $distances = collect($candidateInvoices)
            ->map(function (array $candidate) use ($statementMonthKey): ?int {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                if (stripos((string) $invoice->tipo, 'mens') === false) {
                    return null;
                }

                $invoiceMonthKey = $invoice->data_emissao?->format('Y-m');

                if (!$invoiceMonthKey && is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
                    $invoiceMonthKey = $invoice->mes;
                }

                return $invoiceMonthKey ? $this->calculateMonthDistance($statementMonthKey, $invoiceMonthKey) : null;
            })
            ->filter(static fn (?int $distance) => $distance !== null)
            ->values();

        if ($distances->count() <= 1) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $penaltyUnits = $distances->sum() - $distances->min();
        if ($penaltyUnits <= 0) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $sameMonthCount = $distances->filter(static fn (int $distance) => $distance === 0)->count();
        $staleCount = $distances->filter(static fn (int $distance) => $distance > 0)->count();

        if ($sameMonthCount > 0 && $staleCount > 0) {
            return [
                'score' => -min(40, 20 + ($penaltyUnits * 8)),
                'rule' => 'stale_monthly_mix',
                'explanation' => 'A combinacao mistura a mensalidade do periodo com dividas antigas e perde prioridade para a mensalidade mais proxima do movimento.',
            ];
        }

        return [
            'score' => -min(18, $penaltyUnits * 4),
            'rule' => 'stale_monthly_mix',
            'explanation' => 'A combinacao inclui mensalidades mais antigas e perde prioridade face a periodos mais proximos do movimento.',
        ];
    }

    /**
     * @return array{score:int, rule:?string, explanation:?string}
     */
    private function resolveFutureMonthlyPenalty(BankStatement $bankStatement, array $candidateInvoices): array
    {
        $statementDate = $bankStatement->data_movimento;
        if (!$statementDate) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $statementMonthKey = $statementDate->format('Y-m');

        foreach ($candidateInvoices as $candidate) {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];

            if (!$this->isMonthlyInvoice($invoice)) {
                continue;
            }

            $invoiceMonthKey = $invoice->data_emissao?->format('Y-m');
            if (!$invoiceMonthKey && is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
                $invoiceMonthKey = $invoice->mes;
            }

            if ($invoiceMonthKey !== null && $invoiceMonthKey > $statementMonthKey) {
                return [
                    'score' => -65,
                    'rule' => 'future_monthly_invoice',
                    'explanation' => 'A sugestao inclui uma mensalidade de um periodo futuro face a data do movimento.',
                ];
            }
        }

        return ['score' => 0, 'rule' => null, 'explanation' => null];
    }

    private function hasSimilarPaymentHistory(?string $userId, ?string $familyId, float $statementAmount): bool
    {
        if (!$userId && !$familyId) {
            return false;
        }

        return Payment::query()
            ->confirmed()
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->when($familyId, fn ($query) => $query->where('family_id', $familyId))
            ->whereBetween('amount', [max($statementAmount - 0.5, 0), $statementAmount + 0.5])
            ->exists();
    }

    private function resolvePaymentHistoryProfile(?string $userId, ?string $familyId): array
    {
        if (!$userId && !$familyId) {
            return [
                'has_records' => false,
                'preferred_origins' => [],
            ];
        }

        $payments = Payment::query()
            ->confirmed()
            ->with(['allocations.invoice:id,tipo,origem_tipo'])
            ->where(function ($query) use ($userId, $familyId): void {
                if ($familyId) {
                    $query->where('family_id', $familyId);
                }

                if ($userId) {
                    $method = $familyId ? 'orWhere' : 'where';
                    $query->{$method}('user_id', $userId);
                }
            })
            ->latest('payment_date')
            ->limit(25)
            ->get();

        $preferredOrigins = $payments
            ->flatMap(fn (Payment $payment) => $payment->allocations)
            ->map(fn (PaymentAllocation $allocation) => $this->resolveInvoiceOriginKey($allocation->invoice))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(3)
            ->values()
            ->all();

        return [
            'has_records' => $payments->isNotEmpty(),
            'preferred_origins' => $preferredOrigins,
        ];
    }

    /**
     * @return array{score:int, rule:?string, explanation:?string}
     */
    private function resolveHistoricalPriorityAlignment(BankStatement $bankStatement, array $candidateInvoices, array $context): array
    {
        $historyProfile = $context['history_profile'] ?? [];
        if (!($historyProfile['has_records'] ?? false)) {
            return ['score' => 0, 'rule' => null, 'explanation' => null];
        }

        $score = 0;
        $rules = [];
        $explanations = [];

        if ($this->candidateSetMatchesHistoricalOrigin($candidateInvoices, $context, $historyProfile)) {
            $score += 18;
            $rules[] = 'historical_origin_match';
            $explanations[] = 'As faturas sugeridas seguem a origem mais habitual dos pagamentos anteriores.';
        }

        if ($this->candidateSetContainsMovementDateMatch($bankStatement, $candidateInvoices)) {
            $score += 20;
            $rules[] = 'movement_date_matches_due_monthly_fee';
            $explanations[] = 'A data do movimento coincide com a mensalidade em falta sugerida.';
        }

        if ($this->candidateSetMatchesOldestMonthlyPrefix($bankStatement, $candidateInvoices, $context, $historyProfile)) {
            $score += 24;
            $rules[] = 'oldest_due_monthly_prefix_match';
            $explanations[] = 'O valor coincide com as mensalidades atuais e vencidas por ordem da mais antiga.';
        }

        return [
            'score' => $score,
            'rule' => $rules[0] ?? null,
            'explanation' => $explanations !== [] ? implode(' ', $explanations) : null,
        ];
    }

    private function candidateSetMatchesHistoricalOrigin(array $candidateInvoices, array $context, array $historyProfile): bool
    {
        if ($candidateInvoices === []) {
            return false;
        }

        foreach ($candidateInvoices as $candidate) {
            if (!$this->candidateMatchesHistoricalOrigin($candidate, $context, $historyProfile)) {
                return false;
            }
        }

        return true;
    }

    private function candidateMatchesHistoricalOrigin(array $candidate, array $context, array $historyProfile): bool
    {
        $preferredOrigins = (array) ($historyProfile['preferred_origins'] ?? []);
        if ($preferredOrigins === []) {
            return false;
        }

        /** @var Invoice $invoice */
        $invoice = $candidate['invoice'];
        $origin = $this->resolveInvoiceOriginKey($invoice);

        if ($origin === null) {
            return false;
        }

        if (in_array($origin, $preferredOrigins, true)) {
            return true;
        }

        if (in_array('mensalidade', $preferredOrigins, true) && $this->isMonthlyInvoice($invoice)) {
            return true;
        }

        return false;
    }

    private function candidateSetContainsMovementDateMatch(BankStatement $bankStatement, array $candidateInvoices): bool
    {
        if (!$bankStatement->data_movimento) {
            return false;
        }

        foreach ($candidateInvoices as $candidate) {
            /** @var Invoice $invoice */
            $invoice = $candidate['invoice'];

            if ($invoice->data_vencimento && $invoice->data_vencimento->isSameDay($bankStatement->data_movimento)) {
                return true;
            }
        }

        return false;
    }

    private function candidateSetMatchesOldestMonthlyPrefix(
        BankStatement $bankStatement,
        array $candidateInvoices,
        array $context,
        array $historyProfile
    ): bool {
        $userId = $context['user_id'] ?? null;
        $familyId = $context['family_id'] ?? null;

        if (!$bankStatement->data_movimento || (!$userId && !$familyId) || $candidateInvoices === []) {
            return false;
        }

        $openInvoices = $this->fetchOpenInvoicesForContext($userId, $familyId)
            ->filter(function (array $candidate) use ($bankStatement, $context, $historyProfile): bool {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                return $this->isCurrentOrOverdueMonthlyInvoice($invoice, $bankStatement->data_movimento)
                    && $this->candidateMatchesHistoricalOrigin($candidate, $context, $historyProfile);
            })
            ->sortBy(fn (array $candidate) => $candidate['invoice']->data_vencimento?->format('Y-m-d') ?? '9999-12-31')
            ->values();

        if ($openInvoices->isEmpty()) {
            return false;
        }

        $candidateIds = collect($candidateInvoices)
            ->map(fn (array $candidate) => (string) $candidate['invoice']->id)
            ->values()
            ->all();

        $prefixIds = [];
        $runningAmount = 0.0;
        $targetAmount = round(collect($candidateInvoices)->sum('amount'), 2);

        foreach ($openInvoices as $candidate) {
            $prefixIds[] = (string) $candidate['invoice']->id;
            $runningAmount = round($runningAmount + $candidate['open_amount'], 2);

            if (abs($runningAmount - $targetAmount) <= 0.009) {
                return $prefixIds === $candidateIds;
            }

            if ($runningAmount > $targetAmount) {
                break;
            }
        }

        return false;
    }

    private function isMonthlyInvoice(Invoice $invoice): bool
    {
        return stripos((string) $invoice->tipo, 'mens') !== false;
    }

    private function isCurrentOrOverdueMonthlyInvoice(Invoice $invoice, ?Carbon $statementDate): bool
    {
        if (!$this->isMonthlyInvoice($invoice) || !$statementDate) {
            return false;
        }

        if ($invoice->data_vencimento && $invoice->data_vencimento->lte($statementDate)) {
            return true;
        }

        $invoiceMonthKey = $invoice->data_emissao?->format('Y-m');
        if (!$invoiceMonthKey && is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
            $invoiceMonthKey = $invoice->mes;
        }

        return $invoiceMonthKey !== null && $invoiceMonthKey <= $statementDate->format('Y-m');
    }

    private function resolveInvoiceOriginKey(?Invoice $invoice): ?string
    {
        if (!$invoice) {
            return null;
        }

        if ($this->isMonthlyInvoice($invoice)) {
            return 'mensalidade';
        }

        return $invoice->origem_tipo ?: ($invoice->tipo ?: null);
    }

    private function matchesRecurringMonthlyPattern(array $candidateInvoices, float $statementAmount): bool
    {
        if (count($candidateInvoices) !== 1) {
            return false;
        }

        /** @var Invoice $invoice */
        $invoice = $candidateInvoices[0]['invoice'];

        return stripos((string) $invoice->tipo, 'mens') !== false
            && abs((float) $invoice->valor_total - $statementAmount) <= 0.009;
    }

    private function isGenericDescription(string $normalizedText): bool
    {
        if ($normalizedText === '') {
            return true;
        }

        return in_array($normalizedText, [
            'TRANSFERENCIA',
            'TRF',
            'PAGAMENTO',
            'TRANSFERENCIA BANCARIA',
            'MBWAY',
        ], true);
    }

    private function isIgnoredIdentityToken(string $token): bool
    {
        return in_array($token, [
            'A',
            'AO',
            'COM',
            'COMPRA',
            'CREDITO',
            'DA',
            'DE',
            'DO',
            'MB',
            'MBWAY',
            'NO',
            'PAGAMENTO',
            'PARA',
            'POR',
            'REF',
            'REFERENCIA',
            'SEPA',
            'TRF',
            'TRANSFERENCIA',
        ], true);
    }

    private function resolveConfidenceLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => BankReconciliationSuggestion::CONFIDENCE_VERY_HIGH,
            $score >= 75 => BankReconciliationSuggestion::CONFIDENCE_HIGH,
            $score >= 55 => BankReconciliationSuggestion::CONFIDENCE_MEDIUM,
            default => BankReconciliationSuggestion::CONFIDENCE_LOW,
        };
    }

    private function resolveSingleUserIdFromCandidates(array $candidateInvoices): ?string
    {
        $userIds = collect($candidateInvoices)
            ->map(fn (array $candidate) => $candidate['invoice']->user_id)
            ->filter()
            ->unique()
            ->values();

        return $userIds->count() === 1 ? $userIds->first() : null;
    }

    private function resolveFamilyId(?string $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        return User::query()
            ->whereKey($userId)
            ->with('families:id')
            ->first()?->families
            ->first()?->id;
    }

    private function makeAllocationSignature(array $candidateInvoices): string
    {
        $parts = collect($candidateInvoices)
            ->map(function (array $candidate): string {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                return $invoice->id . ':' . number_format((float) $candidate['amount'], 2, '.', '');
            })
            ->sort()
            ->values()
            ->all();

        return implode('|', $parts);
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