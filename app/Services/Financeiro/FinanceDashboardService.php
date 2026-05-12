<?php

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceDashboardService
{
    public function build(): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();

        $mensalidadesVencidas = Invoice::query()
            ->where('tipo', 'mensalidade')
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->where('oculta', false)
            ->sum('valor_em_aberto');

        $receitasMes = FinancialEntry::query()
            ->where('tipo', 'receita')
            ->where('estado', 'pago')
            ->whereDate('data_pagamento', '>=', $monthStart->toDateString())
            ->sum('valor_pago');

        $despesasMes = FinancialEntry::query()
            ->where('tipo', 'despesa')
            ->where('estado', 'pago')
            ->whereDate('data_pagamento', '>=', $monthStart->toDateString())
            ->sum('valor_pago');

        return [
            'total_geral' => round((float) ($receitasMes - $despesasMes), 2),
            'receitas_mes' => round((float) $receitasMes, 2),
            'despesas_mes' => round((float) $despesasMes, 2),
            'mensalidades_vencidas' => round((float) $mensalidadesVencidas, 2),
            'movimentos_pendentes' => round((float) FinancialEntry::query()->whereIn('estado', ['pendente', 'parcial'])->sum('valor_em_aberto'), 2),
            'distribuicao_por_tipo' => $this->groupTotals('tipo'),
            'evolucao_mensal_ultimos_6_meses' => $this->buildMonthlyEvolution(),
            'receitas_despesas_por_centro_custo' => $this->buildCostCenterSummary(),
        ];
    }

    private function groupTotals(string $column): array
    {
        return FinancialEntry::query()
            ->selectRaw($column . ', SUM(valor) as total')
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->{$column},
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    private function buildMonthlyEvolution(): array
    {
        $months = collect(range(0, 5))->map(fn (int $offset) => now()->copy()->subMonths(5 - $offset));

        return $months->map(function (Carbon $month): array {
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();

            return [
                'mes' => $month->format('Y-m'),
                'receitas' => round((float) FinancialEntry::query()
                    ->where('tipo', 'receita')
                    ->whereBetween('data', [$start, $end])
                    ->sum('valor'), 2),
                'despesas' => round((float) FinancialEntry::query()
                    ->where('tipo', 'despesa')
                    ->whereBetween('data', [$start, $end])
                    ->sum('valor'), 2),
            ];
        })->all();
    }

    private function buildCostCenterSummary(): array
    {
        return FinancialEntry::query()
            ->selectRaw('centro_custo_id, tipo, SUM(valor) as total')
            ->groupBy('centro_custo_id', 'tipo')
            ->get()
            ->groupBy('centro_custo_id')
            ->map(function (Collection $rows, string $costCenterId): array {
                return [
                    'centro_custo_id' => $costCenterId,
                    'receitas' => round((float) $rows->where('tipo', 'receita')->sum('total'), 2),
                    'despesas' => round((float) $rows->where('tipo', 'despesa')->sum('total'), 2),
                ];
            })
            ->values()
            ->all();
    }
}