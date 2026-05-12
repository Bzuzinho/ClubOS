import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { ChartBar, Users, CurrencyCircleDollar, Funnel, ArrowsClockwise, CalendarBlank } from '@phosphor-icons/react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { toast } from 'sonner';
import { CentroCusto, User, AgeGroup, FinanceReportAgeGroupItem, FinanceReportCostCenterItem, FinanceReportResponse } from './types';

type TipoRelatorio = 'periodo' | 'escalao' | 'centro-custo' | 'atleta';

interface RelatoriosTabProps {
  centrosCusto: CentroCusto[];
  users: User[];
  ageGroups: AgeGroup[];
}

type ReportFilters = {
  data_inicio: string;
  data_fim: string;
  centro_custo_id: string;
  user_id: string;
  tipo: string;
  origem_modulo: string;
  origem_tipo: string;
};

const buildDefaultFilters = (): ReportFilters => {
  const today = new Date();
  const yearStart = new Date(today.getFullYear(), 0, 1);

  return {
    data_inicio: yearStart.toISOString().slice(0, 10),
    data_fim: today.toISOString().slice(0, 10),
    centro_custo_id: 'all',
    user_id: 'all',
    tipo: 'all',
    origem_modulo: '',
    origem_tipo: '',
  };
};

function formatCurrency(value?: number | null) {
  return new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
  }).format(value ?? 0);
}

function parseJsonResponse(response: Response) {
  const contentType = response.headers.get('content-type') || '';

  if (contentType.includes('application/json')) {
    return response.json();
  }

  return response.text();
}

export function RelatoriosTab({ centrosCusto, users, ageGroups }: RelatoriosTabProps) {
  const [tipoRelatorio, setTipoRelatorio] = useState<TipoRelatorio>('escalao');
  const [filters, setFilters] = useState<ReportFilters>(buildDefaultFilters);
  const [escalaoFilter, setEscalaoFilter] = useState<string>('all');
  const [reportData, setReportData] = useState<FinanceReportResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const escaloes = useMemo(() => {
    return (ageGroups || []).map((ageGroup) => ({ id: ageGroup.id, nome: ageGroup.nome }));
  }, [ageGroups]);

  const loadReportData = async (nextFilters?: ReportFilters) => {
    const activeFilters = nextFilters ?? filters;
    setLoading(true);
    setErrorMessage(null);

    const params = new URLSearchParams();
    params.set('data_inicio', activeFilters.data_inicio);
    params.set('data_fim', activeFilters.data_fim);

    if (activeFilters.centro_custo_id !== 'all') params.set('centro_custo_id', activeFilters.centro_custo_id);
    if (activeFilters.user_id !== 'all') params.set('user_id', activeFilters.user_id);
    if (activeFilters.tipo !== 'all') params.set('tipo', activeFilters.tipo);
    if (activeFilters.origem_modulo.trim()) params.set('origem_modulo', activeFilters.origem_modulo.trim());
    if (activeFilters.origem_tipo.trim()) params.set('origem_tipo', activeFilters.origem_tipo.trim());

    try {
      const response = await fetch(`${route('relatorios-financeiros.index')}?${params.toString()}`, {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      const payload = await parseJsonResponse(response);

      if (!response.ok || typeof payload === 'string') {
        throw new Error(typeof payload === 'string' ? payload : 'Nao foi possivel carregar os relatórios.');
      }

      setReportData(payload as FinanceReportResponse);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erro inesperado ao carregar os relatórios.';
      setErrorMessage(message);
      toast.error(message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void loadReportData(buildDefaultFilters());
  }, []);

  const relatorioEscalao = useMemo(() => {
    const items = reportData?.reports.age_groups.items ?? [];

    if (escalaoFilter === 'all') {
      return items;
    }

    return items.filter((item) => item.age_group_id === escalaoFilter);
  }, [escalaoFilter, reportData]);

  const chartData = useMemo(() => {
    if (!reportData) {
      return [];
    }

    if (tipoRelatorio === 'periodo') {
      return reportData.reports.period.items.map((item) => ({
        name: item.period_label,
        Receitas: item.receitas,
        Despesas: item.despesas,
      }));
    }

    if (tipoRelatorio === 'escalao') {
      return relatorioEscalao.map((item) => ({
        name: item.age_group,
        Receitas: item.receitas,
        Pago: item.total_pago,
      }));
    }

    if (tipoRelatorio === 'centro-custo') {
      return reportData.reports.cost_centers.items.slice(0, 10).map((item: FinanceReportCostCenterItem) => ({
        name: item.nome.length > 15 ? `${item.nome.substring(0, 15)}...` : item.nome,
        Receitas: item.receitas,
        Despesas: item.despesas,
      }));
    }

    return [];
  }, [reportData, relatorioEscalao, tipoRelatorio]);

  const handleApplyFilters = async () => {
    await loadReportData(filters);
  };

  const handleResetFilters = async () => {
    const defaultFilters = buildDefaultFilters();
    setFilters(defaultFilters);
    setEscalaoFilter('all');
    await loadReportData(defaultFilters);
  };

  const ageGroupTotals = useMemo(() => {
    if (escalaoFilter === 'all' || !reportData) {
      return reportData?.reports.age_groups.totals ?? null;
    }

    return relatorioEscalao.reduce((totals, item: FinanceReportAgeGroupItem) => ({
      numero_atletas: totals.numero_atletas + item.numero_atletas,
      receitas: totals.receitas + item.receitas,
      total_faturado: totals.total_faturado + item.total_faturado,
      total_pago: totals.total_pago + item.total_pago,
      total_pendente: totals.total_pendente + item.total_pendente,
      despesas: totals.despesas + item.despesas,
      peso_financeiro: totals.peso_financeiro + item.peso_financeiro,
    }), {
      numero_atletas: 0,
      receitas: 0,
      total_faturado: 0,
      total_pago: 0,
      total_pendente: 0,
      despesas: 0,
      peso_financeiro: 0,
    });
  }, [escalaoFilter, relatorioEscalao, reportData]);

  return (
    <div className="space-y-3">
      <Card className="p-2">
        <div className="flex items-center gap-1.5 mb-1.5">
          <Funnel size={14} className="text-primary" />
          <h3 className="font-semibold text-xs">Filtros de Relatorio</h3>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-4 gap-2">
          <div className="space-y-0.5">
            <Label className="text-xs">Tipo de Relatorio</Label>
            <Select value={tipoRelatorio} onValueChange={(v) => setTipoRelatorio(v as TipoRelatorio)}>
              <SelectTrigger className="h-7 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="periodo">Receitas/Despesas por Periodo</SelectItem>
                <SelectItem value="escalao">Rendimento por Escalao</SelectItem>
                <SelectItem value="centro-custo">Receitas/Despesas por Centro de Custo</SelectItem>
                <SelectItem value="atleta">Peso Financeiro por Atleta</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Data inicio</Label>
            <Input className="h-7 text-xs" type="date" value={filters.data_inicio} onChange={(event) => setFilters((current) => ({ ...current, data_inicio: event.target.value }))} />
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Data fim</Label>
            <Input className="h-7 text-xs" type="date" value={filters.data_fim} onChange={(event) => setFilters((current) => ({ ...current, data_fim: event.target.value }))} />
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Tipo financeiro</Label>
            <Select value={filters.tipo} onValueChange={(value) => setFilters((current) => ({ ...current, tipo: value }))}>
              <SelectTrigger className="h-7 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Receitas e despesas</SelectItem>
                <SelectItem value="receita">Receitas</SelectItem>
                <SelectItem value="despesa">Despesas</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Centro de custo</Label>
            <Select value={filters.centro_custo_id} onValueChange={(value) => setFilters((current) => ({ ...current, centro_custo_id: value }))}>
              <SelectTrigger className="h-7 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos os centros</SelectItem>
                {(centrosCusto || []).filter((cc) => cc.ativo).map((cc) => (
                  <SelectItem key={cc.id} value={cc.id}>{cc.nome}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Utilizador</Label>
            <Select value={filters.user_id} onValueChange={(value) => setFilters((current) => ({ ...current, user_id: value }))}>
              <SelectTrigger className="h-7 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos os utilizadores</SelectItem>
                {(users || []).map((user) => (
                  <SelectItem key={user.id} value={user.id}>{user.nome_completo}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Origem modulo</Label>
            <Input className="h-7 text-xs" value={filters.origem_modulo} onChange={(event) => setFilters((current) => ({ ...current, origem_modulo: event.target.value }))} placeholder="Ex.: financeiro" />
          </div>

          <div className="space-y-0.5">
            <Label className="text-xs">Origem tipo</Label>
            <Input className="h-7 text-xs" value={filters.origem_tipo} onChange={(event) => setFilters((current) => ({ ...current, origem_tipo: event.target.value }))} placeholder="Ex.: movement" />
          </div>

          {tipoRelatorio === 'escalao' && (
            <div className="space-y-0.5">
              <Label className="text-xs">Filtrar Escalao</Label>
              <Select value={escalaoFilter} onValueChange={setEscalaoFilter}>
                <SelectTrigger className="h-7 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos os Escaloes</SelectItem>
                  {escaloes.map((esc) => (
                    <SelectItem key={esc.id} value={esc.id}>
                      {esc.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>

        <div className="mt-2 flex flex-wrap gap-2">
          <Button type="button" size="sm" onClick={() => void handleApplyFilters()} disabled={loading}>
            <CalendarBlank size={14} className="mr-1.5" />
            Aplicar filtros
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => void handleResetFilters()} disabled={loading}>
            Limpar
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => void loadReportData(filters)} disabled={loading}>
            <ArrowsClockwise size={14} className="mr-1.5" />
            Atualizar
          </Button>
        </div>
      </Card>

      {errorMessage ? (
        <Card className="p-4 text-sm text-rose-700 border-rose-200 bg-rose-50">
          {errorMessage}
        </Card>
      ) : null}

      {loading ? (
        <Card className="p-4 text-sm text-muted-foreground">A carregar relatórios canónicos...</Card>
      ) : null}

      {!loading && (tipoRelatorio === 'periodo' || tipoRelatorio === 'escalao' || tipoRelatorio === 'centro-custo') && chartData.length > 0 && (
        <Card className="p-3">
          <h3 className="font-semibold text-sm mb-3 flex items-center gap-2">
            <ChartBar size={18} className="text-primary" />
            Visualizacao Grafica
          </h3>
          <div className="h-[220px]">
            <ResponsiveContainer width="100%" height="100%" minWidth={0} minHeight={220}>
              <BarChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip formatter={(value) => `€${Number(value).toFixed(2)}`} />
                <Legend wrapperStyle={{ fontSize: '11px' }} />
                {tipoRelatorio === 'periodo' && (
                  <>
                    <Bar dataKey="Receitas" fill="oklch(0.55 0.15 150)" />
                    <Bar dataKey="Despesas" fill="oklch(0.55 0.22 25)" />
                  </>
                )}
                {tipoRelatorio === 'escalao' && (
                  <>
                    <Bar dataKey="Receitas" fill="oklch(0.55 0.15 150)" />
                    <Bar dataKey="Pago" fill="oklch(0.45 0.15 250)" />
                  </>
                )}
                {tipoRelatorio === 'centro-custo' && (
                  <>
                    <Bar dataKey="Receitas" fill="oklch(0.55 0.15 150)" />
                    <Bar dataKey="Despesas" fill="oklch(0.55 0.22 25)" />
                  </>
                )}
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      )}

      {!loading && tipoRelatorio === 'periodo' && reportData && (
        <Card className="p-4">
          <h3 className="text-base font-semibold mb-4 flex items-center gap-2">
            <CalendarBlank size={20} className="text-primary" />
            Relatorio: Receitas/Despesas por Periodo
          </h3>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Periodo</TableHead>
                <TableHead className="text-right">Receitas</TableHead>
                <TableHead className="text-right">Despesas</TableHead>
                <TableHead className="text-right">Saldo</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {!reportData.reports.period.available || reportData.reports.period.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={4} className="text-center text-muted-foreground py-8">
                    {reportData.reports.period.empty_message}
                  </TableCell>
                </TableRow>
              ) : (
                reportData.reports.period.items.map((item) => (
                  <TableRow key={item.period_key}>
                    <TableCell className="font-medium">{item.period_label}</TableCell>
                    <TableCell className="text-right text-green-600">{formatCurrency(item.receitas)}</TableCell>
                    <TableCell className="text-right text-red-600">{formatCurrency(item.despesas)}</TableCell>
                    <TableCell className={`text-right font-semibold ${item.saldo >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                      {formatCurrency(item.saldo)}
                    </TableCell>
                  </TableRow>
                ))
              )}
              {reportData.reports.period.items.length > 0 ? (
                <TableRow className="font-bold bg-muted/50">
                  <TableCell>TOTAL</TableCell>
                  <TableCell className="text-right text-green-600">{formatCurrency(reportData.reports.period.totals.receitas)}</TableCell>
                  <TableCell className="text-right text-red-600">{formatCurrency(reportData.reports.period.totals.despesas)}</TableCell>
                  <TableCell className={`text-right ${reportData.reports.period.totals.saldo >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                    {formatCurrency(reportData.reports.period.totals.saldo)}
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </Card>
      )}

      {!loading && tipoRelatorio === 'escalao' && reportData && (
        <Card className="p-4">
          <h3 className="text-base font-semibold mb-4 flex items-center gap-2">
            <Users size={20} className="text-primary" />
            Relatorio: Rendimento por Escalao
          </h3>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Escalao</TableHead>
                <TableHead className="text-right">Nº Atletas</TableHead>
                <TableHead className="text-right">Receitas</TableHead>
                <TableHead className="text-right">Total Faturado</TableHead>
                <TableHead className="text-right">Total Pago</TableHead>
                <TableHead className="text-right">Pendente</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {!reportData.reports.age_groups.available || relatorioEscalao.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                    {reportData.reports.age_groups.empty_message}
                  </TableCell>
                </TableRow>
              ) : (
                relatorioEscalao.map((item) => (
                  <TableRow key={item.age_group_id}>
                    <TableCell className="font-medium">{item.age_group}</TableCell>
                    <TableCell className="text-right">{item.numero_atletas}</TableCell>
                    <TableCell className="text-right font-semibold text-green-600">
                      {formatCurrency(item.receitas)}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(item.total_faturado)}</TableCell>
                    <TableCell className="text-right text-green-600">{formatCurrency(item.total_pago)}</TableCell>
                    <TableCell className="text-right text-orange-600">{formatCurrency(item.total_pendente)}</TableCell>
                  </TableRow>
                ))
              )}
              {relatorioEscalao.length > 0 && ageGroupTotals && (
                <TableRow className="font-bold bg-muted/50">
                  <TableCell>TOTAL</TableCell>
                  <TableCell className="text-right">{ageGroupTotals.numero_atletas}</TableCell>
                  <TableCell className="text-right text-green-600">
                    {formatCurrency(ageGroupTotals.receitas)}
                  </TableCell>
                  <TableCell className="text-right">{formatCurrency(ageGroupTotals.total_faturado)}</TableCell>
                  <TableCell className="text-right text-green-600">
                    {formatCurrency(ageGroupTotals.total_pago)}
                  </TableCell>
                  <TableCell className="text-right text-orange-600">
                    {formatCurrency(ageGroupTotals.total_pendente)}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </Card>
      )}

      {!loading && tipoRelatorio === 'centro-custo' && reportData && (
        <Card className="p-4">
          <h3 className="text-base font-semibold mb-4 flex items-center gap-2">
            <CurrencyCircleDollar size={20} className="text-primary" />
            Relatorio: Receitas/Despesas por Centro de Custo
          </h3>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Centro de Custo</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead className="text-right">Receitas</TableHead>
                <TableHead className="text-right">Despesas</TableHead>
                <TableHead className="text-right">Saldo</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {!reportData.reports.cost_centers.available || reportData.reports.cost_centers.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} className="text-center text-muted-foreground py-8">
                    {reportData.reports.cost_centers.empty_message}
                  </TableCell>
                </TableRow>
              ) : (
                reportData.reports.cost_centers.items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.nome}</TableCell>
                    <TableCell className="capitalize">{item.tipo}</TableCell>
                    <TableCell className="text-right font-semibold text-green-600">
                      {formatCurrency(item.receitas)}
                    </TableCell>
                    <TableCell className="text-right font-semibold text-red-600">
                      {formatCurrency(item.despesas)}
                    </TableCell>
                    <TableCell
                      className={`text-right font-bold ${item.saldo >= 0 ? 'text-green-600' : 'text-red-600'}`}
                    >
                      {formatCurrency(item.saldo)}
                    </TableCell>
                  </TableRow>
                ))
              )}
              {reportData.reports.cost_centers.items.length > 0 && (
                <TableRow className="font-bold bg-muted/50">
                  <TableCell colSpan={2}>TOTAL</TableCell>
                  <TableCell className="text-right text-green-600">
                    {formatCurrency(reportData.reports.cost_centers.totals.receitas)}
                  </TableCell>
                  <TableCell className="text-right text-red-600">
                    {formatCurrency(reportData.reports.cost_centers.totals.despesas)}
                  </TableCell>
                  <TableCell
                    className={`text-right ${reportData.reports.cost_centers.totals.saldo >= 0 ? 'text-green-600' : 'text-red-600'}`}
                  >
                    {formatCurrency(reportData.reports.cost_centers.totals.saldo)}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </Card>
      )}

      {!loading && tipoRelatorio === 'atleta' && reportData && (
        <Card className="p-4">
          <h3 className="text-base font-semibold mb-3 flex items-center gap-2">
            <Users size={20} className="text-primary" />
            Relatorio: Peso Financeiro por Atleta
          </h3>
          <p className="text-xs text-muted-foreground mb-3">
            Valor Pago - Valor Gasto = Peso Financeiro. A tab apenas apresenta leitura canónica do backend.
          </p>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nome</TableHead>
                <TableHead>Numero Socio</TableHead>
                <TableHead>Escalao</TableHead>
                <TableHead className="text-right">Valor Pago</TableHead>
                <TableHead className="text-right">Valor Gasto</TableHead>
                <TableHead className="text-right">Peso Financeiro</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {!reportData.reports.athletes.available || reportData.reports.athletes.items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                    {reportData.reports.athletes.empty_message}
                  </TableCell>
                </TableRow>
              ) : (
                reportData.reports.athletes.items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.nome}</TableCell>
                    <TableCell>{item.numero_socio}</TableCell>
                    <TableCell>{item.escalao}</TableCell>
                    <TableCell className="text-right text-green-600 font-semibold">
                      {formatCurrency(item.valor_pago)}
                    </TableCell>
                    <TableCell className="text-right text-red-600">{formatCurrency(item.valor_gasto)}</TableCell>
                    <TableCell
                      className={`text-right font-bold ${item.peso_financeiro >= 0 ? 'text-green-600' : 'text-red-600'}`}
                    >
                      {formatCurrency(item.peso_financeiro)}
                    </TableCell>
                  </TableRow>
                ))
              )}
              {reportData.reports.athletes.items.length > 0 ? (
                <TableRow className="font-bold bg-muted/50">
                  <TableCell colSpan={3}>TOTAL</TableCell>
                  <TableCell className="text-right text-green-600">{formatCurrency(reportData.reports.athletes.totals.valor_pago)}</TableCell>
                  <TableCell className="text-right text-red-600">{formatCurrency(reportData.reports.athletes.totals.valor_gasto)}</TableCell>
                  <TableCell className={`text-right ${reportData.reports.athletes.totals.peso_financeiro >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                    {formatCurrency(reportData.reports.athletes.totals.peso_financeiro)}
                  </TableCell>
                </TableRow>
              ) : null}
            </TableBody>
          </Table>
        </Card>
      )}
    </div>
  );
}
