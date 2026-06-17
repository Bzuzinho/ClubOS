<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\FiscalDocumentRequest;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankReconciliationAuditService
{
    public const EXPORT_LIMIT = 5000;

    public const STATE_ALL = 'todos';
    public const STATE_UNRECONCILED = 'por_conciliar';
    public const STATE_PARTIAL = 'parcial';
    public const STATE_RECONCILED = 'conciliado';

    public const METHOD_AUTOMATIC_SUGGESTION = 'sugestao_automatica';
    public const METHOD_ASSISTED_ALLOCATION = 'alocacao_assistida';
    public const METHOD_EXPENSE_FROM_STATEMENT = 'despesa_extrato';
    public const METHOD_MANUAL_PAYMENT = 'pagamento_manual';
    public const METHOD_OTHER = 'outro_fluxo';

    public function paginate(array $filters = []): array
    {
        $perPage = max(5, min((int) ($filters['per_page'] ?? 20), 200));
        [$sortBy, $sortDirection] = $this->resolveSort($filters);

        $query = $this->buildBaseQuery($filters);
        $summary = $this->buildSummary(clone $query);

        if ($sortBy === 'valor') {
            $query->orderBy('valor', $sortDirection);
        } else {
            $query->orderBy('data_movimento', $sortDirection);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items());

        return [
            'rows' => $this->decorateRows($items),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'summary' => $summary,
        ];
    }

    public function exportRows(array $filters = []): array
    {
        [$sortBy, $sortDirection] = $this->resolveSort($filters);
        $limit = max(1, min((int) ($filters['export_limit'] ?? self::EXPORT_LIMIT), self::EXPORT_LIMIT));

        $query = $this->buildBaseQuery($filters);
        $summary = $this->buildSummary(clone $query);

        if ($sortBy === 'valor') {
            $query->orderBy('valor', $sortDirection);
        } else {
            $query->orderBy('data_movimento', $sortDirection);
        }

        $items = $query->limit($limit)->get();
        $rows = $this->decorateRows($items);
        $totalFiltered = (int) ($summary['total_linhas'] ?? 0);

        return [
            'rows' => $rows,
            'summary' => $summary,
            'meta' => [
                'total_filtered' => $totalFiltered,
                'exported_rows' => $rows->count(),
                'limit' => $limit,
                'truncated' => $totalFiltered > $rows->count(),
            ],
        ];
    }

    public function supportsXlsxExport(): bool
    {
        return class_exists('Maatwebsite\\Excel\\Facades\\Excel')
            && class_exists('Maatwebsite\\Excel\\ExcelServiceProvider');
    }

    private function resolveSort(array $filters): array
    {
        $requestedSortBy = (string) ($filters['sort_by'] ?? 'data_movimento');
        $sortBy = in_array($requestedSortBy, ['data_movimento', 'valor'], true)
            ? $requestedSortBy
            : 'data_movimento';
        $requestedSortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc'));
        $sortDirection = $requestedSortDirection === 'asc' ? 'asc' : 'desc';

        return [$sortBy, $sortDirection];
    }

    private function buildBaseQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $state = (string) ($filters['estado'] ?? self::STATE_ALL);
        $method = (string) ($filters['metodo'] ?? '');
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = BankStatement::query()
            ->with([
                'financialEntry:id,origem_tipo,origem_id,descricao,estado',
                'payments' => function ($paymentQuery): void {
                    $paymentQuery
                        ->withTrashed()
                        ->with([
                            'createdBy:id,nome_completo,name',
                            'cancelledBy:id,nome_completo,name',
                            'user:id,nome_completo,name',
                            'family:id,nome',
                            'allocations' => function ($allocationQuery): void {
                                $allocationQuery
                                    ->withTrashed()
                                    ->with([
                                        'invoice:id,user_id,tipo,mes,estado_pagamento,valor_total,valor_em_aberto',
                                        'invoice.user:id,nome_completo,name',
                                        'financialEntry:id,origem_tipo,origem_id,descricao,estado,valor,valor_em_aberto',
                                    ]);
                            },
                            'credits' => function ($creditQuery): void {
                                $creditQuery
                                    ->withTrashed()
                                    ->with([
                                        'user:id,nome_completo,name',
                                        'family:id,nome',
                                    ]);
                            },
                        ]);
                },
                'reconciliationMaps' => function ($mapQuery): void {
                    $mapQuery
                        ->with([
                            'invoice:id,user_id,tipo,mes,estado_pagamento,valor_total,valor_em_aberto',
                            'invoice.user:id,nome_completo,name',
                            'movement:id,nome_manual,categoria,estado_pagamento,origem_tipo,valor_total,data_emissao',
                            'payment:id,source,method,created_by,payment_date,status,deleted_at',
                            'payment.createdBy:id,nome_completo,name',
                            'paymentAllocation:id,payment_id,invoice_id,financial_entry_id,amount,status,allocated_at,deleted_at',
                            'suggestion:id,status,score',
                        ])
                        ->orderByDesc('created_at');
                },
            ]);

        if (!empty($filters['date_from'])) {
            $query->whereDate('data_movimento', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('data_movimento', '<=', $filters['date_to']);
        }

        if ($state === self::STATE_RECONCILED) {
            $query->where('conciliacao_status', 'reconciled');
        } elseif ($state === self::STATE_PARTIAL) {
            $query->where('conciliacao_status', 'partial');
        } elseif ($state === self::STATE_UNRECONCILED) {
            $query->where(function ($stateQuery): void {
                $stateQuery
                    ->where('conciliacao_status', 'unreconciled')
                    ->orWhereNull('conciliacao_status');
            });
        }

        if (!empty($filters['user_id'])) {
            $query->whereHas('payments', function (Builder $paymentQuery) use ($filters): void {
                $paymentQuery
                    ->whereNull('payments.deleted_at')
                    ->where('payments.status', Payment::STATUS_CONFIRMED)
                    ->where('payments.user_id', $filters['user_id']);
            });
        }

        if (!empty($filters['family_id'])) {
            $query->whereHas('payments', function (Builder $paymentQuery) use ($filters): void {
                $paymentQuery
                    ->whereNull('payments.deleted_at')
                    ->where('payments.status', Payment::STATUS_CONFIRMED)
                    ->where('payments.family_id', $filters['family_id']);
            });
        }

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search, $operator): void {
                $searchQuery
                    ->where('descricao', $operator, '%' . $search . '%')
                    ->orWhere('referencia', $operator, '%' . $search . '%')
                    ->orWhereHas('payments.user', function (Builder $userQuery) use ($search, $operator): void {
                        $userQuery
                            ->where('nome_completo', $operator, '%' . $search . '%')
                            ->orWhere('name', $operator, '%' . $search . '%');
                    })
                    ->orWhereHas('payments.family', function (Builder $familyQuery) use ($search, $operator): void {
                        $familyQuery->where('nome', $operator, '%' . $search . '%');
                    });
            });
        }

        if (array_key_exists('has_credit', $filters) && $filters['has_credit'] !== null) {
            $hasCredit = (bool) $filters['has_credit'];

            $query->where(function (Builder $creditQuery) use ($hasCredit): void {
                if ($hasCredit) {
                    $creditQuery->whereHas('payments.credits', function (Builder $nested): void {
                        $nested
                            ->whereNull('account_credits.deleted_at')
                            ->where('account_credits.status', '!=', AccountCredit::STATUS_CANCELLED);
                    });

                    return;
                }

                $creditQuery->whereDoesntHave('payments.credits', function (Builder $nested): void {
                    $nested
                        ->whereNull('account_credits.deleted_at')
                        ->where('account_credits.status', '!=', AccountCredit::STATUS_CANCELLED);
                });
            });
        }

        if ($method !== '') {
            $this->applyMethodFilter($query, $method);
        }

        return $query;
    }

    private function applyMethodFilter(Builder $query, string $method): void
    {
        if ($method === self::METHOD_AUTOMATIC_SUGGESTION) {
            $query->whereHas('reconciliationMaps', fn (Builder $mapQuery) => $mapQuery->where('regra_usada', 'suggestion_score'));

            return;
        }

        if ($method === self::METHOD_ASSISTED_ALLOCATION) {
            $query->whereHas('reconciliationMaps', fn (Builder $mapQuery) => $mapQuery->where('regra_usada', 'suggestion_assisted_allocation'));

            return;
        }

        if ($method === self::METHOD_EXPENSE_FROM_STATEMENT) {
            $query->whereHas('reconciliationMaps', function (Builder $mapQuery): void {
                $mapQuery
                    ->whereNotNull('movimento_id')
                    ->where('regra_usada', 'bank_statement_settlement');
            });

            return;
        }

        if ($method === self::METHOD_MANUAL_PAYMENT) {
            $query->where(function (Builder $methodQuery): void {
                $methodQuery
                    ->whereHas('payments', function (Builder $paymentQuery): void {
                        $paymentQuery
                            ->whereNull('payments.deleted_at')
                            ->where('payments.status', Payment::STATUS_CONFIRMED)
                            ->where('payments.source', Payment::SOURCE_MANUAL);
                    })
                    ->orWhereHas('reconciliationMaps', function (Builder $mapQuery): void {
                        $mapQuery->whereIn('regra_usada', ['manual', 'manual_payment_allocation']);
                    });
            });

            return;
        }

        if ($method === self::METHOD_OTHER) {
            $query->whereDoesntHave('reconciliationMaps', function (Builder $mapQuery): void {
                $mapQuery->whereIn('regra_usada', [
                    'suggestion_score',
                    'suggestion_assisted_allocation',
                    'bank_statement_settlement',
                    'manual',
                    'manual_payment_allocation',
                ]);
            });
        }
    }

    private function buildSummary(Builder $query): array
    {
        $stats = (clone $query)
            ->selectRaw('COUNT(*) as total_linhas')
            ->selectRaw("SUM(CASE WHEN conciliacao_status = 'reconciled' THEN 1 ELSE 0 END) as total_conciliado")
            ->selectRaw("SUM(CASE WHEN conciliacao_status = 'partial' THEN 1 ELSE 0 END) as total_parcial")
            ->selectRaw("SUM(CASE WHEN conciliacao_status = 'unreconciled' OR conciliacao_status IS NULL THEN 1 ELSE 0 END) as total_por_conciliar")
            ->selectRaw('SUM(COALESCE(valor_conciliado, 0)) as total_alocado')
            ->selectRaw('SUM(CASE WHEN valor_por_conciliar IS NOT NULL THEN valor_por_conciliar ELSE MAX(ABS(valor) - COALESCE(valor_conciliado, 0), 0) END) as total_por_alocar')
            ->first();

        $statementIdsSubquery = (clone $query)->select('bank_statements.id');

        $totalCredit = AccountCredit::query()
            ->join('payments', 'payments.id', '=', 'account_credits.payment_id')
            ->whereIn('payments.bank_statement_id', $statementIdsSubquery)
            ->whereNull('payments.deleted_at')
            ->where('payments.status', Payment::STATUS_CONFIRMED)
            ->whereNull('account_credits.deleted_at')
            ->where('account_credits.status', '!=', AccountCredit::STATUS_CANCELLED)
            ->sum('account_credits.amount');

        return [
            'total_linhas' => (int) ($stats->total_linhas ?? 0),
            'total_conciliado' => (int) ($stats->total_conciliado ?? 0),
            'total_parcial' => (int) ($stats->total_parcial ?? 0),
            'total_por_conciliar' => (int) ($stats->total_por_conciliar ?? 0),
            'total_alocado' => round((float) ($stats->total_alocado ?? 0), 2),
            'total_por_alocar' => round((float) ($stats->total_por_alocar ?? 0), 2),
            'total_credito_criado' => round((float) $totalCredit, 2),
        ];
    }

    private function decorateRows(Collection $statements): Collection
    {
        if ($statements->isEmpty()) {
            return collect();
        }

        $movementIds = $statements
            ->flatMap(function (BankStatement $statement): array {
                return collect($statement->payments)
                    ->flatMap(fn (Payment $payment) => collect($payment->allocations)
                        ->map(fn (PaymentAllocation $allocation) => (string) ($allocation->financialEntry?->origem_tipo === 'movement'
                            ? $allocation->financialEntry?->origem_id
                            : null))
                    )
                    ->merge(collect($statement->reconciliationMaps)->map(fn ($map) => (string) ($map->movimento_id ?? '')))
                    ->filter()
                    ->values()
                    ->all();
            })
            ->unique()
            ->values();

        $movementMap = Movement::query()
            ->whereIn('id', $movementIds)
            ->get(['id', 'nome_manual', 'categoria', 'estado_pagamento', 'origem_tipo', 'valor_total', 'data_emissao'])
            ->keyBy('id');

        [$fiscalByStatement, $invoiceStatementMap, $entryStatementMap] = $this->buildFiscalMaps($statements);

        return $statements->map(function (BankStatement $statement) use ($movementMap, $fiscalByStatement, $invoiceStatementMap, $entryStatementMap): array {
            $payments = collect($statement->payments ?? []);
            $confirmedPayments = $payments
                ->filter(fn (Payment $payment) => $payment->deleted_at === null && $payment->status === Payment::STATUS_CONFIRMED)
                ->values();
            $cancelledPayments = $payments
                ->filter(fn (Payment $payment) => $payment->deleted_at !== null || $payment->status === Payment::STATUS_CANCELLED)
                ->values();

            $confirmedAllocations = $confirmedPayments
                ->flatMap(fn (Payment $payment) => collect($payment->allocations)
                    ->filter(fn (PaymentAllocation $allocation) => $allocation->deleted_at === null && $allocation->status === PaymentAllocation::STATUS_CONFIRMED)
                    ->values()
                )
                ->values();

            $cancelledAllocations = $payments
                ->flatMap(fn (Payment $payment) => collect($payment->allocations)
                    ->filter(fn (PaymentAllocation $allocation) => $allocation->deleted_at !== null || $allocation->status === PaymentAllocation::STATUS_CANCELLED)
                    ->map(fn (PaymentAllocation $allocation) => [
                        'payment' => $payment,
                        'allocation' => $allocation,
                    ])
                    ->values()
                )
                ->values();

            $activeCredits = $confirmedPayments
                ->flatMap(fn (Payment $payment) => collect($payment->credits)
                    ->filter(fn (AccountCredit $credit) => $credit->deleted_at === null && $credit->status !== AccountCredit::STATUS_CANCELLED)
                    ->values()
                )
                ->values();

            $latestConfirmedPayment = $confirmedPayments
                ->sortByDesc(fn (Payment $payment) => optional($payment->created_at)?->timestamp ?? 0)
                ->first();

            $invoiceAllocations = $confirmedAllocations
                ->filter(fn (PaymentAllocation $allocation) => !empty($allocation->invoice_id))
                ->values();

            $movementAllocations = $confirmedAllocations
                ->filter(fn (PaymentAllocation $allocation) => ($allocation->financialEntry?->origem_tipo ?? null) === 'movement')
                ->values();

            $targetNames = $confirmedPayments
                ->map(function (Payment $payment): ?string {
                    if ($payment->user?->nome_completo || $payment->user?->name) {
                        return (string) ($payment->user->nome_completo ?? $payment->user->name);
                    }

                    if ($payment->family?->nome) {
                        return (string) $payment->family->nome;
                    }

                    return null;
                })
                ->filter()
                ->unique()
                ->values();

            $totalCreditCreated = round((float) $activeCredits->sum(fn (AccountCredit $credit) => (float) $credit->amount), 2);

            $fiscalRows = $fiscalByStatement->get((string) $statement->id, collect());
            $fiscalStatus = $this->resolveFiscalStatus($fiscalRows);

            $allocations = $this->buildAllocationRows(
                $invoiceAllocations,
                $movementAllocations,
                $activeCredits,
                $movementMap,
            );

            $history = $this->buildUnreconciliationHistory($cancelledPayments, $cancelledAllocations);

            $flags = [
                'tem_credito' => $totalCreditCreated > 0,
                'tem_desconciliacao' => $history->isNotEmpty(),
                'tem_documento_fiscal_emitido' => $this->hasIssuedFiscalDocument($fiscalRows),
                'bloqueado_para_desconciliar' => $this->hasIssuedFiscalDocument($fiscalRows),
            ];

            $operationalIssues = [];
            if ($flags['bloqueado_para_desconciliar']) {
                $operationalIssues[] = 'Extrato com documento fiscal emitido associado.';
            }
            if ($this->mapState($statement->conciliacao_status) !== self::STATE_UNRECONCILED && $confirmedPayments->isEmpty()) {
                $operationalIssues[] = 'Estado do extrato indica conciliacao sem pagamentos confirmados ativos.';
            }

            return [
                'bank_statement_id' => (string) $statement->id,
                'data_movimento' => optional($statement->data_movimento)?->toDateString(),
                'descricao' => $statement->descricao,
                'referencia' => $statement->referencia,
                'valor' => round((float) $statement->valor, 2),
                'estado_conciliacao' => $this->mapState($statement->conciliacao_status),
                'valor_alocado' => round((float) ($statement->valor_conciliado ?? 0), 2),
                'valor_por_alocar' => $statement->valor_por_conciliar !== null
                    ? round((float) $statement->valor_por_conciliar, 2)
                    : round(max(abs((float) $statement->valor) - (float) ($statement->valor_conciliado ?? 0), 0), 2),
                'reconciled_at' => optional($latestConfirmedPayment?->created_at)?->toIso8601String(),
                'reconciled_by_name' => $latestConfirmedPayment?->createdBy?->nome_completo
                    ?? $latestConfirmedPayment?->createdBy?->name,
                'metodo_conciliacao' => $this->resolveMethod($statement, $confirmedPayments),
                'target_summary' => [
                    'nomes' => $targetNames->all(),
                    'nome_principal' => $targetNames->first(),
                    'faturas_afetadas' => $invoiceAllocations->pluck('invoice_id')->filter()->unique()->count(),
                    'movimentos_afetados' => $movementAllocations->map(fn (PaymentAllocation $allocation) => $allocation->financialEntry?->origem_id)->filter()->unique()->count(),
                    'valor_credito_criado' => $totalCreditCreated,
                ],
                'allocations' => $allocations->values()->all(),
                'fiscal_status' => $fiscalStatus,
                'flags' => $flags,
                'historico_desconciliacoes' => $history->values()->all(),
                'erros_ou_bloqueios' => $operationalIssues,
            ];
        })->values();
    }

    private function mapState(?string $status): string
    {
        return match ($status) {
            'reconciled' => self::STATE_RECONCILED,
            'partial' => self::STATE_PARTIAL,
            default => self::STATE_UNRECONCILED,
        };
    }

    private function resolveMethod(BankStatement $statement, Collection $confirmedPayments): string
    {
        $maps = collect($statement->reconciliationMaps ?? []);

        if ($maps->contains(fn ($map) => ($map->regra_usada ?? null) === 'suggestion_assisted_allocation')) {
            return self::METHOD_ASSISTED_ALLOCATION;
        }

        if ($maps->contains(fn ($map) => ($map->regra_usada ?? null) === 'suggestion_score')) {
            return self::METHOD_AUTOMATIC_SUGGESTION;
        }

        if ($maps->contains(function ($map): bool {
            return ($map->regra_usada ?? null) === 'bank_statement_settlement'
                && !empty($map->movimento_id)
                && ($map->movement?->origem_tipo ?? null) === 'bank_statement';
        })) {
            return self::METHOD_EXPENSE_FROM_STATEMENT;
        }

        if ($confirmedPayments->contains(fn (Payment $payment) => $payment->source === Payment::SOURCE_MANUAL)
            || $maps->contains(fn ($map) => in_array($map->regra_usada, ['manual', 'manual_payment_allocation'], true))) {
            return self::METHOD_MANUAL_PAYMENT;
        }

        return self::METHOD_OTHER;
    }

    private function buildAllocationRows(
        Collection $invoiceAllocations,
        Collection $movementAllocations,
        Collection $activeCredits,
        Collection $movementMap,
    ): Collection {
        $invoiceRows = $invoiceAllocations->map(function (PaymentAllocation $allocation): array {
            $invoice = $allocation->invoice;
            $memberName = $invoice?->user?->nome_completo ?? $invoice?->user?->name;

            return [
                'tipo' => 'invoice',
                'id' => (string) ($allocation->invoice_id ?? $allocation->id),
                'descricao' => trim(implode(' - ', array_filter([
                    'Fatura',
                    $invoice?->tipo,
                    $memberName,
                ]))),
                'mes' => $invoice?->mes,
                'valor_alocado' => round((float) ($allocation->amount ?? 0), 2),
                'estado' => $invoice?->estado_pagamento,
            ];
        });

        $movementRows = $movementAllocations->map(function (PaymentAllocation $allocation) use ($movementMap): array {
            $movementId = (string) ($allocation->financialEntry?->origem_id ?? '');
            $movement = $movementMap->get($movementId);

            return [
                'tipo' => 'movement',
                'id' => $movementId !== '' ? $movementId : (string) $allocation->id,
                'descricao' => $movement?->nome_manual
                    ?? $allocation->financialEntry?->descricao
                    ?? 'Movimento financeiro',
                'mes' => optional($movement?->data_emissao)?->format('Y-m'),
                'valor_alocado' => round((float) ($allocation->amount ?? 0), 2),
                'estado' => $movement?->estado_pagamento ?? $allocation->financialEntry?->estado,
            ];
        });

        $creditRows = $activeCredits->map(function (AccountCredit $credit): array {
            return [
                'tipo' => 'credit',
                'id' => (string) $credit->id,
                'descricao' => 'Credito criado em conta corrente',
                'mes' => null,
                'valor_alocado' => round((float) ($credit->amount ?? 0), 2),
                'estado' => $credit->status,
            ];
        });

        return $invoiceRows
            ->concat($movementRows)
            ->concat($creditRows)
            ->values();
    }

    private function buildUnreconciliationHistory(Collection $cancelledPayments, Collection $cancelledAllocations): Collection
    {
        $paymentRows = $cancelledPayments->map(function (Payment $payment): array {
            return [
                'tipo' => 'payment_cancelled',
                'payment_id' => (string) $payment->id,
                'payment_allocation_id' => null,
                'cancelled_at' => optional($payment->cancelled_at ?? $payment->deleted_at)?->toIso8601String(),
                'cancelled_by_name' => $payment->cancelledBy?->nome_completo ?? $payment->cancelledBy?->name,
                'motivo' => $payment->notes,
            ];
        });

        $allocationRows = $cancelledAllocations->map(function (array $entry): array {
            /** @var Payment $payment */
            $payment = $entry['payment'];
            /** @var PaymentAllocation $allocation */
            $allocation = $entry['allocation'];

            return [
                'tipo' => 'allocation_cancelled',
                'payment_id' => (string) $payment->id,
                'payment_allocation_id' => (string) $allocation->id,
                'cancelled_at' => optional($allocation->deleted_at ?? $payment->cancelled_at ?? $payment->deleted_at)?->toIso8601String(),
                'cancelled_by_name' => $payment->cancelledBy?->nome_completo ?? $payment->cancelledBy?->name,
                'motivo' => $allocation->notes,
            ];
        });

        return $paymentRows
            ->concat($allocationRows)
            ->sortByDesc(fn (array $row) => strtotime((string) ($row['cancelled_at'] ?? '1970-01-01')))
            ->values();
    }

    /**
     * @return array{Collection<string, Collection<int, FiscalDocumentRequest>>, Collection<string, Collection<int, string>>, Collection<string, Collection<int, string>>}
     */
    private function buildFiscalMaps(Collection $statements): array
    {
        $invoiceStatementMap = collect();
        $entryStatementMap = collect();

        foreach ($statements as $statement) {
            $statementId = (string) $statement->id;

            $invoiceIds = collect($statement->payments)
                ->flatMap(fn (Payment $payment) => collect($payment->allocations)->pluck('invoice_id'))
                ->merge(collect($statement->reconciliationMaps)->pluck('fatura_id'))
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            $entryIds = collect($statement->payments)
                ->flatMap(fn (Payment $payment) => collect($payment->allocations)->pluck('financial_entry_id'))
                ->merge(collect($statement->reconciliationMaps)->pluck('lancamento_id'))
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values();

            foreach ($invoiceIds as $invoiceId) {
                $invoiceStatementMap->put(
                    $invoiceId,
                    collect($invoiceStatementMap->get($invoiceId, []))
                        ->push($statementId)
                        ->unique()
                        ->values()
                );
            }

            foreach ($entryIds as $entryId) {
                $entryStatementMap->put(
                    $entryId,
                    collect($entryStatementMap->get($entryId, []))
                        ->push($statementId)
                        ->unique()
                        ->values()
                );
            }
        }

        $statementIds = $statements->pluck('id')->map(fn ($id) => (string) $id)->values();
        $invoiceIds = $invoiceStatementMap->keys()->values();
        $entryIds = $entryStatementMap->keys()->values();

        $requests = FiscalDocumentRequest::query()
            ->where(function (Builder $query) use ($statementIds, $invoiceIds, $entryIds): void {
                if ($statementIds->isNotEmpty()) {
                    $query->orWhereIn('bank_statement_id', $statementIds->all());
                }

                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhereIn('invoice_id', $invoiceIds->all());
                }

                if ($entryIds->isNotEmpty()) {
                    $query->orWhereIn('financial_entry_id', $entryIds->all());
                }
            })
            ->get(['id', 'status', 'external_document_number', 'bank_statement_id', 'invoice_id', 'financial_entry_id']);

        $fiscalByStatement = collect();

        foreach ($requests as $request) {
            $targets = collect();

            if (!empty($request->bank_statement_id)) {
                $targets->push((string) $request->bank_statement_id);
            }

            if (!empty($request->invoice_id)) {
                $targets = $targets->merge($invoiceStatementMap->get((string) $request->invoice_id, collect()));
            }

            if (!empty($request->financial_entry_id)) {
                $targets = $targets->merge($entryStatementMap->get((string) $request->financial_entry_id, collect()));
            }

            foreach ($targets->unique() as $statementId) {
                $fiscalByStatement->put(
                    (string) $statementId,
                    collect($fiscalByStatement->get((string) $statementId, []))->push($request)
                );
            }
        }

        return [$fiscalByStatement, $invoiceStatementMap, $entryStatementMap];
    }

    private function resolveFiscalStatus(Collection $fiscalRows): ?string
    {
        if ($fiscalRows->isEmpty()) {
            return null;
        }

        $statuses = $fiscalRows->pluck('status')->filter()->unique()->values();

        if ($statuses->contains(FiscalDocumentRequest::STATUS_ISSUED)) {
            return FiscalDocumentRequest::STATUS_ISSUED;
        }

        if ($statuses->contains(FiscalDocumentRequest::STATUS_IN_PROGRESS)) {
            return FiscalDocumentRequest::STATUS_IN_PROGRESS;
        }

        if ($statuses->contains(FiscalDocumentRequest::STATUS_PENDING)) {
            return FiscalDocumentRequest::STATUS_PENDING;
        }

        if ($statuses->contains(FiscalDocumentRequest::STATUS_ERROR_DATA)
            || $statuses->contains(FiscalDocumentRequest::STATUS_API_ERROR)) {
            return 'error';
        }

        if ($statuses->contains(FiscalDocumentRequest::STATUS_CANCELLED)) {
            return FiscalDocumentRequest::STATUS_CANCELLED;
        }

        return (string) $statuses->first();
    }

    private function hasIssuedFiscalDocument(Collection $fiscalRows): bool
    {
        return $fiscalRows->contains(function (FiscalDocumentRequest $request): bool {
            if ($request->status === FiscalDocumentRequest::STATUS_ISSUED) {
                return true;
            }

            return !empty($request->external_document_number);
        });
    }
}
