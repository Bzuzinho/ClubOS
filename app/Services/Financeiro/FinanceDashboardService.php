<?php

namespace App\Services\Financeiro;

use App\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceDashboardService
{
    public function __construct(
        private readonly FinanceReportQueryService $queryService,
        private readonly FinancialReportingFactService $financialReportingFactService,
        private readonly MovementDocumentControlService $movementDocumentControlService,
    ) {
    }

    public function build(array $filters = []): array
    {
        $now = !empty($filters['reference_date'])
            ? Carbon::parse($filters['reference_date'])
            : Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $monthFacts = $this->financialReportingFactService->paidFacts($monthStart, $monthEnd, $filters);
        $allFacts = $this->financialReportingFactService->paidFacts(null, null, $filters);

        $totalReceitas = (float) $allFacts->where('type', 'receita')->sum('amount');
        $totalDespesas = (float) $allFacts->where('type', 'despesa')->sum('amount');

        $receitasMes = round((float) $monthFacts->where('type', 'receita')->sum('amount'), 2);
        $despesasMes = round((float) $monthFacts->where('type', 'despesa')->sum('amount'), 2);

        $movimentosPendentes = round(
            (float) $this->queryService->pendingCanonicalFinancialEntries($filters)
                ->get(['valor_em_aberto'])
                ->sum(fn ($entry) => abs((float) ($entry->valor_em_aberto ?? 0)))
            + (float) $this->queryService->pendingLegacyMovements($filters)
                ->get(['valor_total'])
                ->sum(fn ($movement) => abs((float) ($movement->valor_total ?? 0))),
            2
        );

        return [
            'total_geral' => round($totalReceitas - $totalDespesas, 2),
            'receitas_mes' => $receitasMes,
            'despesas_mes' => $despesasMes,
            'mensalidades_vencidas' => round((float) $this->queryService->overdueMonthlyInvoices($filters)->sum('valor_em_aberto'), 2),
            'movimentos_pendentes' => $movimentosPendentes,
            'alerts' => $this->buildExpenseAlerts($now),
            'distribuicao_por_tipo' => $this->buildDistributionByType($allFacts),
            'evolucao_mensal_ultimos_6_meses' => $this->buildMonthlyEvolution($now, $filters),
            'receitas_despesas_por_centro_custo' => $this->buildCostCenterSummary($allFacts),
        ];
    }

    private function buildExpenseAlerts(Carbon $referenceDate): array
    {
        $expenseMovements = Movement::query()
            ->where('classificacao', 'despesa')
            ->with('documents')
            ->get();

        $evaluated = $expenseMovements->map(function (Movement $movement): array {
            return [
                'movement' => $movement,
                'evaluation' => $this->movementDocumentControlService->evaluate($movement),
            ];
        });

        $isPaid = static fn (Movement $movement): bool => in_array(
            (string) $movement->estado_pagamento,
            ['pago', 'parcial', 'pago_parcial'],
            true,
        );

        return [
            'paid_without_invoice' => $evaluated->filter(fn (array $row): bool =>
                $isPaid($row['movement'])
                && in_array('invoice', $row['evaluation']['missing_documents'], true)
            )->count(),
            'paid_without_receipt' => $evaluated->filter(fn (array $row): bool =>
                $isPaid($row['movement'])
                && in_array('receipt', $row['evaluation']['missing_documents'], true)
            )->count(),
            'missing_payment_proof' => $evaluated->filter(fn (array $row): bool =>
                in_array('payment_proof', $row['evaluation']['missing_documents'], true)
            )->count(),
            'overdue_unpaid' => $expenseMovements->filter(function (Movement $movement) use ($referenceDate): bool {
                if (!in_array((string) $movement->estado_pagamento, ['pendente', 'por_pagar', 'vencido'], true)) {
                    return false;
                }

                return $movement->data_vencimento !== null
                    && Carbon::parse($movement->data_vencimento)->lt($referenceDate->copy()->startOfDay());
            })->count(),
            'amount_mismatch' => $evaluated->filter(fn (array $row): bool =>
                (bool) ($row['evaluation']['has_amount_mismatch'] ?? false)
            )->count(),
            'stock_without_document' => $evaluated->filter(fn (array $row): bool =>
                $row['movement']->origem_tipo === 'stock'
                && in_array(
                    (string) ($row['evaluation']['estado_documental'] ?? ''),
                    ['sem_documentos', 'falta_fatura', 'falta_recibo', 'falta_comprovativo_pagamento', 'pendente_validacao'],
                    true,
                )
            )->count(),
        ];
    }

    private function buildDistributionByType(Collection $facts): array
    {
        return $facts
            ->groupBy(fn (array $fact): string => $fact['type'].'|'.$fact['origem_tipo'])
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'tipo' => $first['type'],
                    'label' => $first['origem_tipo'] ?: 'Sem categoria',
                    'total' => round((float) $group->sum('amount'), 2),
                ];
            })
            ->values()
            ->all();
    }

    private function buildMonthlyEvolution(Carbon $referenceDate, array $filters = []): array
    {
        $months = collect(range(0, 5))->map(fn (int $offset) => $referenceDate->copy()->subMonths(5 - $offset));

        $firstMonth = $months->first()?->copy()->startOfMonth();
        $lastMonth = $months->last()?->copy()->endOfMonth();
        $facts = $this->financialReportingFactService->paidFacts($firstMonth, $lastMonth, $filters);
        $factsByMonth = $facts->groupBy(fn (array $fact): string => Carbon::parse($fact['paid_at'])->format('Y-m'));

        return $months->map(function (Carbon $month) use ($factsByMonth): array {
            $monthKey = $month->format('Y-m');
            $rows = $factsByMonth->get($monthKey, collect());

            return [
                'mes' => $monthKey,
                'receitas' => round((float) $rows->where('type', 'receita')->sum('amount'), 2),
                'despesas' => round((float) $rows->where('type', 'despesa')->sum('amount'), 2),
            ];
        })->all();
    }

    private function buildCostCenterSummary(Collection $facts): array
    {
        $factsByCostCenter = $facts->groupBy(fn (array $fact): string => (string) ($fact['centro_custo_id'] ?? ''));

        return $this->queryService->costCenters()
            ->map(function ($costCenter) use ($factsByCostCenter): array {
                $rows = $factsByCostCenter->get((string) $costCenter->id, collect());

                return [
                    'centro_custo_id' => $costCenter->id,
                    'centro_custo_nome' => $costCenter->nome,
                    'receitas' => round((float) $rows->where('type', 'receita')->sum('amount'), 2),
                    'despesas' => round((float) $rows->where('type', 'despesa')->sum('amount'), 2),
                ];
            })
            ->filter(fn (array $row) => $row['receitas'] > 0 || $row['despesas'] > 0)
            ->values()
            ->all();
    }
}
