import { User, EventoPresenca, Event, ResultadoProva } from '@/lib/types';
import { useKV } from '@/hooks/useKV';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { useMemo } from 'react';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { TrendingUp, TrendingDown, Calendar, Trophy, Activity, DollarSign } from 'lucide-react';

interface DashboardTabProps {
  user: User;
  faturas?: any[];
}

export function DashboardTab({ user, faturas = [] }: DashboardTabProps) {
  const [presencas] = useKV<EventoPresenca[]>('club-presencas', []);
  const [events] = useKV<Event[]>('club-events', []);
  const [resultadosProvas] = useKV<ResultadoProva[]>('club-resultados-provas', []);
  const currentAccountSummary = user.current_account_summary || {};

  const toNumber = (value: unknown) => {
    if (typeof value === 'number') return value;
    if (typeof value === 'string') {
      const parsed = parseFloat(value);
      return Number.isNaN(parsed) ? 0 : parsed;
    }
    return 0;
  };

  // FINANCEIRO
  const getStartOfToday = () => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today;
  };

  const isFutureInvoice = (fatura: any) => new Date(fatura.data_fatura) > getStartOfToday();

  const userFaturas = useMemo(() => {
    return (faturas || [])
      .filter((f) => f.user_id === user.id && !isFutureInvoice(f))
      .sort((a, b) => new Date(b.data_emissao).getTime() - new Date(a.data_emissao).getTime());
  }, [faturas, user.id]);

  const contaCorrente = useMemo(() => {
    return toNumber(currentAccountSummary.net_debt ?? user.conta_corrente ?? 0);
  }, [currentAccountSummary.net_debt, user.conta_corrente]);

  const creditoDisponivel = useMemo(() => {
    return toNumber(currentAccountSummary.available_credit ?? user.credito_disponivel ?? 0);
  }, [currentAccountSummary.available_credit, user.credito_disponivel]);

  const mensalidadesAbertas = useMemo(() => {
    return toNumber(currentAccountSummary.monthly_fees_open_amount ?? 0);
  }, [currentAccountSummary.monthly_fees_open_amount]);

  const movimentosAbertos = useMemo(() => {
    return toNumber(currentAccountSummary.revenue_movements_open_amount ?? 0);
  }, [currentAccountSummary.revenue_movements_open_amount]);

  const valorPago = useMemo(() => {
    const valorPagoFaturas = userFaturas.reduce((sum, fatura) => sum + toNumber(fatura.valor_pago ?? (fatura.estado_pagamento === 'pago' ? fatura.valor_total : 0)), 0);

    return valorPagoFaturas;
  }, [userFaturas]);

  const dividaVencida = useMemo(() => {
    return toNumber(currentAccountSummary.overdue_debt ?? user.divida_vencida ?? 0);
  }, [currentAccountSummary.overdue_debt, user.divida_vencida]);

  // Ultimas faturas para o grafico
  const ultimasFaturas = useMemo(() => {
    return userFaturas.slice(0, 6).reverse();
  }, [userFaturas]);

  // PRESENCAS
  const atletaPresencas = useMemo(() => {
    return (presencas || [])
      .filter(p => p.user_id === user.id)
      .map(p => {
        const evento = (events || []).find(e => e.id === p.evento_id);
        return { ...p, evento };
      })
      .filter(p => p.evento)
      .sort((a, b) => new Date(b.evento!.data_inicio).getTime() - new Date(a.evento!.data_inicio).getTime());
  }, [presencas, events, user.id]);

  const presencasStats = useMemo(() => {
    const total = atletaPresencas.length;
    const presentes = atletaPresencas.filter(p => p.estado === 'presente').length;
    const ausentes = atletaPresencas.filter(p => p.estado === 'ausente').length;
    const justificados = atletaPresencas.filter(p => p.estado === 'justificado').length;
    const taxaPresenca = total > 0 ? Math.round((presentes / total) * 100) : 0;

    return { total, presentes, ausentes, justificados, taxaPresenca };
  }, [atletaPresencas]);

  // RESULTADOS
  const atletaResultados = useMemo(() => {
    return (resultadosProvas || [])
      .filter(r => r.atleta_id === user.id)
      .sort((a, b) => new Date(b.data).getTime() - new Date(a.data).getTime());
  }, [resultadosProvas, user.id]);

  // PROXIMOS EVENTOS
  const proximosEventos = useMemo(() => {
    const hoje = new Date();
    return (events || [])
      .filter(e => new Date(e.data_inicio) >= hoje)
      .sort((a, b) => new Date(a.data_inicio).getTime() - new Date(b.data_inicio).getTime())
      .slice(0, 3);
  }, [events]);

  // ESTATISTICAS DO PERIODO (ultimos 30 dias)
  const estatisticasPeriodo = useMemo(() => {
    const hoje = new Date();
    const ha30Dias = new Date(hoje.getTime() - 30 * 24 * 60 * 60 * 1000);

    const presencasPeriodo = atletaPresencas.filter(p =>
      p.evento && new Date(p.evento.data_inicio) >= ha30Dias
    );

    const treinosPeriodo = presencasPeriodo.filter(p =>
      p.evento?.tipo === 'treino'
    ).length;

    const competicoesPeriodo = presencasPeriodo.filter(p =>
      p.evento?.tipo === 'prova'
    ).length;

    const presentesPeriodo = presencasPeriodo.filter(p => p.estado === 'presente').length;
    const taxaPresencaPeriodo = presencasPeriodo.length > 0
      ? Math.round((presentesPeriodo / presencasPeriodo.length) * 100)
      : 0;

    const resultadosPeriodo = atletaResultados.filter(r =>
      new Date(r.data) >= ha30Dias
    ).length;

    return {
      treinos: treinosPeriodo,
      competicoes: competicoesPeriodo,
      taxaPresenca: taxaPresencaPeriodo,
      resultados: resultadosPeriodo,
    };
  }, [atletaPresencas, atletaResultados]);

  return (
    <div className="space-y-1">
      <div className="grid grid-cols-1 md:grid-cols-4 gap-1">
        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <DollarSign size={14} />
              Conta Corrente
            </div>
            <div className={`text-lg font-bold leading-none ${contaCorrente > 0 ? 'text-red-600' : 'text-green-600'}`}>
              {contaCorrente.toFixed(2)}€
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <TrendingDown size={14} />
              Mensalidades
            </div>
            <div className="text-lg font-bold text-amber-600 leading-none">
              {mensalidadesAbertas.toFixed(2)}€
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <Activity size={14} />
              Movimentos
            </div>
            <div className="text-lg font-bold leading-none">
              {movimentosAbertos.toFixed(2)}€
            </div>
          </div>
        </Card>

        <Card className="p-0">
          <div className="flex flex-col items-center justify-center gap-2 px-2 py-1">
            <div className="text-xs text-muted-foreground flex items-center justify-center gap-1 leading-none">
              <TrendingUp size={14} />
              Valor Pago
            </div>
            <div className="text-lg font-bold leading-none">
              {valorPago.toFixed(2)}€
            </div>
          </div>
        </Card>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-1">
        <Card className="p-2">
          <div className="flex items-center gap-1 mb-1">
            <TrendingUp size={14} />
            <h3 className="text-sm font-semibold">Resumo Financeiro</h3>
          </div>
          <div>
            <div className="space-y-1">
              <div className="grid grid-cols-2 gap-1">
                <div>
                  <div className="text-xs text-muted-foreground">Crédito disponível</div>
                  <div className="text-base font-bold text-green-600">{creditoDisponivel.toFixed(2)}€</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Conta Corrente vencida</div>
                  <div className="text-base font-bold text-red-600">{dividaVencida.toFixed(2)}€</div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-1">
                <div>
                  <div className="text-xs text-muted-foreground">Mensalidades em aberto</div>
                  <div className="text-base font-bold text-slate-900">{mensalidadesAbertas.toFixed(2)}€</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Movimentos em aberto</div>
                  <div className="text-base font-bold text-slate-900">{movimentosAbertos.toFixed(2)}€</div>
                </div>
              </div>

              <div className="pt-1 border-t mt-1">
                <h4 className="text-xs font-medium mb-1">Últimas Faturas</h4>
                <div className="space-y-0.5">
                  {ultimasFaturas.slice(0, 3).map((fatura, i) => (
                    <div key={i} className="flex items-center justify-between text-xs">
                      <div className="flex items-center gap-2">
                        <span className="text-muted-foreground">
                          {format(new Date(fatura.data_emissao), 'MMM yy', { locale: pt })}
                        </span>
                        <Badge
                          variant="outline"
                          className={`text-xs px-1 py-0 ${
                            fatura.estado_pagamento === 'pago'
                              ? 'bg-green-50 text-green-700 border-green-200'
                              : 'bg-amber-50 text-amber-700 border-amber-200'
                          }`}
                        >
                          {fatura.estado_pagamento === 'pago' ? 'PAGO' : 'pendente'}
                        </Badge>
                      </div>
                      <span className="font-medium">{toNumber(fatura.estado_pagamento === 'pago' ? fatura.valor_total : (fatura.valor_em_aberto ?? fatura.valor_total)).toFixed(2)}€</span>
                    </div>
                  ))}
                  {ultimasFaturas.length === 0 && (
                    <p className="text-xs text-muted-foreground text-center py-2">
                      Sem faturas recentes
                    </p>
                  )}
                </div>
              </div>

              <div className="pt-1 border-t mt-1">
                <h4 className="text-xs font-medium mb-1">Evolução Últimos 6 Meses</h4>
                <div className="flex items-end justify-between h-14 gap-0.5">
                  {ultimasFaturas.length > 0 ? (
                    ultimasFaturas.map((fatura, i) => {
                      const valor = toNumber(fatura.valor_total);
                      const maxValor = Math.max(...ultimasFaturas.map(f => toNumber(f.valor_total)), 1);
                      const altura = (valor / maxValor) * 100;

                      return (
                        <div key={i} className="flex-1 flex flex-col items-center gap-1">
                          <div className="w-full bg-primary/20 rounded-t relative" style={{ height: `${altura}%` }}>
                            <div className="absolute inset-0 bg-primary rounded-t"></div>
                          </div>
                          <span className="text-[9px] text-muted-foreground leading-none">
                            {format(new Date(fatura.data_emissao), 'MMM', { locale: pt })}
                          </span>
                        </div>
                      );
                    })
                  ) : (
                    <div className="flex-1 flex items-center justify-center">
                      <p className="text-xs text-muted-foreground">Sem dados</p>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </Card>

        <Card className="p-2">
          <div className="flex items-center gap-1 mb-1">
            <Calendar size={14} />
            <h3 className="text-sm font-semibold">Resumo Desportivo</h3>
          </div>
          <div>
            <div className="space-y-1">
              <div>
                <h4 className="text-xs font-medium mb-1">Próximos Eventos</h4>
                <div className="space-y-0.5">
                  {proximosEventos.length > 0 ? (
                    proximosEventos.map((evento) => (
                      <div key={evento.id} className="flex items-start justify-between text-xs border-l-2 border-primary pl-2 py-1">
                        <div>
                          <div className="font-medium">{evento.titulo}</div>
                          <div className="text-muted-foreground">
                            {format(new Date(evento.data_inicio), "dd 'de' MMMM", { locale: pt })}
                          </div>
                        </div>
                        <Badge variant="outline" className="text-xs">
                          {evento.tipo}
                        </Badge>
                      </div>
                    ))
                  ) : (
                    <p className="text-xs text-muted-foreground text-center py-2">
                      Sem eventos agendados
                    </p>
                  )}
                </div>
              </div>

              <div className="pt-1 border-t mt-1">
                <h4 className="text-xs font-medium mb-1">Últimos Resultados</h4>
                <div className="space-y-0.5">
                  {atletaResultados.slice(0, 3).map((resultado) => (
                    <div key={resultado.id} className="flex items-center justify-between text-xs bg-muted/50 rounded p-2">
                      <div className="flex-1">
                        <div className="font-medium">{resultado.prova}</div>
                        <div className="text-muted-foreground">
                          {resultado.evento_nome || '-'}  {format(new Date(resultado.data), 'dd/MM/yy', { locale: pt })}
                        </div>
                      </div>
                      <div className="text-right">
                        <div className="flex items-center gap-1">
                          <Trophy size={12} className="text-primary" />
                          <span className="font-mono font-semibold text-sm">{resultado.tempo_final}</span>
                        </div>
                        <div className="text-[10px] text-muted-foreground">{resultado.piscina === 'piscina_25m' ? '25m' : resultado.piscina === 'piscina_50m' ? '50m' : 'Abertas'}</div>
                      </div>
                    </div>
                  ))}
                  {atletaResultados.length === 0 && (
                    <p className="text-xs text-muted-foreground text-center py-2">
                      Sem resultados registados
                    </p>
                  )}
                </div>
              </div>
            </div>
          </div>
        </Card>
      </div>

      <Card className="p-2">
        <h3 className="text-sm font-semibold mb-1">Estatísticas do Período</h3>
        <div>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-1">
            <div className="text-center p-2 bg-blue-50 rounded border border-blue-100">
              <div className="text-xs text-blue-600 font-medium">Treinos</div>
              <div className="text-lg font-bold text-blue-700">{estatisticasPeriodo.treinos}</div>
            </div>

            <div className="text-center p-2 bg-purple-50 rounded border border-purple-100">
              <div className="text-xs text-purple-600 font-medium">Competições</div>
              <div className="text-lg font-bold text-purple-700">{estatisticasPeriodo.competicoes}</div>
            </div>

            <div className="text-center p-2 bg-green-50 rounded border border-green-100">
              <div className="text-xs text-green-600 font-medium">Taxa Presença</div>
              <div className="text-lg font-bold text-green-700">{estatisticasPeriodo.taxaPresenca}%</div>
            </div>

            <div className="text-center p-2 bg-amber-50 rounded border border-amber-100">
              <div className="text-xs text-amber-600 font-medium">Resultados</div>
              <div className="text-lg font-bold text-amber-700">{estatisticasPeriodo.resultados}</div>
            </div>
          </div>
        </div>
      </Card>
    </div>
  );
}
