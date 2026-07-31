<?php

namespace App\Services\Financeiro;

use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Members\MemberFiscalDataResolver;
use App\Services\Members\MemberPersonalDataColumnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankReconciliationSuggestionService
{
    private const MAX_INVOICES_PER_CONTEXT = 6;
    private const MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY = 80;
    private const REFERENCE_MONTH_NAMES = [
        'janeiro' => 1,
        'fevereiro' => 2,
        'marco' => 3,
        'abril' => 4,
        'maio' => 5,
        'junho' => 6,
        'julho' => 7,
        'agosto' => 8,
        'setembro' => 9,
        'outubro' => 10,
        'novembro' => 11,
        'dezembro' => 12,
    ];

    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly ReconciliationAliasService $reconciliationAliasService,
        private readonly ReconciliationRepositoryService $reconciliationRepositoryService,
        private readonly MemberFiscalDataResolver $memberFiscalDataResolver,
    ) {
    }

    public function generateForBankStatement(BankStatement $bankStatement, array $options = []): Collection
    {
        $bankStatement = $bankStatement->fresh();
        $forceRegeneration = (bool) ($options['force_regeneration'] ?? false);

        if (
            !$bankStatement
            || abs((float) $bankStatement->valor) <= 0.009
            || $this->isBankStatementFullyReconciled($bankStatement)
        ) {
            return collect();
        }

        if ((float) $bankStatement->valor < 0) {
            $suggestions = $this->generateExpenseSuggestions($bankStatement, $options);
            $this->markSuggestionsAnalyzed($bankStatement);

            return $suggestions;
        }

        $existingSuggestions = $this->fetchActiveSuggestions($bankStatement);
        $unsafePersistedSuggestionIds = $existingSuggestions
            ->filter(fn (BankReconciliationSuggestion $suggestion): bool =>
                (int) ($suggestion->score ?? 0) > 0
                && !$this->hasPersistedIdentityEvidence($suggestion)
            )
            ->pluck('id')
            ->all();

        if ($unsafePersistedSuggestionIds !== []) {
            BankReconciliationSuggestion::query()
                ->whereIn('id', $unsafePersistedSuggestionIds)
                ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
                ->update(['status' => BankReconciliationSuggestion::STATUS_EXPIRED]);

            $existingSuggestions = $existingSuggestions
                ->reject(fn (BankReconciliationSuggestion $suggestion): bool =>
                    in_array($suggestion->id, $unsafePersistedSuggestionIds, true)
                )
                ->values();
        }

        if (!$forceRegeneration && $this->shouldReuseExistingSuggestions($existingSuggestions)) {
            $this->markSuggestionsAnalyzed($bankStatement);

            return $existingSuggestions;
        }

        $rejectedAllocationSignatures = $forceRegeneration
            ? []
            : $this->fetchRejectedAllocationSignatures($bankStatement);

        $statementAmount = $this->resolveRemainingAmount($bankStatement);
        if ($statementAmount <= 0.009) {
            $this->markSuggestionsAnalyzed($bankStatement);

            return collect();
        }

        $normalizedText = $this->normalizeStatementText($bankStatement);
        $contexts = $this->buildCandidateContexts($bankStatement, $normalizedText, $statementAmount);
        $suggestions = $this->generateReceiptMovementSuggestions(
            $bankStatement,
            $statementAmount,
            $normalizedText,
            $rejectedAllocationSignatures,
            $forceRegeneration,
        );
        $seenSignatures = $suggestions
            ->mapWithKeys(fn (BankReconciliationSuggestion $suggestion): array => [
                $this->makeAllocationSignatureFromAllocations((array) $suggestion->suggested_allocations) => true,
            ])
            ->all();

        foreach ($contexts as $context) {
            if (!$this->hasClearIdentityEvidence($context)) {
                continue;
            }

            $candidateInvoices = $this->fetchOpenInvoicesForContext(
                $context['user_id'] ?? null,
                $context['family_id'] ?? null,
                $context['matched_user_ids'] ?? [],
                (bool) ($context['repository_match'] ?? false),
            );

            $legacyPaidSuggestion = $this->generateLegacyPaidInvoiceSuggestion(
                $bankStatement,
                $context,
                $statementAmount,
                $normalizedText,
                $rejectedAllocationSignatures,
                $forceRegeneration,
            );
            if ($legacyPaidSuggestion) {
                $legacySignature = $this->makeAllocationSignatureFromAllocations(
                    (array) $legacyPaidSuggestion->suggested_allocations,
                );
                if (!isset($seenSignatures[$legacySignature])) {
                    $seenSignatures[$legacySignature] = true;
                    $suggestions->push($legacyPaidSuggestion);
                }
            }

            if ($candidateInvoices->isEmpty()) {
                continue;
            }

            $historyProfile = $this->resolvePaymentHistoryProfile($context['user_id'] ?? null, $context['family_id'] ?? null);
            $hasEqualReceiptMovement = $this->hasEqualOpenReceiptMovementForContext(
                $context['user_id'] ?? null,
                $context['family_id'] ?? null,
                $statementAmount,
            );
            $referenceMonthSequence = $this->buildReferenceMonthChronologicalSequence(
                $candidateInvoices,
                $statementAmount,
                $bankStatement,
                $context,
            );

            foreach ($this->generateCandidateInvoiceSets($candidateInvoices, $statementAmount, $bankStatement, $context, $historyProfile, $referenceMonthSequence) as $candidateSetPayload) {
                $candidateSet = $candidateSetPayload['candidate_set'] ?? $candidateSetPayload;
                $candidateContext = $candidateSetPayload['context'] ?? [];
                $signature = $this->makeAllocationSignature($candidateSet);
                if (isset($seenSignatures[$signature])) {
                    continue;
                }

                $seenSignatures[$signature] = true;

                if (!$forceRegeneration && isset($rejectedAllocationSignatures[$signature])) {
                    continue;
                }

                $scoringContext = array_merge($context, [
                    'statement_amount' => $statementAmount,
                    'normalized_text' => $normalizedText,
                    'history_profile' => $historyProfile,
                    'has_equal_receipt_movement' => $hasEqualReceiptMovement,
                    'reference_month_available' => $referenceMonthSequence !== null
                        && (int) data_get($referenceMonthSequence, '0.reference_month_total_months', 0) > 1,
                ], $candidateContext);
                $scoreData = $this->calculateScore($bankStatement, $candidateSet, $scoringContext);

                if (($context['repository_match'] ?? false) !== true
                    && $scoreData['score'] < self::MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY
                    && empty($candidateContext['reference_month_context'])) {
                    continue;
                }

                $suggestion = $this->buildSuggestion($bankStatement, $candidateSet, array_merge($context, [
                    'statement_amount' => $statementAmount,
                    'normalized_text' => $normalizedText,
                    'history_profile' => $historyProfile,
                ], $candidateContext), $scoreData);

                if ($suggestion->score > 0 || ($options['include_zero_score'] ?? false)) {
                    $suggestions->push($suggestion);
                }
            }
        }

        $sortedSuggestions = $suggestions
            ->sortBy(function (BankReconciliationSuggestion $suggestion): array {
                $hasReferenceMonthRule = collect((array) ($suggestion->matched_rules ?? []))
                    ->contains(fn (string $rule): bool => str_starts_with($rule, 'reference_month_sequence_'));

                return [
                    (int) $suggestion->score,
                    $hasReferenceMonthRule ? 1 : 0,
                    (int) ($suggestion->total_allocated_amount ?? 0),
                ];
            })
            ->reverse()
            ->values();

        $this->expireStaleSuggestions($bankStatement, $sortedSuggestions->pluck('id')->all());
        $this->markSuggestionsAnalyzed($bankStatement);

        return $sortedSuggestions;
    }

    public function generateForUnreconciled(array $filters = []): int
    {
        $query = BankStatement::query()
            ->where('valor', '!=', 0)
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

        if (
            !$bankStatement
            || abs((float) $bankStatement->valor) <= 0.009
            || $this->isBankStatementFullyReconciled($bankStatement)
        ) {
            return false;
        }

        $existingSuggestions = $this->fetchActiveSuggestions($bankStatement);

        if ((float) $bankStatement->valor > 0 && $existingSuggestions->contains(
            fn (BankReconciliationSuggestion $suggestion): bool =>
                (int) ($suggestion->score ?? 0) > 0
                && !$this->hasPersistedIdentityEvidence($suggestion)
        )) {
            return true;
        }

        return !$this->shouldReuseExistingSuggestions($existingSuggestions);
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
                'reference_month_context' => $matchedRules['reference_month_context'] ?? $this->extractReferenceMonthContext($candidateInvoices),
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
        $referenceMonthContext = $context['reference_month_context'] ?? $this->extractReferenceMonthContext($candidateInvoices);
        $hasReferenceMonthContext = is_array($referenceMonthContext) && (int) ($referenceMonthContext['total_months'] ?? 0) > 1;
        $referenceMonthAvailable = (bool) ($context['reference_month_available'] ?? false);

        if ($referenceMonthAvailable && !$hasReferenceMonthContext) {
            $score -= 40;
            $rules[] = 'reference_month_sequence_lower_priority';
            $explanations[] = 'Existe uma sequencia cronologica mensal mais adequada para este contexto.';
        }

        if ($hasReferenceMonthContext) {
            $score += 100;
            $rules[] = !empty($referenceMonthContext['insufficient'])
                ? 'reference_month_sequence_partial'
                : 'reference_month_sequence_full';
            $explanations[] = (string) ($referenceMonthContext['insufficient']
                ? sprintf(
                    'Existem mensalidades em aberto ate %s, mas o valor da linha so cobre %d de %d mensalidades.',
                    $referenceMonthContext['reference_month_label'] ?? '',
                    (int) ($referenceMonthContext['covered_months'] ?? 0),
                    (int) ($referenceMonthContext['total_months'] ?? 0)
                )
                : sprintf(
                    'Valor cobre mensalidades em aberto ate %s.',
                    $referenceMonthContext['reference_month_label'] ?? ''
                ));
        } elseif (count($candidateInvoices) === 1 && abs($statementAmount - $openAmount) <= 0.009) {
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
            if ($this->hasClearIdentityEvidence($context)) {
                $score += 35;
                $rules[] = 'confirmed_alias';
                $explanations[] = 'Alias bancario confirmado encontrado.';
            } else {
                $score -= 10;
                $rules[] = 'confirmed_alias_without_clear_target';
                $explanations[] = 'Alias confirmado sem evidencia suficiente do alvo. Requer validacao manual.';
            }
        } elseif (($context['alias_match'] ?? false) === true) {
            if ($this->hasClearIdentityEvidence($context)) {
                $score += 15;
                $rules[] = 'alias_match';
                $explanations[] = 'Alias bancario sugerido encontrado.';
            } else {
                $score -= 10;
                $rules[] = 'alias_without_clear_target';
                $explanations[] = 'Alias bancario encontrado sem evidencias adicionais suficientes; requer validacao manual.';
            }
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

        $referenceMonthSignal = $this->resolveReferenceMonthSignal($candidateInvoices);
        if (!$hasReferenceMonthContext && $referenceMonthSignal !== null) {
            if ($this->hasClearIdentityEvidence($context) || ($context['repository_match'] ?? false)) {
                $score += $referenceMonthSignal['is_full'] ? 20 : 12;
                $rules[] = $referenceMonthSignal['is_full']
                    ? 'reference_month_sequence_full'
                    : 'reference_month_sequence_partial';
            } else {
                $score -= 8;
                $score = min($score, 74);
                $rules[] = 'reference_month_sequence_without_identity';
            }

            $explanations[] = $referenceMonthSignal['message'];
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

        if ($hasReferenceMonthContext && !$this->hasClearIdentityEvidence($context) && !($context['repository_match'] ?? false)) {
            $score = min($score, 74);
        }

        $score = max(0, min(100, $score));
        $isCompleteExactSettlement = abs($statementAmount - $allocatedAmount) <= 0.009
            && abs($openAmount - $allocatedAmount) <= 0.009;

        if (!$isCompleteExactSettlement) {
            $score = min($score, 99);
        }

        if (($context['has_equal_receipt_movement'] ?? false) === true) {
            $score = min($score, 99);
            $rules[] = 'equal_receipt_movement_requires_review';
            $explanations[] = 'Existe tambem um movimento faturado em aberto com o mesmo valor; confirme manualmente o alvo.';
        }

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

            if (
                (int) $suggestion->score !== 100
                || round((float) $suggestion->unallocated_amount, 2) > 0.009
            ) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A conciliacao direta exige uma sugestao exata com score de 100%. Use a alocacao assistida.',
                ]);
            }

            if ((float) $bankStatement->valor < 0) {
                return $this->confirmExpenseSuggestion($suggestion, $bankStatement, $actor, $options);
            }

            $movementAllocations = collect((array) $suggestion->suggested_allocations)
                ->filter(fn (array $allocation): bool => !empty($allocation['movement_id']))
                ->values();
            if ($movementAllocations->isNotEmpty()) {
                if (
                    $movementAllocations->count() !== 1
                    || collect((array) $suggestion->suggested_allocations)->count() !== 1
                ) {
                    throw ValidationException::withMessages([
                        'suggestion' => 'A sugestao direta de movimento deve identificar uma unica receita.',
                    ]);
                }

                $allocation = $movementAllocations->first();
                $availableAmount = $this->resolveRemainingAmount($bankStatement);
                if (abs((float) $allocation['amount'] - $availableAmount) > 0.009) {
                    throw ValidationException::withMessages([
                        'suggestion' => 'A receita sugerida nao cobre integralmente o valor bancario.',
                    ]);
                }

                $movement = Movement::query()->findOrFail($allocation['movement_id']);
                $payment = $this->financialSettlementService->settleMixedAllocations(
                    $bankStatement,
                    [[
                        'movement_id' => $movement->id,
                        'amount' => $availableAmount,
                        'centro_custo_id' => $movement->centro_custo_id,
                        'notes' => $allocation['reason'] ?? $suggestion->explanation,
                        'metadata' => ['suggestion_id' => $suggestion->id],
                    ]],
                    [
                        'method' => $options['method'] ?? 'transferencia',
                        'reference' => $options['reference'] ?? $bankStatement->referencia,
                        'description' => $options['description'] ?? $bankStatement->descricao,
                        'notes' => $options['notes'] ?? $suggestion->explanation,
                        'family_id' => $options['family_id'] ?? $suggestion->family_id,
                        'user_id' => $options['user_id'] ?? $suggestion->user_id,
                        'created_by' => $actor?->id,
                        'source' => Payment::SOURCE_RECONCILIATION,
                        'suggestion_id' => $suggestion->id,
                        'suggestion_score' => $suggestion->score,
                        'map_rule' => 'receipt_movement_suggestion_score',
                        'map_metadata' => [
                            'suggestion_id' => $suggestion->id,
                            'matched_rules' => $suggestion->matched_rules,
                        ],
                    ],
                );

                $this->finalizeConfirmedSuggestion(
                    $suggestion,
                    $actor,
                    $payment->user_id ?: $movement->user_id,
                    $payment->family_id ?: $this->resolveFamilyId($movement->user_id),
                    $bankStatement,
                );

                return $payment->fresh(['allocations.financialEntry', 'bankStatement']);
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
            if (data_get($suggestion->metadata, 'target_type') === 'legacy_paid_invoice') {
                if (count($invoiceIds) !== 1 || count($allocations) !== 1) {
                    throw ValidationException::withMessages([
                        'suggestion' => 'A conciliação de legado exige uma única mensalidade paga.',
                    ]);
                }

                $invoice = Invoice::query()->findOrFail($invoiceIds[0]);
                $payment = $this->financialSettlementService->reconcileLegacyPaidInvoice(
                    $bankStatement,
                    $invoice,
                    [
                        'method' => $options['method'] ?? 'transferencia',
                        'reference' => $options['reference'] ?? $bankStatement->referencia,
                        'description' => $options['description'] ?? $bankStatement->descricao,
                        'notes' => $options['notes'] ?? $suggestion->explanation,
                        'created_by' => $actor?->id,
                        'suggestion_id' => $suggestion->id,
                        'suggestion_score' => $suggestion->score,
                    ],
                );

                $this->finalizeConfirmedSuggestion(
                    $suggestion,
                    $actor,
                    $payment->user_id ?: $invoice->user_id,
                    $payment->family_id ?: $this->resolveFamilyId($invoice->user_id),
                    $bankStatement,
                );

                return $payment->fresh(['allocations.invoice', 'bankStatement']);
            }

            $openInvoices = Invoice::query()
                ->withSum([
                    'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                        $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                    },
                ], 'amount')
                ->whereIn('id', $invoiceIds)
                ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
                ->get()
                ->keyBy('id');

            if ($openInvoices->count() !== count($invoiceIds)) {
                throw ValidationException::withMessages([
                    'suggestion' => 'Uma ou mais faturas da sugestao ja nao estao em aberto.',
                ]);
            }

            $containsPartialSettlement = collect($allocations)->contains(function (array $allocation) use ($openInvoices): bool {
                $invoice = $openInvoices->get($allocation['invoice_id']);

                return !$invoice
                    || abs($this->getInvoiceOutstandingAmount($invoice) - (float) $allocation['amount']) > 0.009;
            });
            $allocatedTotal = round(collect($allocations)->sum('amount'), 2);
            $availableAmount = $this->resolveRemainingAmount($bankStatement);

            if ($containsPartialSettlement || abs($allocatedTotal - $availableAmount) > 0.009) {
                throw ValidationException::withMessages([
                    'suggestion' => 'A conciliacao direta so pode liquidar integralmente as obrigacoes identificadas e todo o valor bancario.',
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

            $this->finalizeConfirmedSuggestion(
                $suggestion,
                $actor,
                $payment->user_id,
                $payment->family_id,
                $bankStatement,
            );

            return $payment->fresh(['allocations.invoice', 'credits', 'bankStatement']);
        });
    }

    public function finalizeConfirmedSuggestion(
        BankReconciliationSuggestion $suggestion,
        ?User $actor,
        ?string $resolvedUserId,
        ?string $resolvedFamilyId,
        BankStatement $bankStatement,
        bool $learnAlias = true,
    ): void {
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

        if ($learnAlias) {
            $this->reconciliationAliasService->learnFromConfirmedReconciliation(
                $bankStatement,
                $resolvedUserId,
                $resolvedFamilyId,
                $actor?->id,
            );
        }
    }

    public function buildAssistedAllocationContext(BankReconciliationSuggestion $suggestion): ?array
    {
        $suggestion = $suggestion->fresh([
            'bankStatement',
            'user:id,nome_completo,name',
            'user.families:id,nome',
            'family:id,nome',
        ]);

        $bankStatement = $suggestion?->bankStatement?->fresh();
        if (!$suggestion || !$bankStatement || $this->isBankStatementFullyReconciled($bankStatement)) {
            return null;
        }

        $availableAmount = round(max((float) ($bankStatement->valor_por_conciliar ?? abs((float) $bankStatement->valor)), 0), 2);
        if ($availableAmount <= 0.009) {
            return null;
        }

        $referenceMonth = $this->resolveAssistedReferenceMonth($suggestion, $bankStatement);
        $userId = $suggestion->user_id;
        $familyId = $suggestion->family_id;

        if (!$userId && !$familyId) {
            $seedInvoiceId = collect((array) $suggestion->suggested_allocations)
                ->pluck('invoice_id')
                ->filter()
                ->first();

            if ($seedInvoiceId) {
                $seedInvoice = Invoice::query()->with(['user.families:id,nome'])->find($seedInvoiceId);
                $userId = $seedInvoice?->user_id;
                $familyId = $seedInvoice?->user?->families?->first()?->id;
            }
        }

        $eligibleInvoices = $this->fetchEligibleInvoicesForAssistedAllocation($userId, $familyId, $referenceMonth);
        $eligibleMovements = $this->fetchEligibleMovementsForAssistedAllocation($userId, $familyId);
        $defaultAllocations = $this->buildDefaultAssistedAllocations($availableAmount, $eligibleInvoices, $eligibleMovements);

        $creditTargetType = $userId ? 'user' : ($familyId ? 'family' : null);

        return [
            'reference_month' => $referenceMonth,
            'matched_user_id' => $userId,
            'matched_family_id' => $familyId,
            'available_amount' => $availableAmount,
            'eligible_invoices' => $eligibleInvoices,
            'eligible_movements' => $eligibleMovements,
            'can_create_credit' => $creditTargetType !== null,
            'credit_target_type' => $creditTargetType,
            'default_allocations' => $defaultAllocations,
        ];
    }

    public function rejectSuggestion(BankReconciliationSuggestion $suggestion, ?User $actor = null, ?string $reason = null): void
    {
        $rejectionSnapshot = [
            'allocation_signature' => $this->makeAllocationSignatureFromAllocations((array) ($suggestion->suggested_allocations ?? [])),
            'score' => (int) ($suggestion->score ?? 0),
            'confidence_label' => $suggestion->confidence_label,
            'matched_rules' => $suggestion->matched_rules,
            'explanation' => $suggestion->explanation,
            'reason' => $reason,
            'rejected_by' => $actor?->id,
            'rejected_at' => now()->toIso8601String(),
        ];

        $suggestion->forceFill([
            'status' => BankReconciliationSuggestion::STATUS_REJECTED,
            'rejected_by' => $actor?->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'metadata' => array_merge((array) ($suggestion->metadata ?? []), [
                'rejection_snapshot' => $rejectionSnapshot,
                'allocation_signature' => $rejectionSnapshot['allocation_signature'],
            ]),
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

        return collect($contexts)
            ->filter(fn (array $context): bool => $this->hasClearIdentityEvidence($context))
            ->values()
            ->all();
    }

    private function generateReceiptMovementSuggestions(
        BankStatement $bankStatement,
        float $statementAmount,
        string $normalizedStatementText,
        array $rejectedAllocationSignatures,
        bool $forceRegeneration,
    ): Collection {
        $candidates = $this->whereMovementAbsoluteAmount(
            Movement::query()
                ->with([
                    'user:id,nome_completo,name,numero_socio,nif',
                    'user.families:id,nome',
                    'centroCusto:id,nome',
                ])
                ->where('classificacao', 'receita')
                ->whereNull('supplier_id')
                ->whereIn('estado_pagamento', ['pendente', 'por_pagar', 'vencido', 'parcial', 'pago_parcial']),
            $statementAmount,
        )
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get();

        $candidateCount = $candidates->count();
        $strongCandidateCount = $candidates
            ->filter(fn (Movement $movement): bool =>
                $this->countMovementMatchingTokens($movement, $normalizedStatementText) >= 2
            )
            ->count();
        $existingBySignature = $this->fetchActiveSuggestions($bankStatement)
            ->keyBy(fn (BankReconciliationSuggestion $suggestion): string =>
                (string) data_get($suggestion->metadata, 'allocation_signature', '')
            );
        $equalInvoiceCache = [];

        return $candidates
            ->map(function (Movement $movement) use (
                $bankStatement,
                $statementAmount,
                $normalizedStatementText,
                $candidateCount,
                $strongCandidateCount,
                $rejectedAllocationSignatures,
                $forceRegeneration,
                $existingBySignature,
                &$equalInvoiceCache,
            ): ?BankReconciliationSuggestion {
                $allocation = [
                    'movement_id' => $movement->id,
                    'amount' => $statementAmount,
                    'reason' => 'Valor exato de um movimento de receita em aberto.',
                ];
                $signature = $this->makeAllocationSignatureFromAllocations([$allocation]);

                if (!$forceRegeneration && isset($rejectedAllocationSignatures[$signature])) {
                    return null;
                }

                $familyId = $movement->user?->families?->first()?->id;
                $matchingTokens = $this->countMovementMatchingTokens($movement, $normalizedStatementText);
                $movementDate = $movement->data_vencimento ?: $movement->data_emissao;
                $sameMonth = $movementDate
                    && $bankStatement->data_movimento
                    && $movementDate->format('Y-m') === $bankStatement->data_movimento->format('Y-m');
                $identityKey = (string) ($movement->user_id ?: 'club') . '|' . (string) ($familyId ?: 'none');
                if (!array_key_exists($identityKey, $equalInvoiceCache)) {
                    $equalInvoiceCache[$identityKey] = $this->fetchOpenInvoicesForContext($movement->user_id, $familyId)
                        ->contains(fn (array $candidate): bool =>
                            abs((float) $candidate['open_amount'] - $statementAmount) <= 0.009
                        );
                }
                $hasEqualInvoice = $equalInvoiceCache[$identityKey];

                $score = 60;
                $rules = ['exact_receipt_movement_amount'];
                $explanations = ['O valor bancario coincide exatamente com um movimento de receita em aberto.'];

                if ($matchingTokens >= 2) {
                    $score += 20;
                    $rules[] = 'receipt_movement_description_strong_match';
                    $explanations[] = 'A descricao bancaria identifica o atleta, familia ou descricao do movimento.';
                } elseif ($matchingTokens === 1) {
                    $score += 10;
                    $rules[] = 'receipt_movement_description_match';
                    $explanations[] = 'A descricao bancaria contem uma pista do movimento.';
                }

                if ($sameMonth) {
                    $score += 10;
                    $rules[] = 'receipt_movement_same_month';
                    $explanations[] = 'O movimento faturado pertence ao mesmo mes da entrada bancaria.';
                }

                if ($candidateCount === 1) {
                    $score += 10;
                    $rules[] = 'unique_exact_receipt_movement';
                } elseif ($matchingTokens >= 2 && $strongCandidateCount === 1) {
                    $score += 10;
                    $rules[] = 'unique_strong_receipt_movement_match';
                    $explanations[] = 'Entre os movimentos com o mesmo valor, apenas este coincide fortemente com a descricao bancaria.';
                } else {
                    $rules[] = 'multiple_exact_receipt_movements';
                    $explanations[] = sprintf('Existem %d movimentos de receita em aberto com este valor.', $candidateCount);
                }

                if ($hasEqualInvoice) {
                    $rules[] = 'equal_invoice_requires_review';
                    $explanations[] = 'Existe tambem uma fatura em aberto com o mesmo valor; confirme manualmente o alvo.';
                }

                $isCertain = $strongCandidateCount === 1
                    && $matchingTokens >= 2
                    && $sameMonth
                    && !$hasEqualInvoice;
                $score = max(0, min($isCertain ? 100 : 99, $score));

                if ($score < self::MIN_SCORE_TO_PERSIST_WITHOUT_HISTORY) {
                    return null;
                }

                $existing = $existingBySignature->get($signature);
                $suggestion = $existing ?? new BankReconciliationSuggestion();
                $suggestion->fill([
                    'bank_statement_id' => $bankStatement->id,
                    'user_id' => $movement->user_id,
                    'family_id' => $familyId,
                    'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
                    'score' => $score,
                    'confidence_label' => $this->resolveConfidenceLabel($score),
                    'total_bank_amount' => $statementAmount,
                    'total_allocated_amount' => $statementAmount,
                    'unallocated_amount' => 0,
                    'suggested_allocations' => [$allocation],
                    'matched_rules' => $rules,
                    'explanation' => implode(' ', $explanations),
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                    'metadata' => array_merge((array) ($suggestion->metadata ?? []), [
                        'allocation_signature' => $signature,
                        'normalized_text' => $normalizedStatementText,
                        'target_type' => 'receipt_movement',
                        'candidate_movement_ids' => [$movement->id],
                    ]),
                ]);
                $suggestion->save();

                return $suggestion->refresh(['bankStatement', 'user.families', 'family']);
            })
            ->filter()
            ->sortByDesc('score')
            ->values();
    }

    private function hasEqualOpenReceiptMovementForContext(
        ?string $userId,
        ?string $familyId,
        float $statementAmount,
    ): bool {
        if (!$userId && !$familyId) {
            return false;
        }

        return $this->whereMovementAbsoluteAmount(
            Movement::query()
                ->where('classificacao', 'receita')
                ->whereNull('supplier_id')
                ->whereIn('estado_pagamento', ['pendente', 'por_pagar', 'vencido', 'parcial', 'pago_parcial']),
            $statementAmount,
        )
            ->where(function ($identityQuery) use ($userId, $familyId): void {
                if ($userId) {
                    $identityQuery->where('user_id', $userId);
                }

                if ($familyId) {
                    $method = $userId ? 'orWhereHas' : 'whereHas';
                    $identityQuery->{$method}('user.families', function ($familyQuery) use ($familyId): void {
                        $familyQuery->where('familias.id', $familyId);
                    });
                }
            })
            ->exists();
    }

    private function generateExpenseSuggestions(BankStatement $bankStatement, array $options = []): Collection
    {
        $forceRegeneration = (bool) ($options['force_regeneration'] ?? false);
        $existingSuggestions = $this->fetchActiveSuggestions($bankStatement);

        if (!$forceRegeneration && $this->shouldReuseExistingSuggestions($existingSuggestions)) {
            return $existingSuggestions;
        }

        $rejectedAllocationSignatures = $forceRegeneration
            ? []
            : $this->fetchRejectedAllocationSignatures($bankStatement);
        $statementAmount = $this->resolveRemainingAmount($bankStatement);
        $normalizedStatementText = $this->normalizeStatementText($bankStatement);

        $candidates = $this->whereMovementAbsoluteAmount(
            Movement::query()
                ->with([
                    'user:id,nome_completo,name',
                    'user.families:id,nome',
                    'supplier:id,nome,nif',
                    'centroCusto:id,nome',
                ])
                ->where('classificacao', 'despesa')
                ->whereIn('estado_pagamento', ['pendente', 'por_pagar', 'vencido', 'parcial', 'pago_parcial']),
            $statementAmount,
        )
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get();

        $candidateCount = $candidates->count();
        $strongCandidateCount = $candidates
            ->filter(fn (Movement $movement): bool =>
                $this->countMovementMatchingTokens($movement, $normalizedStatementText) >= 2
            )
            ->count();
        $existingBySignature = $existingSuggestions
            ->keyBy(fn (BankReconciliationSuggestion $suggestion): string =>
                (string) data_get($suggestion->metadata, 'allocation_signature', '')
            );
        $suggestions = $candidates
            ->map(function (Movement $movement) use (
                $bankStatement,
                $statementAmount,
                $normalizedStatementText,
                $candidateCount,
                $strongCandidateCount,
                $rejectedAllocationSignatures,
                $forceRegeneration,
                $existingBySignature,
            ): ?BankReconciliationSuggestion {
                $allocation = [
                    'movement_id' => $movement->id,
                    'amount' => $statementAmount,
                    'reason' => 'Valor exato de uma despesa em aberto.',
                ];
                $signature = $this->makeAllocationSignatureFromAllocations([$allocation]);

                if (!$forceRegeneration && isset($rejectedAllocationSignatures[$signature])) {
                    return null;
                }

                $scoreData = $this->calculateExpenseScore(
                    $bankStatement,
                    $movement,
                    $normalizedStatementText,
                    $statementAmount,
                    $candidateCount,
                    $strongCandidateCount,
                );
                $existing = $existingBySignature->get($signature);
                $suggestion = $existing ?? new BankReconciliationSuggestion();

                $suggestion->fill([
                    'bank_statement_id' => $bankStatement->id,
                    'user_id' => $movement->user_id,
                    'family_id' => $this->resolveFamilyId($movement->user_id),
                    'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
                    'score' => $scoreData['score'],
                    'confidence_label' => $scoreData['confidence_label'],
                    'total_bank_amount' => $statementAmount,
                    'total_allocated_amount' => $statementAmount,
                    'unallocated_amount' => 0,
                    'suggested_allocations' => [$allocation],
                    'matched_rules' => $scoreData['matched_rules'],
                    'explanation' => $scoreData['explanation'],
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                    'metadata' => array_merge((array) ($suggestion->metadata ?? []), [
                        'allocation_signature' => $signature,
                        'normalized_text' => $normalizedStatementText,
                        'target_type' => 'expense_movement',
                        'candidate_movement_ids' => [$movement->id],
                    ]),
                ]);
                $suggestion->save();

                return $suggestion->refresh(['bankStatement', 'user.families', 'family']);
            })
            ->filter()
            ->sortByDesc('score')
            ->values();

        $this->expireStaleSuggestions($bankStatement, $suggestions->pluck('id')->all());

        return $suggestions;
    }

    private function whereMovementAbsoluteAmount(Builder $query, float $amount): Builder
    {
        $minimum = max(round($amount - 0.009, 3), 0);
        $maximum = round($amount + 0.009, 3);

        return $query->where(function (Builder $amountQuery) use ($minimum, $maximum): void {
            $amountQuery
                ->where(function (Builder $positiveQuery) use ($minimum, $maximum): void {
                    $positiveQuery
                        ->where('valor_total', '>=', $minimum)
                        ->where('valor_total', '<=', $maximum);
                })
                ->orWhere(function (Builder $negativeQuery) use ($minimum, $maximum): void {
                    $negativeQuery
                        ->where('valor_total', '>=', -$maximum)
                        ->where('valor_total', '<=', -$minimum);
                });
        });
    }

    /**
     * @return array{score:int,confidence_label:string,matched_rules:array<int,string>,explanation:string}
     */
    private function calculateExpenseScore(
        BankStatement $bankStatement,
        Movement $movement,
        string $normalizedStatementText,
        float $statementAmount,
        int $candidateCount,
        int $strongCandidateCount,
    ): array {
        $score = 70;
        $rules = ['exact_expense_amount'];
        $explanations = ['O valor da saida bancaria coincide exatamente com uma despesa em aberto.'];

        $matchingTokens = $this->countMovementMatchingTokens($movement, $normalizedStatementText);

        if ($matchingTokens >= 2) {
            $score += 20;
            $rules[] = 'expense_description_strong_match';
            $explanations[] = 'A descricao bancaria coincide com a entidade ou descricao da despesa.';
        } elseif ($matchingTokens === 1) {
            $score += 10;
            $rules[] = 'expense_description_match';
            $explanations[] = 'A descricao bancaria contem uma pista da despesa.';
        }

        $movementDate = $movement->data_vencimento ?: $movement->data_emissao;
        if ($bankStatement->data_movimento && $movementDate) {
            $days = abs($movementDate->diffInDays($bankStatement->data_movimento, false));
            if ($days <= 31) {
                $score += 10;
                $rules[] = 'expense_date_near_statement';
                $explanations[] = 'A data da despesa e a data bancaria sao compativeis.';
            }
        }

        if ($candidateCount === 1) {
            $score += 10;
            $rules[] = 'unique_exact_expense';
            $explanations[] = 'Nao existe outra despesa em aberto com o mesmo valor.';
        } elseif ($matchingTokens >= 2 && $strongCandidateCount === 1) {
            $rules[] = 'unique_strong_expense_match';
            $explanations[] = 'Entre as despesas com o mesmo valor, apenas esta coincide fortemente com a descricao bancaria.';
        } else {
            $rules[] = 'multiple_exact_expenses';
            $explanations[] = sprintf('Existem %d despesas em aberto com este valor; confirme manualmente a correta.', $candidateCount);
        }

        $hasCertainIdentity = $strongCandidateCount === 1 && $matchingTokens >= 2;
        if (
            !$hasCertainIdentity
            || abs(abs((float) $movement->valor_total) - $statementAmount) > 0.009
        ) {
            $score = min($score, 99);
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'confidence_label' => $this->resolveConfidenceLabel($score),
            'matched_rules' => $rules,
            'explanation' => implode(' ', $explanations),
        ];
    }

    private function countMovementMatchingTokens(Movement $movement, string $normalizedStatementText): int
    {
        $movementText = $this->normalizer->normalize(trim(implode(' ', array_filter([
            $movement->observacoes,
            $movement->categoria,
            $movement->nome_manual,
            $movement->referencia_pagamento,
            $movement->supplier?->nome,
            $movement->supplier?->nif,
            $movement->user?->nome_completo ?: $movement->user?->name,
            $movement->user?->numero_socio,
            $movement->user?->nif,
            $movement->user?->families?->pluck('nome')->implode(' '),
        ]))));

        return collect(explode(' ', $movementText))
            ->filter(fn (string $token): bool => strlen($token) >= 4 && !$this->isIgnoredIdentityToken($token))
            ->unique()
            ->filter(fn (string $token): bool => str_contains($normalizedStatementText, $token))
            ->count();
    }

    private function confirmExpenseSuggestion(
        BankReconciliationSuggestion $suggestion,
        BankStatement $bankStatement,
        ?User $actor,
        array $options,
    ): Payment {
        $movementIds = collect((array) $suggestion->suggested_allocations)
            ->pluck('movement_id')
            ->filter()
            ->unique()
            ->values();

        if ($movementIds->count() !== 1) {
            throw ValidationException::withMessages([
                'suggestion' => 'A sugestao de despesa nao identifica um unico movimento.',
            ]);
        }

        $movement = Movement::query()->findOrFail($movementIds->first());
        $settlement = $this->financialSettlementService->settleExpenseMovement(
            $bankStatement,
            $movement,
            [
                'payment_date' => optional($bankStatement->data_movimento)?->toDateString() ?? now()->toDateString(),
                'method' => $options['method'] ?? 'transferencia',
                'reference' => $options['reference'] ?? $bankStatement->referencia,
                'description' => $options['description'] ?? $bankStatement->descricao,
                'notes' => $options['notes'] ?? $suggestion->explanation,
                'created_by' => $actor?->id,
                'source' => Payment::SOURCE_RECONCILIATION,
                'suggestion_id' => $suggestion->id,
                'suggestion_score' => $suggestion->score,
                'map_rule' => 'expense_suggestion_score',
                'map_metadata' => [
                    'suggestion_id' => $suggestion->id,
                    'matched_rules' => $suggestion->matched_rules,
                    'expense_reconciliation' => true,
                ],
            ],
        );

        $this->finalizeConfirmedSuggestion(
            $suggestion,
            $actor,
            $movement->user_id,
            $this->resolveFamilyId($movement->user_id),
            $bankStatement,
            false,
        );

        return $settlement['payment']->fresh(['allocations.financialEntry', 'bankStatement']);
    }

    private function resolveAssistedReferenceMonth(BankReconciliationSuggestion $suggestion, BankStatement $bankStatement): string
    {
        $metadataMonth = data_get($suggestion->metadata, 'reference_month_context.reference_month_key');

        if (is_string($metadataMonth) && preg_match('/^\d{4}-\d{2}$/', $metadataMonth) === 1) {
            return $metadataMonth;
        }

        if ($bankStatement->data_movimento) {
            return Carbon::parse($bankStatement->data_movimento)->format('Y-m');
        }

        return now()->format('Y-m');
    }

    private function fetchEligibleInvoicesForAssistedAllocation(?string $userId, ?string $familyId, string $referenceMonth): array
    {
        $query = Invoice::query()
            ->with([
                'user:id,nome_completo,name',
                'user.families:id,nome',
                'costCenter:id,nome',
            ])
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
        } elseif ($userId) {
            $query->where('user_id', $userId);
        } else {
            return [];
        }

        return $query
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) use ($referenceMonth): ?array {
                $openAmount = $this->getInvoiceOutstandingAmount($invoice);
                if ($openAmount <= 0.009) {
                    return null;
                }

                $monthKey = $this->resolveInvoiceMonthKey($invoice);
                $isMonthly = $this->isMonthlyInvoice($invoice);

                if ($isMonthly && $monthKey !== null && $monthKey > $referenceMonth) {
                    return null;
                }

                $family = $invoice->user?->families?->first();

                return [
                    'id' => $invoice->id,
                    'user_id' => $invoice->user_id,
                    'user_name' => $invoice->user?->nome_completo ?? $invoice->user?->name,
                    'family_id' => $family?->id,
                    'family_name' => $family?->nome,
                    'valor_total' => round((float) $invoice->valor_total, 2),
                    'valor_pago' => round(max((float) $invoice->valor_total - $openAmount, 0), 2),
                    'valor_em_aberto' => round($openAmount, 2),
                    'estado_pagamento' => $invoice->estado_pagamento,
                    'data_fatura' => optional($invoice->data_fatura)->toDateString(),
                    'data_vencimento' => optional($invoice->data_vencimento)->toDateString(),
                    'vencimento' => optional($invoice->data_vencimento)->toDateString(),
                    'mes' => $invoice->mes,
                    'tipo' => $invoice->tipo,
                    'centro_custo_id' => $invoice->centro_custo_id,
                    'centro_custo_name' => $invoice->costCenter?->nome,
                    'is_monthly' => $isMonthly,
                    'month_key' => $monthKey,
                ];
            })
            ->filter()
            ->sortBy(fn (array $invoice): array => [
                $invoice['is_monthly'] && $invoice['month_key'] === $referenceMonth ? 0
                    : (!$invoice['is_monthly'] && $invoice['month_key'] === $referenceMonth ? 1
                        : (!$invoice['is_monthly'] ? 2 : 3)),
                $invoice['is_monthly'] && $invoice['month_key'] !== $referenceMonth
                    ? sprintf('%06d', 999999 - (int) str_replace('-', '', (string) $invoice['month_key']))
                    : ($invoice['month_key'] ?? '9999-12'),
                $invoice['vencimento'] ?? '9999-12-31',
                $invoice['id'],
            ])
            ->values()
            ->map(function (array $invoice): array {
                unset($invoice['month_key']);

                return $invoice;
            })
            ->all();
    }

    private function fetchEligibleMovementsForAssistedAllocation(?string $userId, ?string $familyId): array
    {
        $query = Movement::query()
            ->with([
                'user:id,nome_completo,name,numero_socio,nif',
                'user.families:id,nome',
                'user.centrosCusto:id,nome',
                'centroCusto:id,nome',
                'latestFinancialEntry:id,origem_id,origem_tipo,valor_em_aberto,valor_pago,centro_custo_id,created_at',
            ])
            ->where(function ($movementQuery): void {
                $movementQuery
                    ->where('classificacao', 'receita')
                    ->orWhere('tipo', 'receita');
            })
            ->whereIn('estado_pagamento', ['pendente', 'parcial']);

        if ($userId || $familyId) {
            $query->where(function ($identityQuery) use ($userId, $familyId): void {
                if ($userId) {
                    $identityQuery->where('user_id', $userId);
                }

                if ($familyId) {
                    $method = $userId ? 'orWhereHas' : 'whereHas';
                    $identityQuery->{$method}('user.families', function ($familyQuery) use ($familyId): void {
                        $familyQuery->where('familias.id', $familyId);
                    });
                }
            });
        }

        return $query
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get()
            ->map(function (Movement $movement): ?array {
                $financialEntry = $movement->latestFinancialEntry;
                $entryOpenAmount = $financialEntry ? max((float) ($financialEntry->valor_em_aberto ?? 0), 0) : null;
                $movementOpenAmount = round(max(abs((float) $movement->valor_total) - (float) ($financialEntry->valor_pago ?? 0), 0), 2);
                $openAmount = $entryOpenAmount !== null ? round($entryOpenAmount, 2) : $movementOpenAmount;

                if ($openAmount <= 0.009) {
                    return null;
                }

                $defaultCostCenterId = $movement->centro_custo_id
                    ?: $financialEntry?->centro_custo_id
                    ?: $movement->user?->centrosCusto?->sortByDesc(fn ($center) => (float) ($center->pivot->peso ?? 1))->first()?->id;
                $family = $movement->user?->families?->first();

                return [
                    'id' => $movement->id,
                    'user_id' => $movement->user_id,
                    'user_name' => $movement->user?->nome_completo ?? $movement->user?->name ?? $movement->nome_manual,
                    'family_id' => $family?->id,
                    'family_name' => $family?->nome,
                    'financial_entry_id' => $financialEntry?->id,
                    'descricao' => $movement->observacoes ?: $movement->nome_manual ?: ('Movimento ' . $movement->tipo),
                    'tipo' => $movement->tipo,
                    'classificacao' => $movement->classificacao,
                    'estado' => $movement->estado_pagamento,
                    'valor_total' => round((float) $movement->valor_total, 2),
                    'valor_pago' => round(max((float) ($movement->valor_total ?? 0) - $openAmount, 0), 2),
                    'valor_em_aberto' => $openAmount,
                    'estado_pagamento' => $movement->estado_pagamento,
                    'data' => optional($movement->data_emissao)->toDateString() ?: optional($movement->data_vencimento)->toDateString(),
                    'data_emissao' => optional($movement->data_emissao)->toDateString(),
                    'data_vencimento' => optional($movement->data_vencimento)->toDateString(),
                    'centro_custo_id' => $movement->centro_custo_id,
                    'default_centro_custo_id' => $defaultCostCenterId,
                    'requires_centro_custo' => empty($defaultCostCenterId),
                    'centro_custo_name' => $movement->centroCusto?->nome,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function buildDefaultAssistedAllocations(float $availableAmount, array $eligibleInvoices, array $eligibleMovements): array
    {
        $remainingAmount = round(max($availableAmount, 0), 2);
        $invoiceDefaults = [];
        $movementDefaults = [];

        foreach ($eligibleInvoices as $invoice) {
            if ($remainingAmount <= 0.009) {
                break;
            }

            $openAmount = round((float) ($invoice['valor_em_aberto'] ?? 0), 2);
            if ($openAmount <= 0.009) {
                continue;
            }

            $amount = round(min($openAmount, $remainingAmount), 2);
            if ($amount <= 0.009) {
                continue;
            }

            $invoiceDefaults[] = [
                'invoice_id' => $invoice['id'],
                'amount' => $amount,
            ];
            $remainingAmount = round(max($remainingAmount - $amount, 0), 2);
        }

        foreach ($eligibleMovements as $movement) {
            if ($remainingAmount <= 0.009) {
                break;
            }

            $openAmount = round((float) ($movement['valor_em_aberto'] ?? 0), 2);
            if ($openAmount <= 0.009) {
                continue;
            }

            $amount = round(min($openAmount, $remainingAmount), 2);
            if ($amount <= 0.009) {
                continue;
            }

            $movementDefaults[] = [
                'movement_id' => $movement['id'],
                'amount' => $amount,
                'centro_custo_id' => $movement['default_centro_custo_id'] ?? $movement['centro_custo_id'] ?? null,
            ];
            $remainingAmount = round(max($remainingAmount - $amount, 0), 2);
        }

        return [
            'invoices' => $invoiceDefaults,
            'movements' => $movementDefaults,
            'credit_amount' => round(max($remainingAmount, 0), 2),
        ];
    }

    private function isMonthlyInvoice(Invoice $invoice): bool
    {
        $tipo = Str::of((string) ($invoice->tipo ?? ''))->lower()->ascii()->value();
        $origemTipo = Str::of((string) ($invoice->origem_tipo ?? ''))->lower()->ascii()->value();

        return str_contains($tipo, 'mens') || str_contains($origemTipo, 'mens');
    }

    private function generateLegacyPaidInvoiceSuggestion(
        BankStatement $bankStatement,
        array $context,
        float $statementAmount,
        string $normalizedText,
        array $rejectedAllocationSignatures,
        bool $forceRegeneration,
    ): ?BankReconciliationSuggestion {
        $candidates = $this->fetchLegacyPaidInvoicesForContext(
            $bankStatement,
            $context['user_id'] ?? null,
            $context['family_id'] ?? null,
            $context['matched_user_ids'] ?? [],
            $statementAmount,
        );

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var Invoice $invoice */
        $invoice = $candidates->first();
        $candidateSet = [[
            'invoice' => $invoice,
            'open_amount' => $statementAmount,
            'amount' => $statementAmount,
            'reason' => 'mensalidade já paga manualmente e ainda sem conciliação bancária',
        ]];
        $signature = $this->makeAllocationSignature($candidateSet);

        if (!$forceRegeneration && isset($rejectedAllocationSignatures[$signature])) {
            return null;
        }

        $identityRules = collect([
            ($context['repository_match'] ?? false) ? 'repository_match' : null,
            ($context['alias_confirmed'] ?? false) ? 'confirmed_alias' : null,
            ($context['matched_name'] ?? false) ? 'matched_name' : null,
            ($context['matched_nif'] ?? false) ? 'matched_nif' : null,
            ($context['matched_member_number'] ?? false) ? 'matched_member_number' : null,
            ($context['matched_email_or_phone'] ?? false) ? 'matched_email_or_phone' : null,
        ])->filter()->values()->all();

        $suggestion = $this->buildSuggestion(
            $bankStatement,
            $candidateSet,
            array_merge($context, [
                'statement_amount' => $statementAmount,
                'normalized_text' => $normalizedText,
            ]),
            [
                'score' => 100,
                'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_VERY_HIGH,
                'matched_rules' => array_values(array_unique(array_merge($identityRules, [
                    'exact_legacy_paid_invoice_amount',
                    'matching_invoice_period',
                    'legacy_manual_payment_pending_reconciliation',
                    'no_conflict',
                ]))),
                'explanation' => 'A identidade, o valor e o período bancário coincidem com uma mensalidade já paga manualmente, mas ainda sem conciliação. A confirmação associa o pagamento existente ao extrato sem criar um segundo pagamento.',
            ],
        );

        $suggestion->forceFill([
            'metadata' => array_merge((array) ($suggestion->metadata ?? []), [
                'target_type' => 'legacy_paid_invoice',
                'legacy_invoice_id' => $invoice->id,
                'legacy_payment_reconciliation' => true,
            ]),
        ])->save();

        return $suggestion->refresh(['bankStatement', 'user.families', 'family']);
    }

    private function fetchLegacyPaidInvoicesForContext(
        BankStatement $bankStatement,
        ?string $userId,
        ?string $familyId,
        array $matchedUserIds,
        float $statementAmount,
    ): Collection {
        if (!$bankStatement->data_movimento) {
            return collect();
        }

        $monthKey = $bankStatement->data_movimento->format('Y-m');
        $monthStart = $bankStatement->data_movimento->copy()->startOfMonth()->toDateString();
        $monthEnd = $bankStatement->data_movimento->copy()->endOfMonth()->toDateString();

        $query = Invoice::query()
            ->with([
                'user:id,nome_completo,name',
                'user.families:id',
                'paymentAllocations' => function ($allocationQuery): void {
                    $allocationQuery
                        ->confirmed()
                        ->with('payment');
                },
            ])
            ->where('oculta', false)
            ->where('estado_pagamento', 'pago')
            ->where('valor_total', '>=', $statementAmount - 0.009)
            ->where('valor_total', '<=', $statementAmount + 0.009)
            ->where(function ($monthlyTypeQuery): void {
                $monthlyTypeQuery
                    ->where('tipo', 'like', '%mens%')
                    ->orWhere('origem_tipo', 'like', '%mens%');
            })
            ->where(function ($periodQuery) use ($monthKey, $monthStart, $monthEnd): void {
                $periodQuery
                    ->where('mes', $monthKey)
                    ->orWhereBetween('data_fatura', [$monthStart, $monthEnd])
                    ->orWhereBetween('data_emissao', [$monthStart, $monthEnd]);
            })
            ->whereDoesntHave('paymentAllocations', function ($allocationQuery): void {
                $allocationQuery
                    ->confirmed()
                    ->whereHas('payment', function ($paymentQuery): void {
                        $paymentQuery
                            ->confirmed()
                            ->whereNotNull('bank_statement_id');
                    });
            })
            ->whereNotExists(function ($mapQuery): void {
                $mapQuery
                    ->selectRaw('1')
                    ->from('mapa_conciliacao')
                    ->whereColumn('mapa_conciliacao.fatura_id', 'invoices.id')
                    ->where('mapa_conciliacao.status', 'confirmado');
            });

        if ($familyId) {
            $query->whereHas('user.families', function ($familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        } elseif ($matchedUserIds !== []) {
            $query->whereIn('user_id', $matchedUserIds);
        } elseif ($userId) {
            $query->where('user_id', $userId);
        } else {
            return collect();
        }

        return $query
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get()
            ->filter(function (Invoice $invoice) use ($statementAmount): bool {
                $invoiceAmount = round((float) $invoice->valor_total, 2);
                $trackedPaidAmount = round(max(
                    (float) ($invoice->valor_pago ?? 0),
                    $invoiceAmount - (float) ($invoice->valor_em_aberto ?? $invoiceAmount),
                    (float) $invoice->paymentAllocations->sum('amount'),
                ), 2);

                return abs($trackedPaidAmount - $statementAmount) <= 0.009;
            })
            ->values();
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
        array $historyProfile = [],
        ?array $referenceMonthSequence = null
    ): array
    {
        $referenceMonthSets = [];
        if ($referenceMonthSequence) {
            $firstReferenceCandidate = $referenceMonthSequence[0] ?? null;
            $referenceMonthSets[] = [
                'candidate_set' => $referenceMonthSequence,
                'context' => [
                    'reference_month_context' => is_array($firstReferenceCandidate) ? [
                        'reference_month_key' => $firstReferenceCandidate['reference_month_key'] ?? null,
                        'reference_month_label' => $firstReferenceCandidate['reference_month_label'] ?? null,
                        'total_months' => (int) ($firstReferenceCandidate['reference_month_total_months'] ?? count($referenceMonthSequence)),
                        'covered_months' => (int) ($firstReferenceCandidate['reference_month_covered_months'] ?? count($referenceMonthSequence)),
                        'total_open_amount' => round((float) ($firstReferenceCandidate['reference_month_total_open_amount'] ?? 0), 2),
                        'allocated_amount' => round((float) ($firstReferenceCandidate['reference_month_allocated_amount'] ?? 0), 2),
                        'insufficient' => (bool) ($firstReferenceCandidate['reference_month_insufficient'] ?? false),
                    ] : null,
                ],
            ];
        }

        if (($context['repository_match'] ?? false) === true) {
            return $this->dedupeCandidateSets(array_merge(
                $this->generateRepositoryCandidateInvoiceSets($candidateInvoices->all(), $statementAmount, $bankStatement),
                $referenceMonthSets,
            ));
        }

        if ($referenceMonthSequence && count($referenceMonthSequence) > 1) {
            return $this->dedupeCandidateSets($referenceMonthSets);
        }

        $sets = $referenceMonthSets;
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
        $statementMonthKey = $statementDate->format('Y-m');
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
            $oldestInvoice = $dueMonthlyInvoices
                ->first(fn (array $candidate): bool => $this->resolveInvoiceMonthKey($candidate['invoice']) === $statementMonthKey);

            if ($oldestInvoice && abs($oldestInvoice['open_amount'] - $statementAmount) <= 0.009) {
                $sets[] = [[
                    'invoice' => $oldestInvoice['invoice'],
                    'open_amount' => $oldestInvoice['open_amount'],
                    'amount' => $oldestInvoice['open_amount'],
                    'reason' => 'repositorio confirmado: mensalidade do periodo do movimento',
                ]];
            }
        }

        return $sets;
    }

    private function buildReferenceMonthChronologicalSequence(
        Collection $candidateInvoices,
        float $statementAmount,
        ?BankStatement $bankStatement,
        array $context,
    ): ?array {
        if (!$bankStatement || $statementAmount <= 0.009) {
            return null;
        }

        $referenceMonthKey = $this->resolveReferenceMonthKey($bankStatement);
        if ($referenceMonthKey === null) {
            return null;
        }

        $monthlyCandidates = $this->fetchMonthlyInvoicesUntilReferenceMonth(
            $context['user_id'] ?? null,
            $context['family_id'] ?? null,
            $context['matched_user_ids'] ?? [],
            $referenceMonthKey,
        );

        if ($monthlyCandidates->isEmpty()) {
            return null;
        }

        // Divida antiga permanece disponivel no contexto assistido, mas nunca
        // inicia uma selecao automatica quando nao existe mensalidade do periodo.
        if (!$monthlyCandidates->contains(
            fn (array $candidate): bool => ($candidate['month_key'] ?? null) === $referenceMonthKey
        )) {
            return null;
        }

        $remainingAmount = round($statementAmount, 2);
        $selected = [];
        $remainingOpenTotal = round($monthlyCandidates->sum('open_amount'), 2);

        foreach ($monthlyCandidates as $candidate) {
            $openAmount = round((float) ($candidate['open_amount'] ?? 0), 2);
            if ($openAmount <= 0.009) {
                continue;
            }

            if ($openAmount - $remainingAmount > 0.009) {
                break;
            }

            $selected[] = [
                'invoice' => $candidate['invoice'],
                'open_amount' => $openAmount,
                'amount' => $openAmount,
                'reason' => '',
                'reference_month_sequence' => true,
                'reference_month_key' => $referenceMonthKey,
                'reference_month_label' => $this->formatReferenceMonthLabel($referenceMonthKey),
                'reference_month_total_months' => $monthlyCandidates->count(),
                'reference_month_covered_months' => count($selected) + 1,
                'reference_month_total_open_amount' => $remainingOpenTotal,
                'reference_month_allocated_amount' => round($statementAmount, 2),
                'reference_month_insufficient' => $remainingAmount - $openAmount > 0.009,
            ];

            $remainingAmount = round($remainingAmount - $openAmount, 2);
            if ($remainingAmount <= 0.009) {
                break;
            }
        }

        if ($selected === []) {
            return null;
        }

        $coveredCount = count($selected);
        $totalCount = $monthlyCandidates->count();
        $referenceMonthLabel = $this->formatReferenceMonthLabel($referenceMonthKey);
        $reason = $coveredCount >= $totalCount
            ? sprintf('Valor cobre mensalidades em aberto ate %s.', $referenceMonthLabel)
            : sprintf('Existem mensalidades em aberto ate %s, mas o valor da linha so cobre %d de %d mensalidades.', $referenceMonthLabel, $coveredCount, $totalCount);

        return array_map(function (array $candidate) use ($reason, $referenceMonthKey, $referenceMonthLabel, $totalCount, $coveredCount, $remainingOpenTotal, $statementAmount): array {
            $candidate['reason'] = $reason;
            $candidate['reference_month_key'] = $referenceMonthKey;
            $candidate['reference_month_label'] = $referenceMonthLabel;
            $candidate['reference_month_total_months'] = $totalCount;
            $candidate['reference_month_covered_months'] = $coveredCount;
            $candidate['reference_month_total_open_amount'] = $remainingOpenTotal;
            $candidate['reference_month_allocated_amount'] = round($statementAmount, 2);
            $candidate['reference_month_insufficient'] = $coveredCount < $totalCount;

            return $candidate;
        }, $selected);
    }

    private function dedupeCandidateSets(array $sets): array
    {
        $deduped = [];
        $seen = [];
        foreach ($sets as $set) {
            $candidateSet = $set['candidate_set'] ?? $set;
            if (!is_array($candidateSet) || $candidateSet === []) {
                continue;
            }

            $signature = $this->makeAllocationSignature($candidateSet);
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

        $statementMonthKey = $statementDate?->format('Y-m');

        return $candidateInvoices
            ->sortBy(function (array $candidate) use ($statementAmount, $statementDate, $context, $historyProfile): array {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                $invoiceMonthKey = $this->resolveInvoiceMonthKey($invoice);
                $samePeriod = $statementDate && $invoiceMonthKey === $statementDate->format('Y-m') ? 0 : 1;
                $monthly = $this->isMonthlyInvoice($invoice) ? 0 : 1;
                $oldMonthly = $monthly === 0 && $samePeriod !== 0 ? 1 : 0;
                $directAmountMatch = abs($candidate['open_amount'] - $statementAmount) <= 0.009 ? 0 : 1;
                $dueDate = $invoice->data_vencimento?->format('Y-m-d') ?? '9999-12-31';

                return [
                    $samePeriod,
                    $monthly,
                    $oldMonthly,
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

        $personalDataRelation = app(MemberPersonalDataColumnService::class)->relationSelectForFiscalData();

        $users = User::query()
            ->with([
                $personalDataRelation,
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
            ->get();

        $matches = $users->map(function (User $user) use ($normalizedText, $compactDigits): array {
            $fiscalData = $this->memberFiscalDataResolver->resolve($user);
            $name = $this->normalizer->normalize($this->memberFiscalDataResolver->displayName($user));
            $secondaryEmail = $this->normalizer->normalize($this->memberFiscalDataResolver->emailSecondary($user));
            $nif = (string) ($fiscalData['nif'] ?? '');
            $contact = (string) ($this->memberFiscalDataResolver->contact($user) ?? '');
            $educandos = $this->getGuardianStudents($user);
            $directFamilyId = $user->families->first()?->id;
            $responsibleFamilyId = $user->responsibleFamilies->first()?->id;
            $guardianFamilyId = $educandos
                ->flatMap(fn (User $educando) => $educando->families)
                ->unique('id')
                ->first()?->id;
            $familyId = $directFamilyId ?: $responsibleFamilyId ?: $guardianFamilyId;
            $nameTokens = collect(explode(' ', $name))
                ->filter(fn (string $token) => strlen($token) >= 3)
                ->values();
            $email = $this->normalizer->normalize($user->email);
            $memberNumber = (string) ($user->numero_socio ?? '');
            $phoneMatches = $compactDigits !== '' && $contact !== '' && str_contains($contact, $compactDigits);
            $matchedName = $this->nameMatchesStatement($normalizedText, $name);

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
                    'conflict_count' => 0,
                ],
            ];
        })->filter(function (array $match): bool {
            $flags = $match['flags'] ?? [];

            return ($flags['matched_name'] ?? false)
                || ($flags['matched_nif'] ?? false)
                || ($flags['matched_member_number'] ?? false)
                || ($flags['matched_email_or_phone'] ?? false);
        })->values();

        $conflictCount = max($matches->count() - 1, 0);

        return $matches
            ->map(function (array $match) use ($conflictCount): array {
                $match['flags']['conflict_count'] = $conflictCount;

                return $match;
            })
            ->all();
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
            $matchedResponsavel = $this->nameMatchesStatement($normalizedText, $responsavelName);

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
            $guardianName = $this->normalizer->normalize($this->memberFiscalDataResolver->displayName($guardian));
            $matchedGuardian = $this->nameMatchesStatement($normalizedText, $guardianName);

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

    private function fetchRejectedAllocationSignatures(BankStatement $bankStatement): array
    {
        return BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $bankStatement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_REJECTED)
            ->get()
            ->map(function (BankReconciliationSuggestion $suggestion): ?string {
                $metadataSignature = trim((string) data_get($suggestion->metadata, 'allocation_signature', ''));

                if ($metadataSignature !== '') {
                    return $metadataSignature;
                }

                $suggestedAllocations = is_array($suggestion->suggested_allocations) ? $suggestion->suggested_allocations : [];

                return $this->makeAllocationSignatureFromAllocations($suggestedAllocations);
            })
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $signature) => [$signature => true])
            ->all();
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

    private function resolveReferenceMonthKey(BankStatement $bankStatement): ?string
    {
        return $bankStatement->data_movimento?->format('Y-m');
    }

    private function fetchMonthlyInvoicesUntilReferenceMonth(
        ?string $userId,
        ?string $familyId,
        array $matchedUserIds,
        string $referenceMonthKey,
    ): Collection {
        $query = Invoice::query()
            ->with(['user:id'])
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->where('oculta', false)
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->where(function ($monthlyTypeQuery): void {
                $monthlyTypeQuery
                    ->where('tipo', 'like', '%mens%')
                    ->orWhere('origem_tipo', 'like', '%mens%');
            });

        if ($familyId) {
            $query->whereHas('user.families', function ($familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        } elseif ($matchedUserIds !== []) {
            $query->whereIn('user_id', $matchedUserIds);
        } elseif ($userId) {
            $query->where('user_id', $userId);
        } else {
            return collect();
        }

        return $query
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice): array {
                return [
                    'invoice' => $invoice,
                    'open_amount' => $this->getInvoiceOutstandingAmount($invoice),
                    'month_key' => $this->resolveInvoiceMonthKey($invoice),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['open_amount'] > 0.009)
            ->filter(fn (array $candidate): bool => $candidate['month_key'] !== null && $candidate['month_key'] <= $referenceMonthKey)
            ->sortBy(fn (array $candidate): array => [
                $candidate['month_key'] === $referenceMonthKey ? 0 : 1,
                $candidate['month_key'] === $referenceMonthKey ? '' : sprintf('%06d', 999999 - (int) str_replace('-', '', $candidate['month_key'])),
                $candidate['invoice']->data_vencimento?->format('Y-m-d') ?? '9999-12-31',
                (string) $candidate['invoice']->id,
            ])
            ->values();
    }

    private function resolveInvoiceMonthKey(Invoice $invoice): ?string
    {
        if (is_string($invoice->mes) && preg_match('/^\d{4}-\d{2}$/', $invoice->mes) === 1) {
            return $invoice->mes;
        }

        if ($invoice->data_emissao) {
            return $invoice->data_emissao->format('Y-m');
        }

        if ($invoice->data_vencimento) {
            return $invoice->data_vencimento->format('Y-m');
        }

        return null;
    }

    private function formatReferenceMonthLabel(string $monthKey): string
    {
        $date = Carbon::createFromFormat('Y-m-d', $monthKey . '-01');
        $monthNumber = (int) $date->format('n');
        $monthName = array_flip(self::REFERENCE_MONTH_NAMES)[$monthNumber] ?? $date->format('m');

        return sprintf('%s de %s', $monthName, $date->format('Y'));
    }

    private function extractReferenceMonthContext(array $candidateInvoices): ?array
    {
        foreach ($candidateInvoices as $candidate) {
            if (($candidate['reference_month_sequence'] ?? false) !== true) {
                continue;
            }

            return [
                'reference_month_key' => $candidate['reference_month_key'] ?? null,
                'reference_month_label' => $candidate['reference_month_label'] ?? null,
                'total_months' => (int) ($candidate['reference_month_total_months'] ?? 0),
                'covered_months' => (int) ($candidate['reference_month_covered_months'] ?? 0),
                'total_open_amount' => round((float) ($candidate['reference_month_total_open_amount'] ?? 0), 2),
                'allocated_amount' => round((float) ($candidate['reference_month_allocated_amount'] ?? 0), 2),
                'insufficient' => (bool) ($candidate['reference_month_insufficient'] ?? false),
            ];
        }

        return null;
    }

    /**
     * @return array{is_full:bool,message:string}|null
     */
    private function resolveReferenceMonthSignal(array $candidateInvoices): ?array
    {
        foreach ($candidateInvoices as $candidate) {
            $reason = trim((string) ($candidate['reason'] ?? ''));

            if ($reason === '') {
                continue;
            }

            if (str_starts_with($reason, 'Valor cobre mensalidades em aberto ate ')) {
                return ['is_full' => true, 'message' => $reason];
            }

            if (str_starts_with($reason, 'Existem mensalidades em aberto ate ')) {
                return ['is_full' => false, 'message' => $reason];
            }
        }

        return null;
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

            if (abs($invoice->data_vencimento->diffInDays($statementDate, false)) <= 20) {
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
        $allocations = collect($candidateInvoices)
            ->map(function (array $candidate): array {
                /** @var Invoice $invoice */
                $invoice = $candidate['invoice'];

                return [
                    'invoice_id' => $invoice->id,
                    'amount' => (float) $candidate['amount'],
                ];
            })
            ->all();

        return $this->makeAllocationSignatureFromAllocations($allocations);
    }

    private function makeAllocationSignatureFromAllocations(array $allocations): string
    {
        $parts = collect($allocations)
            ->map(function (array $allocation): string {
                $target = !empty($allocation['invoice_id'])
                    ? (string) $allocation['invoice_id']
                    : (!empty($allocation['movement_id'])
                        ? 'movement:' . $allocation['movement_id']
                        : 'unknown');
                $amount = number_format((float) ($allocation['amount'] ?? 0), 2, '.', '');

                return $target . ':' . $amount;
            })
            ->sort()
            ->values()
            ->all();

        return implode('|', $parts);
    }

    private function markSuggestionsAnalyzed(BankStatement $bankStatement): void
    {
        BankStatement::query()
            ->whereKey($bankStatement->id)
            ->update(['suggestions_analyzed_at' => now()]);
    }

    private function nameMatchesStatement(string $normalizedText, string $normalizedName): bool
    {
        if ($normalizedText === '' || $normalizedName === '') {
            return false;
        }

        if (str_contains($normalizedText, $normalizedName)) {
            return true;
        }

        $nameTokens = collect(explode(' ', $normalizedName))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->unique()
            ->values();

        if ($nameTokens->count() < 4) {
            return $nameTokens->isNotEmpty()
                && $nameTokens->every(fn (string $token): bool => str_contains($normalizedText, $token));
        }

        $statementTokens = collect(explode(' ', $normalizedText))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->unique()
            ->values();

        $matchedTokens = $nameTokens->intersect($statementTokens);
        $requiredMatches = max(3, $nameTokens->count() - 1);
        $firstNameMatches = $statementTokens->contains($nameTokens->first());
        $lastNameMatches = $statementTokens->contains($nameTokens->last());

        return $firstNameMatches
            && $lastNameMatches
            && $matchedTokens->count() >= $requiredMatches
            && ($matchedTokens->count() / $nameTokens->count()) >= 0.75;
    }

    private function hasClearIdentityEvidence(array $context): bool
    {
        return (bool) (
            ($context['repository_match'] ?? false)
            || ($context['alias_confirmed'] ?? false)
            || ($context['matched_name'] ?? false)
            || ($context['matched_nif'] ?? false)
            || ($context['matched_member_number'] ?? false)
            || ($context['matched_email_or_phone'] ?? false)
        );
    }

    private function hasPersistedIdentityEvidence(BankReconciliationSuggestion $suggestion): bool
    {
        $identityRules = [
            'repository_match',
            'confirmed_alias',
            'matched_name',
            'matched_nif',
            'matched_member_number',
            'matched_email_or_phone',
            'receipt_movement_description_strong_match',
        ];

        return collect((array) ($suggestion->matched_rules ?? []))
            ->intersect($identityRules)
            ->isNotEmpty();
    }

    private function isBankStatementFullyReconciled(BankStatement $bankStatement): bool
    {
        if (($bankStatement->conciliacao_status ?? null) === 'reconciled') {
            return true;
        }

        return (bool) $bankStatement->conciliado
            && round(abs((float) ($bankStatement->valor_por_conciliar ?? 0)), 2) <= 0.009;
    }
}
