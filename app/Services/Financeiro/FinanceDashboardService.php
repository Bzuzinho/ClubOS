<?php

namespace App\Services\Financeiro;

use App\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceDashboardService
{
    public function __construct(
        private readonly FinanceReportQueryService $queryService,
    ) {
    }

    public function build(array $filters = []): array
    {
        $now = !empty($filters['reference_date'])
            ? Carbon::parse($filters['reference_date'])
            : Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $monthlyPaidThisMonth = (float) $this->queryService->paidMonthlyInvoices($monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidRevenueEntriesThisMonth = (float) $this->queryService->paidCanonicalFinancialEntries('receita', $monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidExpenseEntriesThisMonth = (float) $this->queryService->paidCanonicalFinancialEntries('despesa', $monthStart, $monthEnd, $filters)->sum('valor_pago');
        $paidLegacyRevenueThisMonth = (float) $this->queryService->paidLegacyMovements('receita', $monthStart, $monthEnd, $filters)->sum('valor_total');
        $paidLegacyExpenseThisMonth = (float) $this->queryService->paidLegacyMovements('despesa', $monthStart, $monthEnd, $filters)->sum('valor_total');

        $totalReceitas = (float) $this->queryService->paidMonthlyInvoices(null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidCanonicalFinancialEntries('receita', null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidLegacyMovements('receita', null, null, $filters)->sum('valor_total');
        $totalDespesas = (float) $this->queryService->paidCanonicalFinancialEntries('despesa', null, null, $filters)->sum('valor_pago')
            + (float) $this->queryService->paidLegacyMovements('despesa', null, null, $filters)->sum('valor_total');

        $receitasMes = round($monthlyPaidThisMonth + $paidRevenueEntriesThisMonth + $paidLegacyRevenueThisMonth, 2);
        $despesasMes = round($paidExpenseEntriesThisMonth + $paidLegacyExpenseThisMonth, 2);

        $movimentosPendentes = round(
            (float) $this->queryService->pendingCanonicalFinancialEntries($filters)->sum('valor_em_aberto')
            + (float) $this->queryService->pendingLegacyMovements($filters)->sum('valor_total'),
            2
        );

        return [
            'total_geral' => round($totalReceitas - $totalDespesas, 2),
            'receitas_mes' => $receitasMes,
            'despesas_mes' => $despesasMes,
            'mensalidades_vencidas' => round((float) $this->queryService->overdueMonthlyInvoices($filters)->sum('valor_em_aberto'), 2),
            'movimentos_pendentes' => $movimentosPendentes,
            'alerts' => $this->buildExpenseAlerts($now),
            'distribuicao_por_tipo' => $this->buildDistributionByType($filters),
            'evolucao_mensal_ultimos_6_meses' => $this->buildMonthlyEvolution($now, $filters),
            'receitas_despesas_por_centro_custo' => $this->buildCostCenterSummary($filters),
        ];
    }

    private function buildExpenseAlerts(Carbon $referenceDate): array
    {
        $expenseMovements = Movement::query()->where('classificacao', 'despesa');

        return [
            'paid_without_invoice' => (clone $expenseMovements)->where('estado_pagamento', 'pago')->where('estado_documental', 'falta_fatura')->count(),
            'paid_without_receipt' => (clone $expenseMovements)->where('estado_pagamento', 'pago')->where('estado_documental', 'falta_recibo')->count(),
            'missing_payment_proof' => (clone $expenseMovements)->where('estado_documental', 'falta_comprovativo_pagamento')->count(),
            'overdue_unpaid' => (clone $expenseMovements)
                ->whereIn('estado_pagamento', ['pendente', 'por_pagar'])
                ->whereDate('data_vencimento', '<', $referenceDate->toDateString())
                ->count(),
            'amount_mismatch' => (clone $expenseMovements)->where('estado_documental', 'inconsistente')->count(),
            'stock_without_document' => (clone $expenseMovements)
                ->where('origem_tipo', 'stock')
                ->whereIn('estado_documental', ['sem_documentos', 'falta_fatura', 'pendente_validacao'])
                ->count(),
        ];
    }

    private function buildDistributionByType(array $filters = []): array
    {
        $monthlyTotal = (float) $this->queryService->paidMonthlyInvoices(null, null, $filters)->sum('valor_pago');
        $entries = $this->queryService->canonicalFinancialEntries($filters)
            ->where('estado', 'pago')
            ->selectRaw('tipo, COALESCE(categoria, origem_tipo, ? ) as label, SUM(valor_pago) as total', ['Sem categoria'])
            ->groupBy('tipo', 'label')
            ->get();

        $distribution = collect();

        if ($monthlyTotal > 0) {
            $distribution->push([
                'tipo' => 'receita',
                'label' => 'mensalidade',
                'total' => round($monthlyTotal, 2),
            ]);
        }

        return $distribution
            ->merge($entries->map(fn ($row) => [
                'tipo' => $row->tipo,
                'label' => $row->label,
                'total' => round((float) $row->total, 2),
            ]))
            ->values()
            ->all();
    }

    private function buildMonthlyEvolution(Carbon $referenceDate, array $filters = []): array
    {
        $months = collect(range(0, 5))->map(fn (int $offset) => $referenceDate->copy()->subMonths(5 - $offset));

        return $months->map(function (Carbon $month) use ($filters): array {
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            return [
                'mes' => $month->format('Y-m'),
                'receitas' => round(
                    (float) $this->queryService->paidMonthlyInvoices(Carbon::parse($start), Carbon::parse($end), $filters)->sum('valor_pago')
                    + (float) $this->queryService->paidCanonicalFinancialEntries('receita', Carbon::parse($start), Carbon::parse($end), $filters)->sum('valor_pago')
                    + (float) $this->queryService->paidLegacyMovements('receita', Carbon::parse($start), Carbon::parse($end), $filters)->sum('valor_total'),
                    2
                ),
                'despesas' => round(
                    (float) $this->queryService->paidCanonicalFinancialEntries('despesa', Carbon::parse($start), Carbon::parse($end), $filters)->sum('valor_pago')
                    + (float) $this->queryService->paidLegacyMovements('despesa', Carbon::parse($start), Carbon::parse($end), $filters)->sum('valor_total'),
                    2
                ),
            ];
        })->all();
    }

    private function buildCostCenterSummary(array $filters = []): array
    {
        return $this->queryService->costCenters()
            ->map(function ($costCenter) use ($filters): array {
                $costCenterFilters = array_merge($filters, [
                    'centro_custo_id' => $costCenter->id,
                ]);

                return [
                    'centro_custo_id' => $costCenter->id,
                    'centro_custo_nome' => $costCenter->nome,
                    'receitas' => round(
                        (float) $this->queryService->paidMonthlyInvoices(null, null, $costCenterFilters)->sum('valor_pago')
                        + (float) $this->queryService->paidCanonicalFinancialEntries('receita', null, null, $costCenterFilters)->sum('valor_pago')
                        + (float) $this->queryService->paidLegacyMovements('receita', null, null, $costCenterFilters)->sum('valor_total'),
                        2
                    ),
                    'despesas' => round(
                        (float) $this->queryService->paidCanonicalFinancialEntries('despesa', null, null, $costCenterFilters)->sum('valor_pago')
                        + (float) $this->queryService->paidLegacyMovements('despesa', null, null, $costCenterFilters)->sum('valor_total'),
                        2
                    ),
                ];
            })
            ->filter(fn (array $row) => $row['receitas'] > 0 || $row['despesas'] > 0)
            ->values()
            ->all();
    }
}