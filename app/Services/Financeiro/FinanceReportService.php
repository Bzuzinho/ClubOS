<?php

namespace App\Services\Financeiro;

use App\Models\AgeGroup;
use App\Models\User;
use App\Services\Members\MemberFiscalDataResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceReportService
{
    public function __construct(
        private readonly FinanceReportQueryService $queryService,
        private readonly MemberFiscalDataResolver $memberFiscalDataResolver,
    ) {
    }

    public function summary(array $filters = []): array
    {
        $now = !empty($filters['reference_date'])
            ? Carbon::parse($filters['reference_date'])
            : now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $paidMonthlyInvoices = $this->queryService->paidMonthlyInvoices($monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidRevenueEntries = $this->queryService->paidCanonicalFinancialEntries('receita', $monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidExpenseEntries = $this->queryService->paidCanonicalFinancialEntries('despesa', $monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidLegacyRevenue = $this->queryService->paidLegacyMovements('receita', $monthStart, $monthEnd, $filters)->sum('valor_total');
        $paidLegacyExpense = $this->queryService->paidLegacyMovements('despesa', $monthStart, $monthEnd, $filters)->sum('valor_total');

        $totalRevenue = (float) $this->queryService->paidMonthlyInvoices(null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidCanonicalFinancialEntries('receita', null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidLegacyMovements('receita', null, null, $filters)->sum('valor_total');
        $totalExpense = (float) $this->queryService->paidCanonicalFinancialEntries('despesa', null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidLegacyMovements('despesa', null, null, $filters)->sum('valor_total');

        return [
            'saldoAtual' => round($totalRevenue - $totalExpense, 2),
            'receitasMes' => round((float) $paidMonthlyInvoices + (float) $paidRevenueEntries + (float) $paidLegacyRevenue, 2),
            'despesasMes' => round((float) $paidExpenseEntries + (float) $paidLegacyExpense, 2),
            'mensalidadesAtrasadas' => round((float) $this->queryService->overdueMonthlyInvoices($filters)->sum('valor_em_aberto'), 2),
            'totalReceitas' => round($totalRevenue, 2),
            'totalDespesas' => round($totalExpense, 2),
        ];
    }

    public function reports(array $filters = []): array
    {
        [$start, $end] = $this->resolveDateRange($filters);

        $monthlyInvoices = $this->queryService->visibleMonthlyInvoices($filters)
            ->get(['id', 'user_id', 'centro_custo_id', 'valor_total', 'valor_pago', 'valor_em_aberto', 'estado_pagamento', 'data_pagamento', 'data_fatura']);

        $paidMonthlyInvoices = $this->queryService->paidMonthlyInvoices($start, $end, $filters)
            ->get(['id', 'user_id', 'centro_custo_id', 'valor_total', 'valor_pago', 'valor_em_aberto', 'data_pagamento', 'data_fatura']);

        $paidCanonicalEntries = $this->queryService->paidCanonicalFinancialEntries(null, $start, $end, $filters)
            ->get(['id', 'user_id', 'centro_custo_id', 'tipo', 'valor', 'valor_pago', 'data_pagamento', 'origem_modulo', 'origem_tipo']);

        $paidLegacyMovements = $this->queryService->paidLegacyMovements(null, $start, $end, $filters)
            ->get(['id', 'user_id', 'centro_custo_id', 'classificacao', 'valor_total', 'data_emissao', 'origem_tipo']);

        $reportRows = $this->buildReportRows($paidMonthlyInvoices, $paidCanonicalEntries, $paidLegacyMovements);

        return [
            'filters' => [
                'data_inicio' => $start?->toDateString(),
                'data_fim' => $end?->toDateString(),
                'centro_custo_id' => $filters['centro_custo_id'] ?? null,
                'user_id' => $filters['user_id'] ?? null,
                'tipo' => $filters['tipo'] ?? null,
                'origem_modulo' => $filters['origem_modulo'] ?? null,
                'origem_tipo' => $filters['origem_tipo'] ?? null,
            ],
            'reports' => [
                'period' => $this->buildPeriodReport($reportRows),
                'cost_centers' => $this->buildCostCenterReport($reportRows),
                'age_groups' => $this->buildAgeGroupReport($monthlyInvoices, $paidCanonicalEntries, $paidLegacyMovements, $filters),
                'athletes' => $this->buildAthleteReport($paidMonthlyInvoices, $paidCanonicalEntries, $paidLegacyMovements, $filters),
            ],
        ];
    }

    private function buildReportRows(Collection $paidMonthlyInvoices, Collection $paidCanonicalEntries, Collection $paidLegacyMovements): Collection
    {
        $monthlyRows = $paidMonthlyInvoices->map(function ($invoice): array {
            $paidAmount = $this->toAmount($invoice->valor_pago ?? $invoice->valor_total);
            $paidAt = $invoice->data_pagamento ? Carbon::parse($invoice->data_pagamento) : Carbon::parse($invoice->data_fatura);

            return [
                'kind' => 'invoice',
                'type' => 'receita',
                'amount' => $paidAmount,
                'date' => $paidAt,
                'user_id' => $invoice->user_id,
                'centro_custo_id' => $invoice->centro_custo_id,
                'origem_modulo' => 'mensalidades',
                'origem_tipo' => 'mensalidade',
            ];
        });

        $canonicalRows = $paidCanonicalEntries->map(function ($entry): array {
            return [
                'kind' => 'financial_entry',
                'type' => $entry->tipo,
                'amount' => $this->toAmount($entry->valor_pago ?? $entry->valor),
                'date' => $entry->data_pagamento ? Carbon::parse($entry->data_pagamento) : null,
                'user_id' => $entry->user_id,
                'centro_custo_id' => $entry->centro_custo_id,
                'origem_modulo' => $entry->origem_modulo,
                'origem_tipo' => $entry->origem_tipo,
            ];
        });

        $legacyRows = $paidLegacyMovements->map(function ($movement): array {
            return [
                'kind' => 'movement',
                'type' => $movement->classificacao,
                'amount' => $this->toAmount($movement->valor_total),
                'date' => $movement->data_emissao ? Carbon::parse($movement->data_emissao) : null,
                'user_id' => $movement->user_id,
                'centro_custo_id' => $movement->centro_custo_id,
                'origem_modulo' => null,
                'origem_tipo' => $movement->origem_tipo,
            ];
        });

        return $monthlyRows
            ->concat($canonicalRows)
            ->concat($legacyRows)
            ->filter(fn (array $row): bool => $row['date'] instanceof Carbon)
            ->values();
    }

    private function buildPeriodReport(Collection $rows): array
    {
        $items = $rows
            ->groupBy(fn (array $row) => $row['date']->format('Y-m'))
            ->map(function (Collection $group, string $period): array {
                $revenue = round($group->where('type', 'receita')->sum('amount'), 2);
                $expense = round($group->where('type', 'despesa')->sum('amount'), 2);

                return [
                    'period_key' => $period,
                    'period_label' => Carbon::createFromFormat('Y-m', $period)->format('m/Y'),
                    'receitas' => $revenue,
                    'despesas' => $expense,
                    'saldo' => round($revenue - $expense, 2),
                ];
            })
            ->sortBy('period_key')
            ->values();

        return [
            'available' => true,
            'empty_message' => 'Nenhum movimento canonico encontrado para o periodo selecionado.',
            'items' => $items->all(),
            'totals' => $this->buildTotals($items, 'receitas', 'despesas'),
        ];
    }

    private function buildCostCenterReport(Collection $rows): array
    {
        $costCenterMap = $this->queryService->costCenters()->keyBy('id');

        $items = $rows
            ->groupBy(fn (array $row) => (string) ($row['centro_custo_id'] ?? 'sem-centro-custo'))
            ->map(function (Collection $group, string $costCenterId) use ($costCenterMap): array {
                $costCenter = $costCenterMap->get($costCenterId);
                $revenue = round($group->where('type', 'receita')->sum('amount'), 2);
                $expense = round($group->where('type', 'despesa')->sum('amount'), 2);

                return [
                    'id' => $costCenter?->id,
                    'nome' => $costCenter?->nome ?? 'Sem centro de custo',
                    'tipo' => $costCenter?->tipo ?? 'indefinido',
                    'receitas' => $revenue,
                    'despesas' => $expense,
                    'saldo' => round($revenue - $expense, 2),
                ];
            })
            ->sortByDesc(fn (array $item) => $item['despesas'] + $item['receitas'])
            ->values();

        return [
            'available' => true,
            'empty_message' => 'Nenhum dado canonico encontrado para os centros de custo selecionados.',
            'items' => $items->all(),
            'totals' => $this->buildTotals($items, 'receitas', 'despesas'),
        ];
    }

    private function buildAgeGroupReport(Collection $monthlyInvoices, Collection $paidCanonicalEntries, Collection $paidLegacyMovements, array $filters): array
    {
        $users = $this->loadReportUsers($monthlyInvoices, $paidCanonicalEntries, $paidLegacyMovements, $filters);
        $ageGroupNames = AgeGroup::query()->pluck('nome', 'id');
        $available = $users->contains(fn (User $user): bool => !empty($user->escalao));

        if (! $available) {
            return [
                'available' => false,
                'empty_message' => 'Sem dados de escalão suficientes para construir o relatório.',
                'items' => [],
                'totals' => $this->buildTotals(collect(), 'receitas', 'despesas'),
            ];
        }

        $monthlyByUser = $monthlyInvoices->groupBy('user_id');
        $revenueByUser = $paidCanonicalEntries
            ->where('tipo', 'receita')
            ->concat($paidLegacyMovements->where('classificacao', 'receita'))
            ->groupBy('user_id');
        $expenseByUser = $paidCanonicalEntries
            ->where('tipo', 'despesa')
            ->concat($paidLegacyMovements->where('classificacao', 'despesa'))
            ->groupBy('user_id');

        $items = $users
            ->flatMap(function (User $user) use ($ageGroupNames, $monthlyByUser, $revenueByUser, $expenseByUser): array {
                $ageGroups = collect($user->escalao ?? [])->filter()->values();

                return $ageGroups->map(function (string $ageGroupId) use ($user, $ageGroupNames, $monthlyByUser, $revenueByUser, $expenseByUser): array {
                    $monthlyInvoices = $monthlyByUser->get($user->id, collect());
                    $revenueRows = $revenueByUser->get($user->id, collect());
                    $expenseRows = $expenseByUser->get($user->id, collect());

                    return [
                        'age_group_id' => $ageGroupId,
                        'age_group' => $ageGroupNames->get($ageGroupId, $ageGroupId),
                        'numero_atletas' => 1,
                        'receitas' => round($revenueRows->sum(fn ($row) => $this->toAmount($row->valor_pago ?? $row->valor_total ?? $row->valor)), 2),
                        'total_faturado' => round($monthlyInvoices->sum(fn ($invoice) => $this->toAmount($invoice->valor_total)), 2),
                        'total_pago' => round($monthlyInvoices->sum(fn ($invoice) => $this->toAmount($invoice->valor_pago)), 2),
                        'total_pendente' => round($monthlyInvoices->sum(fn ($invoice) => $this->toAmount($invoice->valor_em_aberto)), 2),
                        'despesas' => round($expenseRows->sum(fn ($row) => $this->toAmount($row->valor_pago ?? $row->valor_total ?? $row->valor)), 2),
                    ];
                })->all();
            })
            ->groupBy('age_group_id')
            ->map(function (Collection $group): array {
                $first = $group->first();
                $revenue = round($group->sum('receitas') + $group->sum('total_pago'), 2);
                $expense = round($group->sum('despesas'), 2);

                return [
                    'age_group_id' => $first['age_group_id'],
                    'age_group' => $first['age_group'],
                    'numero_atletas' => (int) $group->sum('numero_atletas'),
                    'receitas' => round($group->sum('receitas'), 2),
                    'total_faturado' => round($group->sum('total_faturado'), 2),
                    'total_pago' => round($group->sum('total_pago'), 2),
                    'total_pendente' => round($group->sum('total_pendente'), 2),
                    'despesas' => $expense,
                    'peso_financeiro' => round($revenue - $expense, 2),
                ];
            })
            ->sortByDesc('peso_financeiro')
            ->values();

        return [
            'available' => true,
            'empty_message' => 'Nenhum escalão com dados financeiros encontrado para os filtros selecionados.',
            'items' => $items->all(),
            'totals' => [
                'numero_atletas' => (int) $items->sum('numero_atletas'),
                'receitas' => round($items->sum('receitas'), 2),
                'total_faturado' => round($items->sum('total_faturado'), 2),
                'total_pago' => round($items->sum('total_pago'), 2),
                'total_pendente' => round($items->sum('total_pendente'), 2),
                'despesas' => round($items->sum('despesas'), 2),
                'peso_financeiro' => round($items->sum('peso_financeiro'), 2),
            ],
        ];
    }

    private function buildAthleteReport(Collection $paidMonthlyInvoices, Collection $paidCanonicalEntries, Collection $paidLegacyMovements, array $filters): array
    {
        $users = $this->loadReportUsers($paidMonthlyInvoices, $paidCanonicalEntries, $paidLegacyMovements, $filters)
            ->filter(fn (User $user): bool => in_array('atleta', $user->tipo_membro ?? [], true));

        if ($users->isEmpty()) {
            return [
                'available' => false,
                'empty_message' => 'Sem atletas suficientes para calcular o peso financeiro.',
                'items' => [],
                'totals' => $this->buildTotals(collect(), 'valor_pago', 'valor_gasto'),
            ];
        }

        $monthlyByUser = $paidMonthlyInvoices->groupBy('user_id');
        $revenueByUser = $paidCanonicalEntries
            ->where('tipo', 'receita')
            ->concat($paidLegacyMovements->where('classificacao', 'receita'))
            ->groupBy('user_id');
        $expenseByUser = $paidCanonicalEntries
            ->where('tipo', 'despesa')
            ->concat($paidLegacyMovements->where('classificacao', 'despesa'))
            ->groupBy('user_id');
        $ageGroupNames = AgeGroup::query()->pluck('nome', 'id');

        $items = $users
            ->map(function (User $user) use ($monthlyByUser, $revenueByUser, $expenseByUser, $ageGroupNames): array {
                $invoiceRevenue = round($monthlyByUser->get($user->id, collect())->sum(fn ($invoice) => $this->toAmount($invoice->valor_pago)), 2);
                $extraRevenue = round($revenueByUser->get($user->id, collect())->sum(fn ($row) => $this->toAmount($row->valor_pago ?? $row->valor_total ?? $row->valor)), 2);
                $expense = round($expenseByUser->get($user->id, collect())->sum(fn ($row) => $this->toAmount($row->valor_pago ?? $row->valor_total ?? $row->valor)), 2);
                $paid = round($invoiceRevenue + $extraRevenue, 2);

                return [
                    'id' => $user->id,
                    'nome' => $this->memberFiscalDataResolver->displayName($user),
                    'numero_socio' => $user->numero_socio,
                    'escalao' => collect($user->escalao ?? [])
                        ->map(fn (string $ageGroupId) => $ageGroupNames->get($ageGroupId, $ageGroupId))
                        ->implode(', '),
                    'valor_pago' => $paid,
                    'valor_gasto' => $expense,
                    'peso_financeiro' => round($paid - $expense, 2),
                ];
            })
            ->sortByDesc('peso_financeiro')
            ->values();

        return [
            'available' => true,
            'empty_message' => 'Nenhum atleta com dados financeiros encontrado para os filtros selecionados.',
            'items' => $items->all(),
            'totals' => [
                'valor_pago' => round($items->sum('valor_pago'), 2),
                'valor_gasto' => round($items->sum('valor_gasto'), 2),
                'peso_financeiro' => round($items->sum('peso_financeiro'), 2),
            ],
        ];
    }

    private function loadReportUsers(Collection $monthlyInvoices, Collection $paidCanonicalEntries, Collection $paidLegacyMovements, array $filters): Collection
    {
        $userIds = collect([$filters['user_id'] ?? null])
            ->merge($monthlyInvoices->pluck('user_id'))
            ->merge($paidCanonicalEntries->pluck('user_id'))
            ->merge($paidLegacyMovements->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->with('dadosPessoais:id,user_id,nome_completo,nif,morada,codigo_postal,localidade,contacto,email_secundario,telemovel,contacto_telefonico')
            ->get(['id', 'name', 'numero_socio', 'tipo_membro', 'escalao']);
    }

    private function buildTotals(Collection $items, string $revenueKey, string $expenseKey): array
    {
        $revenue = round($items->sum($revenueKey), 2);
        $expense = round($items->sum($expenseKey), 2);

        return [
            'receitas' => $revenue,
            'despesas' => $expense,
            'saldo' => round($revenue - $expense, 2),
        ];
    }

    private function resolveDateRange(array $filters): array
    {
        $start = !empty($filters['data_inicio']) ? Carbon::parse($filters['data_inicio'])->startOfDay() : null;
        $end = !empty($filters['data_fim']) ? Carbon::parse($filters['data_fim'])->endOfDay() : null;

        return [$start, $end];
    }

    private function toAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(abs((float) $value), 2);
    }
}