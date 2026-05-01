import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { Card } from '@/Components/ui/card';
import { Fatura, LancamentoFinanceiro, CentroCusto, ExtratoBancario, Movimento } from './types';
import { TrendUp, TrendDown, Wallet, Receipt, WarningCircle } from '@phosphor-icons/react';

const DashboardCharts = lazy(() => import('./DashboardCharts'));

interface DashboardTabProps {
  faturas: Fatura[];
  lancamentos: LancamentoFinanceiro[];
  movimentos: Movimento[];
  extratos: ExtratoBancario[];
  centrosCusto: CentroCusto[];
}

export function DashboardTab({ faturas, lancamentos, movimentos, extratos, centrosCusto }: DashboardTabProps) {
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

  const toNumber = (value: unknown, fallback = 0) => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
      const parsed = Number(value);
      return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
  };
  const getStartOfToday = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today;
  };
  const isSameMonth = (dateValue?: string | null) => {
    if (!dateValue) return false;

    const date = new Date(dateValue);

    return !Number.isNaN(date.getTime()) &&
      date.getMonth() === new Date().getMonth() &&
      date.getFullYear() === new Date().getFullYear();
  };
  const isFutureInvoice = (fatura: Fatura) => new Date(fatura.data_fatura) > getStartOfToday();
  const faturasAtivas = (faturas || []).filter((f) => !isFutureInvoice(f));

  const stats = useMemo(() => {
    const today = getStartOfToday();

    const mensalidades = faturasAtivas.filter((f) => f.tipo === 'mensalidade');

    const mensalidadesVencidas = mensalidades.filter((f) => {
      if (f.estado_pagamento === 'pago' || f.estado_pagamento === 'cancelado') return false;

      const dataVencimento = new Date(f.data_vencimento);

      return !Number.isNaN(dataVencimento.getTime()) && dataVencimento < today;
    });

    const valorMensalidadesVencidas = mensalidadesVencidas.reduce(
      (sum, f) => sum + toNumber(f.valor_total),
      0
    );

    const valorPendentes = (movimentos || [])
      .filter((m) => m.estado_pagamento !== 'pago' && m.estado_pagamento !== 'cancelado')
      .reduce((sum, m) => sum + Math.abs(toNumber(m.valor_total)), 0);

    const faturasCobradasMes = faturasAtivas
      .filter((f) => {
        if (f.estado_pagamento !== 'pago') return false;
        return isSameMonth(f.data_emissao);
      })
      .reduce((sum, f) => sum + toNumber(f.valor_total), 0);

    const receitasMovimentosMes = (movimentos || [])
      .filter((m) => {
        if (m.classificacao !== 'receita' || m.estado_pagamento !== 'pago') return false;
        return isSameMonth(m.data_emissao);
      })
      .reduce((sum, m) => sum + toNumber(m.valor_total), 0);

    const receitasMes = receitasMovimentosMes + faturasCobradasMes;

    const despesasMes = (movimentos || [])
      .filter((m) => {
        if (m.classificacao !== 'despesa' || m.estado_pagamento !== 'pago') return false;
        return isSameMonth(m.data_emissao);
      })
      .reduce((sum, m) => sum + Math.abs(toNumber(m.valor_total)), 0);

    const receitasTotal = (lancamentos || [])
      .filter((l) => l.tipo === 'receita')
      .reduce((sum, l) => sum + toNumber(l.valor), 0);

    const despesasTotal = (lancamentos || [])
      .filter((l) => l.tipo === 'despesa')
      .reduce((sum, l) => sum + toNumber(l.valor), 0);

    const totalGeral = receitasMes - despesasMes;

    return {
      valorMensalidadesVencidas,
      valorPendentes,
      mensalidadesCobradas: faturasCobradasMes,
      receitasMes,
      despesasMes,
      receitasTotal,
      despesasTotal,
      totalGeral,
      saldoMes: receitasMes - despesasMes,
      saldoTotal: receitasTotal - despesasTotal,
    };
  }, [faturasAtivas, lancamentos, movimentos]);

  const centrosCustoData = useMemo(() => {
    const data = (centrosCusto || [])
      .filter((cc) => cc.ativo)
      .map((cc) => {
        const despesas = (movimentos || [])
          .filter((movimento) => movimento.classificacao === 'despesa' && movimento.centro_custo_id === cc.id)
          .reduce((sum, movimento) => sum + Math.abs(toNumber(movimento.valor_total)), 0);

        const receitasMensalidades = faturasAtivas
          .filter((fatura) => fatura.tipo === 'mensalidade' && fatura.centro_custo_id === cc.id)
          .reduce((sum, fatura) => sum + toNumber(fatura.valor_total), 0);

        const receitasMovimentos = (movimentos || [])
          .filter((movimento) => movimento.classificacao === 'receita' && movimento.centro_custo_id === cc.id)
          .reduce((sum, movimento) => sum + toNumber(movimento.valor_total), 0);

        const receitas = receitasMensalidades + receitasMovimentos;

        return {
          nome: cc.nome,
          despesas,
          receitas,
          saldo: receitas - despesas,
        };
      });

    return data.sort((a, b) => (b.receitas + b.despesas) - (a.receitas + a.despesas)).slice(0, 6);
  }, [centrosCusto, faturasAtivas, movimentos]);

  const monthlyData = useMemo(() => {
    const months: { mes: string; receitas: number; despesas: number }[] = [];
    const now = new Date();

    for (let i = 5; i >= 0; i--) {
      const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
      const month = date.getMonth();
      const year = date.getFullYear();

      const receitas = (lancamentos || [])
        .filter((l) => {
          const d = new Date(l.data);
          return l.tipo === 'receita' && d.getMonth() === month && d.getFullYear() === year;
        })
        .reduce((sum, l) => sum + toNumber(l.valor), 0);

      const despesas = (lancamentos || [])
        .filter((l) => {
          const d = new Date(l.data);
          return l.tipo === 'despesa' && d.getMonth() === month && d.getFullYear() === year;
        })
        .reduce((sum, l) => sum + toNumber(l.valor), 0);

      months.push({
        mes: date.toLocaleDateString('pt-PT', { month: 'short' }),
        receitas,
        despesas,
      });
    }

    return months;
  }, [lancamentos]);

  const tiposFaturaData = useMemo(() => {
    const tipos = ['mensalidade', 'inscricao', 'material', 'servico', 'outro'];
    return tipos
      .map((tipo) => ({
        name: tipo.charAt(0).toUpperCase() + tipo.slice(1),
        value: faturasAtivas.filter((f) => f.tipo === tipo).length,
      }))
      .filter((d) => d.value > 0);
  }, [faturasAtivas]);

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
