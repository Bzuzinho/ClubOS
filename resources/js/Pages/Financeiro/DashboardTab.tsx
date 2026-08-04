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

  const normalizedDashboard = {
    totalGeral: Number(dashboardData?.total_geral ?? 0),
    receitasMes: Number(dashboardData?.receitas_mes ?? 0),
    despesasMes: Number(dashboardData?.despesas_mes ?? 0),
    mensalidadesVencidas: Number(dashboardData?.mensalidades_vencidas ?? 0),
    movimentosPendentes: Number(dashboardData?.movimentos_pendentes ?? 0),
    alerts: {
      paidWithoutInvoice: Number(dashboardData?.alerts?.paid_without_invoice ?? 0),
      paidWithoutReceipt: Number(dashboardData?.alerts?.paid_without_receipt ?? 0),
      missingPaymentProof: Number(dashboardData?.alerts?.missing_payment_proof ?? 0),
      overdueUnpaid: Number(dashboardData?.alerts?.overdue_unpaid ?? 0),
      amountMismatch: Number(dashboardData?.alerts?.amount_mismatch ?? 0),
      stockWithoutDocument: Number(dashboardData?.alerts?.stock_without_document ?? 0),
    },
    distribuicaoPorTipo: (dashboardData?.distribuicao_por_tipo ?? [])
      .map((row) => ({
        name: row.label ?? '-',
        value: Number(row.total ?? 0),
      }))
      .filter((row) => row.value > 0),
    evolucaoMensal: (dashboardData?.evolucao_mensal_ultimos_6_meses ?? []).map((row) => ({
      mes: row.mes ?? '-',
      receitas: Number(row.receitas ?? 0),
      despesas: Number(row.despesas ?? 0),
    })),
    centrosCusto: (dashboardData?.receitas_despesas_por_centro_custo ?? []).map((row) => ({
      nome: row.centro_custo_nome ?? row.centro_custo_id ?? 'Sem centro de custo',
      despesas: Number(row.despesas ?? 0),
      receitas: Number(row.receitas ?? 0),
    })),
  };

  const saldoMes = normalizedDashboard.receitasMes - normalizedDashboard.despesasMes;

  const COLORS = [
    'oklch(0.45 0.15 250)',
    'oklch(0.68 0.18 45)',
    'oklch(0.55 0.22 25)',
    'oklch(0.6 0.15 150)',
    'oklch(0.5 0.12 300)',
  ];

  const saldoCard = (
    <Card className="h-full p-2 sm:p-2.5">
      <div className="mb-1 flex items-center justify-between">
        <h3 className="text-xs font-semibold sm:text-sm">Saldo Atual</h3>
        <Wallet size={16} className="text-primary" />
      </div>
      <div className="space-y-1.5">
        <div className="flex items-center justify-between rounded-lg bg-muted/50 p-1.5">
          <span className="text-xs font-medium">Saldo Total</span>
          <span className={`text-base font-bold sm:text-lg ${normalizedDashboard.totalGeral >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            €{normalizedDashboard.totalGeral.toFixed(2)}
          </span>
        </div>
        <div className="flex items-center justify-between rounded-lg bg-muted/50 p-1.5">
          <span className="text-xs font-medium">Saldo do Mês</span>
          <span className={`text-base font-bold sm:text-lg ${saldoMes >= 0 ? 'text-green-600' : 'text-red-600'}`}>
            €{saldoMes.toFixed(2)}
          </span>
        </div>
      </div>
    </Card>
  );

  const alertsCard = (
    <Card className="h-full p-2 sm:p-2.5">
      <div className="mb-1 text-xs font-semibold sm:text-sm">Alertas documentais</div>
      <div className="grid gap-1 text-xs">
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Pagos sem fatura</span><span className="font-semibold">{normalizedDashboard.alerts.paidWithoutInvoice}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Pagos sem recibo</span><span className="font-semibold">{normalizedDashboard.alerts.paidWithoutReceipt}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Sem comprovativo</span><span className="font-semibold">{normalizedDashboard.alerts.missingPaymentProof}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Vencidas por pagar</span><span className="font-semibold">{normalizedDashboard.alerts.overdueUnpaid}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Valor divergente</span><span className="font-semibold">{normalizedDashboard.alerts.amountMismatch}</span></div>
        <div className="flex items-center justify-between rounded-md border px-2 py-1"><span>Stock sem documento</span><span className="font-semibold">{normalizedDashboard.alerts.stockWithoutDocument}</span></div>
      </div>
    </Card>
  );

  return (
    <div className="space-y-2 sm:space-y-3">
      <div className="grid gap-2 grid-cols-2 lg:grid-cols-5">
        <Card className="p-2 sm:p-3">
          <div className="flex items-start justify-between gap-1">
            <div className="min-w-0 flex-1">
              <p className="text-xs text-muted-foreground font-medium leading-tight">Total Geral</p>
              <p className="text-lg sm:text-xl font-bold text-primary mt-0.5 truncate">
                €{normalizedDashboard.totalGeral.toFixed(2)}
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
                €{normalizedDashboard.receitasMes.toFixed(2)}
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
                €{normalizedDashboard.mensalidadesVencidas.toFixed(2)}
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
                €{normalizedDashboard.movimentosPendentes.toFixed(2)}
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
                €{normalizedDashboard.despesasMes.toFixed(2)}
              </p>
            </div>
            <div className="p-1.5 rounded-lg bg-red-50 flex-shrink-0">
              <TrendDown className="text-red-600" size={16} weight="bold" />
            </div>
          </div>
        </Card>
      </div>

      {showCharts ? (
        <Suspense fallback={<div className="grid grid-cols-1 gap-2 md:grid-cols-3"><Card className="p-3 text-xs text-muted-foreground md:col-span-3">A carregar gráficos...</Card></div>}>
          <DashboardCharts
            tiposFaturaData={normalizedDashboard.distribuicaoPorTipo}
            monthlyData={normalizedDashboard.evolucaoMensal}
            centrosCustoData={normalizedDashboard.centrosCusto}
            colors={COLORS}
            summaryLeft={saldoCard}
            summaryRight={alertsCard}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
