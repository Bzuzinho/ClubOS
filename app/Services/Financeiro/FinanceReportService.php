<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Carbon;

class FinanceReportService
{
    public function __construct(
        private readonly FinanceReportQueryService $queryService,
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
}