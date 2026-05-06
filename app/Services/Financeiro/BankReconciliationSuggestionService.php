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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationSuggestionService
{
    private const MAX_INVOICES_PER_CONTEXT = 6;

    public function __construct(
        private readonly BankAliasNormalizer $normalizer,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly ReconciliationAliasService $reconciliationAliasService,
    ) {
    }

    public function generateForBankStatement(BankStatement $bankStatement, array $options = []): Collection
    {
        $bankStatement = $bankStatement->fresh();

        if (!$bankStatement || $this->isBankStatementFullyReconciled($bankStatement)) {
            return collect();
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
            $candidateInvoices = $this->fetchOpenInvoicesForContext($context['user_id'] ?? null, $context['family_id'] ?? null);

            if ($candidateInvoices->isEmpty()) {
                continue;
            }

            foreach ($this->generateCandidateInvoiceSets($candidateInvoices, $statementAmount) as $candidateSet) {
                $signature = $this->makeAllocationSignature($candidateSet);
                if (isset($seenSignatures[$signature])) {
                    continue;
                }

                $seenSignatures[$signature] = true;

                $suggestion = $this->buildSuggestion($bankStatement, $candidateSet, array_merge($context, [
                    'statement_amount' => $statementAmount,
                    'normalized_text' => $normalizedText,
                ]));

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

    public function buildSuggestion(BankStatement $bankStatement, array $candidateInvoices, array $matchedRules): BankReconciliationSuggestion
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
        $scoreData = $this->calculateScore($bankStatement, $candidateInvoices, $matchedRules);
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
            $score += 45;
            $rules[] = 'exact_single_invoice_amount';
            $explanations[] = 'Valor da linha bate exatamente com uma fatura em aberto.';
        } elseif (abs($statementAmount - $openAmount) <= 0.009) {
            $score += 40;
            $rules[] = 'exact_invoice_combination';
            $explanations[] = 'Valor da linha bate exatamente com a soma das faturas sugeridas.';
        } elseif ($difference > 0 && $difference <= max(5, round($statementAmount * 0.2, 2))) {
            $score += 25;
            $rules[] = 'possible_credit_overpayment';
            $explanations[] = 'Existe um excedente pequeno que pode ser convertido em credito.';
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

            $payment = $this->paymentAllocationService->createFromBankStatement($bankStatement, $allocations, [
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
            if (!$userId && !$familyId) {
                return;
            }

            $key = ($userId ?: 'none') . '|' . ($familyId ?: 'none');
            $existing = $contexts[$key] ?? [
                'user_id' => $userId,
                'family_id' => $familyId,
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

                if ($value === true) {
                    $existing[$flag] = true;
                }
            }

            $contexts[$key] = $existing;
        };

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

        if ($contexts === []) {
            foreach ($this->buildFallbackContexts($statementAmount) as $context) {
                $registerContext($context['user_id'], $context['family_id'], $context);
            }
        }

        return array_values($contexts);
    }

    private function fetchOpenInvoicesForContext(?string $userId, ?string $familyId): Collection
    {
        $query = Invoice::query()
            ->with(['user:id,nome_completo,name,numero_socio,nif,email,email_secundario,telemovel,contacto', 'user.families:id'])
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($familyId) {
            $query->whereHas('user.families', function ($familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        }

        return $query
            ->orderByRaw("CASE WHEN estado_pagamento = 'vencido' THEN 0 WHEN estado_pagamento = 'parcial' THEN 1 ELSE 2 END")
            ->orderBy('data_vencimento')
            ->limit(self::MAX_INVOICES_PER_CONTEXT)
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

    private function generateCandidateInvoiceSets(Collection $candidateInvoices, float $statementAmount): array
    {
        $sets = [];
        $invoiceArray = $candidateInvoices->all();

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
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $tokens = collect(explode(' ', $normalizedText))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => strlen($token) >= 3 && !$this->isIgnoredIdentityToken($token))
            ->take(6)
            ->values();

        $users = User::query()
            ->with('families:id')
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
            $familyId = $user->families->first()?->id;
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
        })->all();
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