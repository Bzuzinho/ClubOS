import { lazy, Suspense, useEffect, useState } from 'react';
import { Card } from '@/Components/ui/card';
import { FinanceDashboardData } from './types';
import { TrendUp, TrendDown, Wallet, Receipt, WarningCircle } from '@phosphor-icons/react';

const DashboardCharts = lazy(() => import('./DashboardCharts'));

interface DashboardTabProps {
  dashboardData: FinanceDashboardData;
}

export function DashboardTab({ dashboardData }: DashboardTabProps) {
  const [showCharts, setShowCharts] = useState(false);

  useEffect(() => {
    const callback = () => setShowCharts(true);

    if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
      const idleId = window.requestIdleCallback(callback, { timeout: 800 });

      return () => window.cancelIdleCallback(idleId);
    }

    const timeoutId = window.setTimeout(callback, 120);

    return () => window.clearTimeout(timeoutId);
  }, []);

  const stats = {
    totalGeral: dashboardData?.total_geral ?? 0,
    receitasMes: dashboardData?.receitas_mes ?? 0,
    despesasMes: dashboardData?.despesas_mes ?? 0,
    valorMensalidadesVencidas: dashboardData?.mensalidades_vencidas ?? 0,
    valorPendentes: dashboardData?.movimentos_pendentes ?? 0,
    saldoMes: (dashboardData?.receitas_mes ?? 0) - (dashboardData?.despesas_mes ?? 0),
    saldoTotal: dashboardData?.total_geral ?? 0,
  };

  const centrosCustoData = (dashboardData?.receitas_despesas_por_centro_custo ?? []).map((row) => ({
    nome: row.centro_custo_id ?? 'Sem centro de custo',
    despesas: row.despesas ?? 0,
    receitas: row.receitas ?? 0,
    saldo: (row.receitas ?? 0) - (row.despesas ?? 0),
  }));

  const monthlyData = (dashboardData?.evolucao_mensal_ultimos_6_meses ?? []).map((row) => ({
    mes: row.mes ?? '-',
    receitas: row.receitas ?? 0,
    despesas: row.despesas ?? 0,
  }));

  const tiposFaturaData = (dashboardData?.distribuicao_por_tipo ?? []).map((row) => ({
    name: row.label ?? '-',
    value: row.total ?? 0,
  })).filter((row) => row.value > 0);

  const COLORS = [
    'oklch(0.45 0.15 250)',
    'oklch(0.68 0.18 45)',
    'oklch(0.55 0.22 25)',
    'oklch(0.6 0.15 150)',
    'oklch(0.5 0.12 300)',
  ];

  return (
    <div className="space-y-2 sm:space-y-3">
      <div className="grid gap-2 grid-cols-2 lg:grid-cols-5">
        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Total Geral</p>
              <p className="text-lg sm:text-xl font-bold text-primary mt-0.5 truncate">
                €{stats.totalGeral.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-primary/10 flex-shrink-0">
              <Wallet className="text-primary" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Receitas Mês</p>
              <p className="text-lg sm:text-xl font-bold text-green-600 mt-0.5 truncate">
                €{stats.receitasMes.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-green-50 flex-shrink-0">
              <TrendUp className="text-green-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Mensalidades Vencidas</p>
              <p className="text-lg sm:text-xl font-bold text-red-600 mt-0.5 truncate">
                €{stats.valorMensalidadesVencidas.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-red-50 flex-shrink-0">
              <WarningCircle className="text-red-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Pendentes</p>
              <p className="text-lg sm:text-xl font-bold text-orange-600 mt-0.5 truncate">
                €{stats.valorPendentes.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-orange-50 flex-shrink-0">
              <Receipt className="text-orange-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>

        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Despesas Mês</p>
              <p className="text-lg sm:text-xl font-bold text-red-600 mt-0.5 truncate">
                €{stats.despesasMes.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-red-50 flex-shrink-0">
              <TrendDown className="text-red-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>
      </div>

      <div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2">
        <Card className="p-2 sm:p-2.5">
          <div className="flex items-center justify-between mb-1.5">
            <h3 className="font-semibold text-xs sm:text-sm">Saldo Atual</h3>
            <Wallet size={16} className="text-primary" />
          </div>
          <div className="space-y-1.5">
            <div className="flex items-center justify-between p-1.5 bg-muted/50 rounded-lg">
              <span className="text-xs font-medium">Saldo Total</span>
              <span
                className={`text-base sm:text-lg font-bold ${
                  stats.saldoTotal >= 0 ? 'text-green-600' : 'text-red-600'
                }`}
              >
                €{stats.saldoTotal.toFixed(2)}
              </span>
            </div>
            <div className="flex items-center justify-between p-1.5 bg-muted/50 rounded-lg">
              <span className="text-xs font-medium">Saldo do Mês</span>
              <span
                className={`text-base sm:text-lg font-bold ${
                  stats.saldoMes >= 0 ? 'text-green-600' : 'text-red-600'
                }`}
              >
                €{stats.saldoMes.toFixed(2)}
              </span>
            </div>
          </div>
        </Card>

        <Card className="p-4 text-xs text-muted-foreground">
          {showCharts ? 'A carregar visualizações financeiras...' : 'A preparar visualizações financeiras...'}
        </Card>
      </div>

      {showCharts ? (
        <Suspense fallback={<div className="grid gap-2 sm:gap-3 grid-cols-1 lg:grid-cols-2"><Card className="p-4 text-xs text-muted-foreground">A carregar gráficos...</Card></div>}>
          <DashboardCharts
            tiposFaturaData={tiposFaturaData}
            monthlyData={monthlyData}
            centrosCustoData={centrosCustoData}
            colors={COLORS}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
