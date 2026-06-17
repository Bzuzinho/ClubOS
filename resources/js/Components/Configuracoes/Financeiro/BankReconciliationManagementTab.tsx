import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { toast } from 'sonner';
import { fetchFinanceiro, getFinanceiroFetchErrorMessage, getFinanceiroJsonHeaders } from '@/Pages/Financeiro/request';

type ReconciliationState = 'por_conciliar' | 'parcial' | 'conciliado';

interface AuditAllocationRow {
  tipo: 'invoice' | 'movement' | 'credit' | 'other';
  id: string;
  descricao: string;
  mes?: string | null;
  valor_alocado: number;
  estado?: string | null;
}

interface AuditRow {
  bank_statement_id: string;
  data_movimento?: string | null;
  descricao?: string | null;
  referencia?: string | null;
  valor: number;
  estado_conciliacao: ReconciliationState;
  valor_alocado: number;
  valor_por_alocar: number;
  reconciled_at?: string | null;
  reconciled_by_name?: string | null;
  metodo_conciliacao?: string | null;
  target_summary?: {
    nome_principal?: string | null;
    nomes?: string[];
    faturas_afetadas?: number;
    movimentos_afetados?: number;
    valor_credito_criado?: number;
  } | null;
  allocations?: AuditAllocationRow[];
  fiscal_status?: string | null;
  flags?: {
    tem_credito?: boolean;
    tem_desconciliacao?: boolean;
    tem_documento_fiscal_emitido?: boolean;
    bloqueado_para_desconciliar?: boolean;
  } | null;
  historico_desconciliacoes?: Array<{
    tipo?: string;
    payment_id?: string | null;
    payment_allocation_id?: string | null;
    cancelled_at?: string | null;
    cancelled_by_name?: string | null;
    motivo?: string | null;
  }>;
  erros_ou_bloqueios?: string[];
}

interface AuditSummary {
  total_linhas: number;
  total_conciliado: number;
  total_parcial: number;
  total_por_conciliar: number;
  total_alocado: number;
  total_por_alocar: number;
  total_credito_criado: number;
}

interface AliasRow {
  id: string;
  normalized_value?: string | null;
  original_value?: string | null;
  description?: string | null;
  target_type?: 'user' | 'family' | null;
  target_name?: string | null;
  confidence?: number | null;
  source?: string | null;
  usage_count?: number | null;
  last_used_at?: string | null;
  active: boolean;
  is_confirmed?: boolean;
}

interface SuggestionRow {
  id: string;
  bank_statement_id: string;
  score?: number;
  rejection_reason?: string | null;
  rejected_at?: string | null;
  metadata?: Record<string, unknown> | null;
  explanation?: string | null;
  matched_rules?: string[] | null;
  bank_statement?: {
    data_movimento?: string | null;
    descricao?: string | null;
    valor?: number | null;
  } | null;
  user?: {
    nome_completo?: string | null;
    numero_socio?: string | null;
  } | null;
  family?: {
    nome?: string | null;
  } | null;
  rejected_by?: {
    nome_completo?: string | null;
  } | null;
}

interface SuggestionApiResponse {
  data?: SuggestionRow[];
  current_page?: number;
  last_page?: number;
  per_page?: number;
  total?: number;
}

interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface AuditApiResponse {
  rows?: AuditRow[];
  data?: AuditRow[];
  summary?: Partial<AuditSummary>;
  meta?: Partial<PaginationMeta>;
  export?: {
    max_rows?: number;
    supports?: {
      csv?: boolean;
      xlsx?: boolean;
    };
  };
}

const formatDate = (value?: string | null) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  return date.toLocaleString('pt-PT');
};

const formatCurrency = (value?: number | null) => {
  if (typeof value !== 'number' || Number.isNaN(value)) return '-';
  return `EUR ${value.toFixed(2)}`;
};

export function BankReconciliationManagementTab({ canEdit }: { canEdit: boolean }) {
  const [activeSubtab, setActiveSubtab] = useState<'aliases' | 'rejeicoes' | 'auditoria'>('aliases');
  const [aliasRows, setAliasRows] = useState<AliasRow[]>([]);
  const [rejectedRows, setRejectedRows] = useState<SuggestionRow[]>([]);
  const [auditRows, setAuditRows] = useState<AuditRow[]>([]);
  const [aliasMeta, setAliasMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [rejectedMeta, setRejectedMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [auditMeta, setAuditMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [auditSummary, setAuditSummary] = useState<AuditSummary>({
    total_linhas: 0,
    total_conciliado: 0,
    total_parcial: 0,
    total_por_conciliar: 0,
    total_alocado: 0,
    total_por_alocar: 0,
    total_credito_criado: 0,
  });
  const [aliasesLoading, setAliasesLoading] = useState(false);
  const [rejectedLoading, setRejectedLoading] = useState(false);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditExporting, setAuditExporting] = useState<'csv' | 'xlsx' | 'summary_csv' | null>(null);
  const [auditExportCapabilities, setAuditExportCapabilities] = useState({
    supportsCsv: true,
    supportsXlsx: false,
    maxRows: 5000,
  });
  const [aliasActionId, setAliasActionId] = useState<string | null>(null);
  const [rejectionActionId, setRejectionActionId] = useState<string | null>(null);

  const [aliasSearch, setAliasSearch] = useState('');
  const [aliasStatusFilter, setAliasStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [aliasTargetFilter, setAliasTargetFilter] = useState<'all' | 'user' | 'family'>('all');
  const [aliasSourceFilter, setAliasSourceFilter] = useState('all');
  const [aliasPage, setAliasPage] = useState(1);
  const [aliasPerPage, setAliasPerPage] = useState(20);

  const [rejectedSearch, setRejectedSearch] = useState('');
  const [rejectedPage, setRejectedPage] = useState(1);
  const [rejectedPerPage, setRejectedPerPage] = useState(20);

  const [auditStateFilter, setAuditStateFilter] = useState<'todos' | 'por_conciliar' | 'parcial' | 'conciliado'>('todos');
  const [auditMethodFilter, setAuditMethodFilter] = useState('all');
  const [auditSearch, setAuditSearch] = useState('');
  const [auditDateFrom, setAuditDateFrom] = useState('');
  const [auditDateTo, setAuditDateTo] = useState('');
  const [auditHasCredit, setAuditHasCredit] = useState<'all' | 'with' | 'without'>('all');
  const [auditSortBy, setAuditSortBy] = useState<'data_movimento' | 'valor'>('data_movimento');
  const [auditSortDirection, setAuditSortDirection] = useState<'asc' | 'desc'>('desc');
  const [auditPage, setAuditPage] = useState(1);
  const [auditPerPage, setAuditPerPage] = useState(20);

  const buildAuditQuery = useCallback(
    ({ includePagination }: { includePagination: boolean }) => {
      const query = new URLSearchParams();

      if (includePagination) {
        query.set('page', String(auditPage));
        query.set('per_page', String(auditPerPage));
      }

      query.set('sort_by', auditSortBy);
      query.set('sort_direction', auditSortDirection);

      if (auditStateFilter !== 'todos') query.set('estado', auditStateFilter);
      if (auditMethodFilter !== 'all') query.set('metodo', auditMethodFilter);
      if (auditSearch.trim() !== '') query.set('search', auditSearch.trim());
      if (auditDateFrom !== '') query.set('date_from', auditDateFrom);
      if (auditDateTo !== '') query.set('date_to', auditDateTo);
      if (auditHasCredit === 'with') query.set('has_credit', '1');
      if (auditHasCredit === 'without') query.set('has_credit', '0');

      return query;
    },
    [auditDateFrom, auditDateTo, auditHasCredit, auditMethodFilter, auditPage, auditPerPage, auditSearch, auditSortBy, auditSortDirection, auditStateFilter],
  );

  const sourceOptions = useMemo(() => {
    const values = new Set<string>();
    aliasRows.forEach((row) => {
      if (row.source) values.add(row.source);
    });
    return ['all', ...Array.from(values).sort((a, b) => a.localeCompare(b, 'pt-PT'))];
  }, [aliasRows]);

  const loadAliases = useCallback(async () => {
    setAliasesLoading(true);
    try {
      const query = new URLSearchParams();
      if (aliasSearch.trim() !== '') query.set('search', aliasSearch.trim());
      if (aliasStatusFilter !== 'all') query.set('active', aliasStatusFilter === 'active' ? '1' : '0');
      if (aliasTargetFilter !== 'all') query.set('target_type', aliasTargetFilter);
      if (aliasSourceFilter !== 'all') query.set('source', aliasSourceFilter);
      query.set('page', String(aliasPage));
      query.set('per_page', String(aliasPerPage));

      const url = `${route('financeiro.bank-aliases.index')}${query.toString() ? `?${query.toString()}` : ''}`;
      const response = await fetchFinanceiro<{ aliases?: AliasRow[]; meta?: Partial<PaginationMeta> }>(url, {
        fallbackMessage: 'Nao foi possivel carregar aliases bancarios.',
      });

      setAliasRows(Array.isArray(response.aliases) ? response.aliases : []);
      setAliasMeta({
        current_page: Number(response.meta?.current_page ?? aliasPage),
        last_page: Number(response.meta?.last_page ?? 1),
        per_page: Number(response.meta?.per_page ?? aliasPerPage),
        total: Number(response.meta?.total ?? 0),
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel carregar aliases bancarios.';
      toast.error(message);
    } finally {
      setAliasesLoading(false);
    }
  }, [aliasPage, aliasPerPage, aliasSearch, aliasSourceFilter, aliasStatusFilter, aliasTargetFilter]);

  const loadRejectedSuggestions = useCallback(async () => {
    setRejectedLoading(true);
    try {
      const query = new URLSearchParams();
      query.set('status', 'rejected');
      query.set('per_page', String(rejectedPerPage));
      query.set('page', String(rejectedPage));
      if (rejectedSearch.trim() !== '') query.set('search', rejectedSearch.trim());

      const response = await fetchFinanceiro<SuggestionApiResponse>(`${route('financeiro.bank-reconciliation-suggestions.index')}?${query.toString()}`, {
        fallbackMessage: 'Nao foi possivel carregar sugestoes rejeitadas.',
      });

      setRejectedRows(Array.isArray(response.data) ? response.data : []);
      setRejectedMeta({
        current_page: Number(response.current_page ?? rejectedPage),
        last_page: Number(response.last_page ?? 1),
        per_page: Number(response.per_page ?? rejectedPerPage),
        total: Number(response.total ?? 0),
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel carregar sugestoes rejeitadas.';
      toast.error(message);
    } finally {
      setRejectedLoading(false);
    }
  }, [rejectedPage, rejectedPerPage, rejectedSearch]);

  const loadAudit = useCallback(async () => {
    setAuditLoading(true);
    try {
      const query = buildAuditQuery({ includePagination: true });

      const response = await fetchFinanceiro<AuditApiResponse>(`${route('financeiro.bank-reconciliation-audit.index')}?${query.toString()}`, {
        fallbackMessage: 'Nao foi possivel carregar auditoria de conciliacao.',
      });

      const rows = Array.isArray(response.rows) ? response.rows : Array.isArray(response.data) ? response.data : [];
      setAuditRows(rows);
      setAuditMeta({
        current_page: Number(response.meta?.current_page ?? auditPage),
        last_page: Number(response.meta?.last_page ?? 1),
        per_page: Number(response.meta?.per_page ?? auditPerPage),
        total: Number(response.meta?.total ?? 0),
      });
      setAuditSummary({
        total_linhas: Number(response.summary?.total_linhas ?? 0),
        total_conciliado: Number(response.summary?.total_conciliado ?? 0),
        total_parcial: Number(response.summary?.total_parcial ?? 0),
        total_por_conciliar: Number(response.summary?.total_por_conciliar ?? 0),
        total_alocado: Number(response.summary?.total_alocado ?? 0),
        total_por_alocar: Number(response.summary?.total_por_alocar ?? 0),
        total_credito_criado: Number(response.summary?.total_credito_criado ?? 0),
      });
      setAuditExportCapabilities({
        supportsCsv: response.export?.supports?.csv !== false,
        supportsXlsx: response.export?.supports?.xlsx === true,
        maxRows: Number(response.export?.max_rows ?? 5000),
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel carregar auditoria de conciliacao.';
      toast.error(message);
    } finally {
      setAuditLoading(false);
    }
  }, [auditPage, auditPerPage, buildAuditQuery]);

  const extractFilenameFromHeader = (headerValue: string | null, fallback: string) => {
    if (!headerValue) return fallback;

    const utf8Match = headerValue.match(/filename\*=UTF-8''([^;]+)/i);
    if (utf8Match?.[1]) {
      return decodeURIComponent(utf8Match[1].trim());
    }

    const basicMatch = headerValue.match(/filename="?([^";]+)"?/i);
    if (basicMatch?.[1]) {
      return basicMatch[1].trim();
    }

    return fallback;
  };

  const downloadAuditExport = useCallback(async (format: 'csv' | 'xlsx') => {
    setAuditExporting(format);

    try {
      const query = buildAuditQuery({ includePagination: false });
      query.set('format', format);

      const response = await fetch(`${route('financeiro.bank-reconciliation-audit.export')}?${query.toString()}`, {
        method: 'GET',
        headers: getFinanceiroJsonHeaders({ includeContentType: false }),
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error(await getFinanceiroFetchErrorMessage(response, 'Nao foi possivel exportar auditoria.'));
      }

      const blob = await response.blob();
      const fallbackName = format === 'xlsx'
        ? 'conciliacao-bancaria-auditoria.xlsx'
        : 'conciliacao-bancaria-auditoria.csv';
      const filename = extractFilenameFromHeader(response.headers.get('content-disposition'), fallbackName);

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);

      if (response.headers.get('x-export-truncated') === '1') {
        const limit = Number(response.headers.get('x-export-limit') ?? auditExportCapabilities.maxRows);
        toast.warning(`Exportacao limitada a ${limit} linhas para proteger operacao.`);
      } else {
        toast.success(format === 'xlsx' ? 'Exportacao Excel concluida.' : 'Exportacao CSV concluida.');
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel exportar auditoria.';
      toast.error(message);
    } finally {
      setAuditExporting(null);
    }
  }, [auditExportCapabilities.maxRows, buildAuditQuery]);

  const downloadAuditSummaryCsv = useCallback(async () => {
    setAuditExporting('summary_csv');

    try {
      const query = buildAuditQuery({ includePagination: false });

      const response = await fetch(`${route('financeiro.bank-reconciliation-audit.export-summary')}?${query.toString()}`, {
        method: 'GET',
        headers: getFinanceiroJsonHeaders({ includeContentType: false }),
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error(await getFinanceiroFetchErrorMessage(response, 'Nao foi possivel exportar resumo operacional.'));
      }

      const blob = await response.blob();
      const filename = extractFilenameFromHeader(
        response.headers.get('content-disposition'),
        'conciliacao-bancaria-auditoria-resumo.csv',
      );

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = filename;
      link.click();
      URL.revokeObjectURL(url);

      toast.success('Resumo operacional exportado com sucesso.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel exportar resumo operacional.';
      toast.error(message);
    } finally {
      setAuditExporting(null);
    }
  }, [buildAuditQuery]);

  useEffect(() => {
    setAliasPage(1);
  }, [aliasSearch, aliasStatusFilter, aliasTargetFilter, aliasSourceFilter, aliasPerPage]);

  useEffect(() => {
    setRejectedPage(1);
  }, [rejectedSearch, rejectedPerPage]);

  useEffect(() => {
    setAuditPage(1);
  }, [auditStateFilter, auditMethodFilter, auditSearch, auditDateFrom, auditDateTo, auditHasCredit, auditPerPage]);

  useEffect(() => {
    void loadAliases();
  }, [loadAliases]);

  useEffect(() => {
    void loadRejectedSuggestions();
  }, [loadRejectedSuggestions]);

  useEffect(() => {
    if (activeSubtab === 'auditoria') {
      void loadAudit();
    }
  }, [activeSubtab, loadAudit]);

  const formatAuditMethod = (value?: string | null) => {
    if (!value) return '-';

    if (value === 'sugestao_automatica') return 'Sugestao automatica';
    if (value === 'alocacao_assistida') return 'Alocacao assistida';
    if (value === 'despesa_extrato') return 'Despesa a partir de extrato';
    if (value === 'pagamento_manual') return 'Pagamento manual';
    if (value === 'outro_fluxo') return 'Outro fluxo';

    return value;
  };

  const formatAuditState = (value: ReconciliationState) => {
    if (value === 'conciliado') return 'Conciliado';
    if (value === 'parcial') return 'Parcial';
    return 'Por conciliar';
  };

  const toggleAlias = useCallback(async (alias: AliasRow) => {
    const activate = !alias.active;
    const confirmation = window.confirm(
      activate
        ? 'Reativar este alias para voltar a ser usado nas proximas sugestoes?'
        : 'Desativar este alias para evitar correspondencias futuras?'
    );

    if (!confirmation) return;

    setAliasActionId(alias.id);

    try {
      await fetchFinanceiro<{ alias?: { id: string; active: boolean } }>(
        activate
          ? route('financeiro.bank-aliases.reactivate', alias.id)
          : route('financeiro.bank-aliases.deactivate', alias.id),
        {
          method: 'POST',
          fallbackMessage: activate
            ? 'Nao foi possivel reativar alias.'
            : 'Nao foi possivel desativar alias.',
        }
      );

      toast.success(activate ? 'Alias reativado com sucesso.' : 'Alias desativado com sucesso.');
      await loadAliases();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel atualizar alias.';
      toast.error(message);
    } finally {
      setAliasActionId(null);
    }
  }, [loadAliases]);

  const clearRejection = useCallback(async (suggestion: SuggestionRow) => {
    const confirmation = window.confirm('Limpar esta rejeicao para permitir nova sugestao automatica?');

    if (!confirmation) return;

    setRejectionActionId(suggestion.id);

    try {
      await fetchFinanceiro<{ rejection_cleared?: boolean }>(
        route('financeiro.bank-reconciliation-suggestions.clear-rejection', suggestion.id),
        {
          method: 'POST',
          body: {
            reason: 'Limpeza manual em Configuracoes > Financeiro > Conciliacao Bancaria',
          },
          fallbackMessage: 'Nao foi possivel limpar rejeicao.',
        }
      );

      toast.success('Rejeicao limpa. A sugestao pode voltar a ser gerada.');
      await loadRejectedSuggestions();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Nao foi possivel limpar rejeicao.';
      toast.error(message);
    } finally {
      setRejectionActionId(null);
    }
  }, [loadRejectedSuggestions]);

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-lg">Conciliacao Bancaria</CardTitle>
          <CardDescription className="text-sm">
            Aliases ajudam o sistema a reconhecer transferencias futuras. Rejeicoes impedem que sugestoes erradas reaparecam automaticamente.
          </CardDescription>
        </CardHeader>
        <CardContent className="pt-0">
          <div className="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant={activeSubtab === 'aliases' ? 'default' : 'outline'} onClick={() => setActiveSubtab('aliases')}>
              Aliases
            </Button>
            <Button type="button" size="sm" variant={activeSubtab === 'rejeicoes' ? 'default' : 'outline'} onClick={() => setActiveSubtab('rejeicoes')}>
              Rejeicoes
            </Button>
            <Button type="button" size="sm" variant={activeSubtab === 'auditoria' ? 'default' : 'outline'} onClick={() => setActiveSubtab('auditoria')}>
              Auditoria
            </Button>
          </div>
        </CardContent>
      </Card>

      {activeSubtab === 'aliases' ? (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Aliases bancarios</CardTitle>
          <CardDescription className="text-sm">Consulte, desative e reative aliases aprendidos ou confirmados.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 md:grid-cols-4">
            <div className="space-y-1">
              <Label htmlFor="alias-search">Pesquisa</Label>
              <Input
                id="alias-search"
                value={aliasSearch}
                onChange={(event) => setAliasSearch(event.target.value)}
                placeholder="descricao, normalizado, utilizador"
              />
            </div>

            <div className="space-y-1">
              <Label>Estado</Label>
              <Select value={aliasStatusFilter} onValueChange={(value: 'all' | 'active' | 'inactive') => setAliasStatusFilter(value)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="active">Ativos</SelectItem>
                  <SelectItem value="inactive">Inativos</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label>Tipo alvo</Label>
              <Select value={aliasTargetFilter} onValueChange={(value: 'all' | 'user' | 'family') => setAliasTargetFilter(value)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="user">Utilizador</SelectItem>
                  <SelectItem value="family">Familia</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label>Origem</Label>
              <Select value={aliasSourceFilter} onValueChange={setAliasSourceFilter}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {sourceOptions.map((source) => (
                    <SelectItem key={source} value={source}>
                      {source === 'all' ? 'Todas' : source}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="flex gap-2">
            <Button type="button" onClick={() => void loadAliases()} disabled={aliasesLoading}>
              {aliasesLoading ? 'A carregar...' : 'Atualizar aliases'}
            </Button>
            <div className="w-40">
              <Select value={String(aliasPerPage)} onValueChange={(value) => setAliasPerPage(Number(value))}>
                <SelectTrigger>
                  <SelectValue placeholder="Por pagina" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="10">10 por pagina</SelectItem>
                  <SelectItem value="20">20 por pagina</SelectItem>
                  <SelectItem value="50">50 por pagina</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="max-h-[360px] overflow-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Valor</TableHead>
                  <TableHead>Associado a</TableHead>
                  <TableHead>Tipo</TableHead>
                  <TableHead>Confianca</TableHead>
                  <TableHead>Origem</TableHead>
                  <TableHead>Utilizacoes</TableHead>
                  <TableHead>Ultima utilizacao</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="text-right">Acoes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {aliasRows.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={9} className="text-center text-muted-foreground">Sem aliases para os filtros atuais.</TableCell>
                  </TableRow>
                ) : (
                  aliasRows.map((alias) => (
                    <TableRow key={alias.id}>
                      <TableCell>
                        <div className="font-medium">{alias.normalized_value || '-'}</div>
                        <div className="text-xs text-muted-foreground">{alias.original_value || alias.description || '-'}</div>
                      </TableCell>
                      <TableCell>{alias.target_name || '-'}</TableCell>
                      <TableCell>{alias.target_type === 'family' ? 'Familia' : alias.target_type === 'user' ? 'Utilizador' : '-'}</TableCell>
                      <TableCell>{typeof alias.confidence === 'number' ? `${alias.confidence}%` : '-'}</TableCell>
                      <TableCell>{alias.source || '-'}</TableCell>
                      <TableCell>{alias.usage_count ?? 0}</TableCell>
                      <TableCell>{formatDate(alias.last_used_at)}</TableCell>
                      <TableCell>
                        <Badge variant={alias.active ? 'default' : 'secondary'}>{alias.active ? 'Ativo' : 'Inativo'}</Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={!canEdit || aliasActionId === alias.id}
                          onClick={() => void toggleAlias(alias)}
                        >
                          {alias.active ? 'Desativar' : 'Reativar'}
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Pagina {aliasMeta.current_page} de {aliasMeta.last_page} · {aliasMeta.total} aliases
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={aliasesLoading || aliasMeta.current_page <= 1}
                onClick={() => setAliasPage((page) => Math.max(page - 1, 1))}
              >
                Anterior
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={aliasesLoading || aliasMeta.current_page >= aliasMeta.last_page}
                onClick={() => setAliasPage((page) => Math.min(page + 1, aliasMeta.last_page || 1))}
              >
                Seguinte
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
      ) : null}

      {activeSubtab === 'rejeicoes' ? (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Rejeicoes de sugestoes</CardTitle>
          <CardDescription className="text-sm">Visualize rejeicoes ativas e limpe quando precisar de permitir nova geracao.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 md:grid-cols-[1fr_auto]">
            <div className="space-y-1">
              <Label htmlFor="rejected-search">Pesquisa</Label>
              <Input
                id="rejected-search"
                value={rejectedSearch}
                onChange={(event) => setRejectedSearch(event.target.value)}
                placeholder="descricao, explicacao, utilizador"
              />
            </div>
            <div className="self-end">
              <div className="flex gap-2">
                <Button type="button" onClick={() => void loadRejectedSuggestions()} disabled={rejectedLoading}>
                  {rejectedLoading ? 'A carregar...' : 'Atualizar rejeicoes'}
                </Button>
                <div className="w-40">
                  <Select value={String(rejectedPerPage)} onValueChange={(value) => setRejectedPerPage(Number(value))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Por pagina" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="10">10 por pagina</SelectItem>
                      <SelectItem value="20">20 por pagina</SelectItem>
                      <SelectItem value="50">50 por pagina</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
          </div>

          <div className="max-h-[360px] overflow-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Descricao bancaria</TableHead>
                  <TableHead>Valor</TableHead>
                  <TableHead>Alvo sugerido</TableHead>
                  <TableHead>Score</TableHead>
                  <TableHead>Motivo</TableHead>
                  <TableHead>Rejeitado em</TableHead>
                  <TableHead>Rejeitado por</TableHead>
                  <TableHead className="text-right">Acoes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rejectedRows.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={9} className="text-center text-muted-foreground">Sem rejeicoes ativas para os filtros atuais.</TableCell>
                  </TableRow>
                ) : (
                  rejectedRows.map((suggestion) => {
                    const target = suggestion.user?.nome_completo
                      || suggestion.family?.nome
                      || '-';
                    const rules = Array.isArray(suggestion.matched_rules) ? suggestion.matched_rules.join(', ') : '';
                    const reason = suggestion.rejection_reason || suggestion.explanation || '-';

                    return (
                      <TableRow key={suggestion.id}>
                        <TableCell>{formatDate(suggestion.bank_statement?.data_movimento || null)}</TableCell>
                        <TableCell>{suggestion.bank_statement?.descricao || '-'}</TableCell>
                        <TableCell>{formatCurrency(suggestion.bank_statement?.valor ?? null)}</TableCell>
                        <TableCell>{target}</TableCell>
                        <TableCell>{typeof suggestion.score === 'number' ? `${suggestion.score}` : '-'}</TableCell>
                        <TableCell>
                          <div className="max-w-[260px] whitespace-normal text-sm">{reason}</div>
                          {rules ? <div className="text-xs text-muted-foreground">{rules}</div> : null}
                        </TableCell>
                        <TableCell>{formatDate(suggestion.rejected_at)}</TableCell>
                        <TableCell>{suggestion.rejected_by?.nome_completo || '-'}</TableCell>
                        <TableCell className="text-right">
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!canEdit || rejectionActionId === suggestion.id}
                            onClick={() => void clearRejection(suggestion)}
                          >
                            Limpar rejeicao
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </div>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Pagina {rejectedMeta.current_page} de {rejectedMeta.last_page} · {rejectedMeta.total} rejeicoes
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={rejectedLoading || rejectedMeta.current_page <= 1}
                onClick={() => setRejectedPage((page) => Math.max(page - 1, 1))}
              >
                Anterior
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={rejectedLoading || rejectedMeta.current_page >= rejectedMeta.last_page}
                onClick={() => setRejectedPage((page) => Math.min(page + 1, rejectedMeta.last_page || 1))}
              >
                Seguinte
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
      ) : null}

      {activeSubtab === 'auditoria' ? (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Relatorio de Conciliacao</CardTitle>
          <CardDescription className="text-sm">Consulta operacional de conciliacoes, alocacoes, creditos e historico de desconciliacoes.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 md:grid-cols-4">
            <div className="space-y-1">
              <Label>Estado</Label>
              <Select value={auditStateFilter} onValueChange={(value: 'todos' | 'por_conciliar' | 'parcial' | 'conciliado') => setAuditStateFilter(value)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="todos">Todos</SelectItem>
                  <SelectItem value="por_conciliar">Por conciliar</SelectItem>
                  <SelectItem value="parcial">Parcial</SelectItem>
                  <SelectItem value="conciliado">Conciliado</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label>Metodo</Label>
              <Select value={auditMethodFilter} onValueChange={setAuditMethodFilter}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="sugestao_automatica">Sugestao automatica</SelectItem>
                  <SelectItem value="alocacao_assistida">Alocacao assistida</SelectItem>
                  <SelectItem value="despesa_extrato">Despesa a partir de extrato</SelectItem>
                  <SelectItem value="pagamento_manual">Pagamento manual</SelectItem>
                  <SelectItem value="outro_fluxo">Outro fluxo</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label>Data de</Label>
              <Input type="date" value={auditDateFrom} onChange={(event) => setAuditDateFrom(event.target.value)} />
            </div>

            <div className="space-y-1">
              <Label>Data ate</Label>
              <Input type="date" value={auditDateTo} onChange={(event) => setAuditDateTo(event.target.value)} />
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-4">
            <div className="space-y-1 md:col-span-2">
              <Label htmlFor="audit-search">Pesquisa</Label>
              <Input
                id="audit-search"
                value={auditSearch}
                onChange={(event) => setAuditSearch(event.target.value)}
                placeholder="descricao, referencia, utilizador, familia"
              />
            </div>

            <div className="space-y-1">
              <Label>Credito</Label>
              <Select value={auditHasCredit} onValueChange={(value: 'all' | 'with' | 'without') => setAuditHasCredit(value)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">Com e sem credito</SelectItem>
                  <SelectItem value="with">Com credito</SelectItem>
                  <SelectItem value="without">Sem credito</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label>Ordenacao</Label>
              <div className="grid grid-cols-2 gap-2">
                <Select value={auditSortBy} onValueChange={(value: 'data_movimento' | 'valor') => setAuditSortBy(value)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="data_movimento">Data</SelectItem>
                    <SelectItem value="valor">Valor</SelectItem>
                  </SelectContent>
                </Select>
                <Select value={auditSortDirection} onValueChange={(value: 'asc' | 'desc') => setAuditSortDirection(value)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="desc">Desc</SelectItem>
                    <SelectItem value="asc">Asc</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-7">
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Total de linhas</div>
                <div className="text-lg font-semibold">{auditSummary.total_linhas}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Total conciliado</div>
                <div className="text-lg font-semibold">{auditSummary.total_conciliado}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Total parcial</div>
                <div className="text-lg font-semibold">{auditSummary.total_parcial}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Total por conciliar</div>
                <div className="text-lg font-semibold">{auditSummary.total_por_conciliar}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Valor alocado</div>
                <div className="text-lg font-semibold">{formatCurrency(auditSummary.total_alocado)}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Valor por alocar</div>
                <div className="text-lg font-semibold">{formatCurrency(auditSummary.total_por_alocar)}</div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-3">
                <div className="text-xs text-muted-foreground">Credito criado</div>
                <div className="text-lg font-semibold">{formatCurrency(auditSummary.total_credito_criado)}</div>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardContent className="p-3 text-sm">
              <div className="font-medium">Fecho operacional do periodo</div>
              <div className="text-muted-foreground">
                Para fechar o periodo, exporte a auditoria filtrada e confirme que nao existem linhas por conciliar.
              </div>
              {auditDateFrom !== '' || auditDateTo !== '' ? (
                <div className="mt-2">
                  {auditSummary.total_por_conciliar === 0 && auditSummary.total_parcial === 0 ? (
                    <Badge variant="default">Periodo pronto para conferencia</Badge>
                  ) : (
                    <Badge variant="secondary">Periodo com pendencias</Badge>
                  )}
                </div>
              ) : (
                <div className="mt-2 text-xs text-muted-foreground">Preencha data de e/ou data ate para avaliar conferencia do periodo.</div>
              )}
            </CardContent>
          </Card>

          <div className="flex gap-2">
            <Button type="button" onClick={() => void loadAudit()} disabled={auditLoading}>
              {auditLoading ? 'A carregar...' : 'Atualizar auditoria'}
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => void downloadAuditExport('csv')}
              disabled={auditExporting !== null || !auditExportCapabilities.supportsCsv}
            >
              {auditExporting === 'csv' ? 'A exportar CSV...' : 'Exportar CSV'}
            </Button>
            {auditExportCapabilities.supportsXlsx ? (
              <Button
                type="button"
                variant="outline"
                onClick={() => void downloadAuditExport('xlsx')}
                disabled={auditExporting !== null}
              >
                {auditExporting === 'xlsx' ? 'A exportar Excel...' : 'Exportar Excel'}
              </Button>
            ) : null}
            <Button
              type="button"
              variant="outline"
              onClick={() => void downloadAuditSummaryCsv()}
              disabled={auditExporting !== null}
            >
              {auditExporting === 'summary_csv' ? 'A exportar resumo...' : 'Exportar resumo CSV'}
            </Button>
            <span className="self-center text-xs text-muted-foreground">
              Exporta todas as linhas filtradas, nao apenas a pagina atual.
            </span>
            <div className="w-40">
              <Select value={String(auditPerPage)} onValueChange={(value) => setAuditPerPage(Number(value))}>
                <SelectTrigger>
                  <SelectValue placeholder="Por pagina" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="10">10 por pagina</SelectItem>
                  <SelectItem value="20">20 por pagina</SelectItem>
                  <SelectItem value="50">50 por pagina</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="max-h-[460px] overflow-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Descricao / Referencia</TableHead>
                  <TableHead>Valor</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Valor alocado</TableHead>
                  <TableHead>Valor por alocar</TableHead>
                  <TableHead>Metodo</TableHead>
                  <TableHead>Alvo</TableHead>
                  <TableHead>Conciliado por</TableHead>
                  <TableHead>Conciliado em</TableHead>
                  <TableHead className="text-right">Acoes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {auditRows.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={11} className="text-center text-muted-foreground">Sem linhas para os filtros atuais.</TableCell>
                  </TableRow>
                ) : (
                  auditRows.map((row) => (
                    <TableRow key={row.bank_statement_id}>
                      <TableCell>{formatDate(row.data_movimento || null)}</TableCell>
                      <TableCell>
                        <div className="font-medium">{row.descricao || '-'}</div>
                        <div className="text-xs text-muted-foreground">{row.referencia || '-'}</div>
                      </TableCell>
                      <TableCell>{formatCurrency(row.valor)}</TableCell>
                      <TableCell>
                        <Badge variant={row.estado_conciliacao === 'conciliado' ? 'default' : row.estado_conciliacao === 'parcial' ? 'secondary' : 'outline'}>
                          {formatAuditState(row.estado_conciliacao)}
                        </Badge>
                      </TableCell>
                      <TableCell>{formatCurrency(row.valor_alocado)}</TableCell>
                      <TableCell>{formatCurrency(row.valor_por_alocar)}</TableCell>
                      <TableCell>{formatAuditMethod(row.metodo_conciliacao)}</TableCell>
                      <TableCell>
                        <div className="max-w-[220px] whitespace-normal">{row.target_summary?.nome_principal || '-'}</div>
                        <div className="text-xs text-muted-foreground">
                          Mensalidades liquidadas: {row.target_summary?.faturas_afetadas ?? 0} · Movimentos liquidados: {row.target_summary?.movimentos_afetados ?? 0}
                        </div>
                      </TableCell>
                      <TableCell>{row.reconciled_by_name || '-'}</TableCell>
                      <TableCell>{formatDate(row.reconciled_at || null)}</TableCell>
                      <TableCell className="text-right">
                        <details>
                          <summary className="cursor-pointer text-sm text-primary">Ver detalhe</summary>
                          <div className="mt-2 space-y-2 rounded border p-2 text-left text-xs">
                            <div>
                              <strong>Mensalidades / Faturas e Movimentos</strong>
                              <div className="mt-1 space-y-1">
                                {(row.allocations || []).length === 0 ? (
                                  <div className="text-muted-foreground">Sem alocacoes detalhadas.</div>
                                ) : (
                                  (row.allocations || []).map((allocation) => (
                                    <div key={`${row.bank_statement_id}-${allocation.tipo}-${allocation.id}`}>
                                      {allocation.tipo} · {allocation.descricao} · {allocation.mes || '-'} · {formatCurrency(allocation.valor_alocado)} · {allocation.estado || '-'}
                                    </div>
                                  ))
                                )}
                              </div>
                            </div>

                            <div>
                              <strong>Credito criado</strong>
                              <div>{formatCurrency(row.target_summary?.valor_credito_criado ?? 0)}</div>
                            </div>

                            <div>
                              <strong>Flags</strong>
                              <div>
                                tem_credito: {row.flags?.tem_credito ? 'sim' : 'nao'} · tem_desconciliacao: {row.flags?.tem_desconciliacao ? 'sim' : 'nao'} · tem_documento_fiscal_emitido: {row.flags?.tem_documento_fiscal_emitido ? 'sim' : 'nao'} · bloqueado_para_desconciliar: {row.flags?.bloqueado_para_desconciliar ? 'sim' : 'nao'}
                              </div>
                            </div>

                            <div>
                              <strong>Historico de desconciliacoes</strong>
                              <div className="mt-1 space-y-1">
                                {(row.historico_desconciliacoes || []).length === 0 ? (
                                  <div className="text-muted-foreground">Sem historico registado.</div>
                                ) : (
                                  (row.historico_desconciliacoes || []).map((event, index) => (
                                    <div key={`${row.bank_statement_id}-history-${index}`}>
                                      {(event.cancelled_at || '-') + ' · ' + (event.cancelled_by_name || '-') + ' · ' + (event.tipo || '-')}
                                    </div>
                                  ))
                                )}
                              </div>
                            </div>

                            <div>
                              <strong>Estado fiscal</strong>
                              <div>{row.fiscal_status || '-'}</div>
                            </div>

                            <div>
                              <strong>Erros / bloqueios</strong>
                              <div className="mt-1 space-y-1">
                                {(row.erros_ou_bloqueios || []).length === 0 ? (
                                  <div className="text-muted-foreground">Sem bloqueios relevantes.</div>
                                ) : (
                                  (row.erros_ou_bloqueios || []).map((issue, index) => (
                                    <div key={`${row.bank_statement_id}-issue-${index}`}>{issue}</div>
                                  ))
                                )}
                              </div>
                            </div>
                          </div>
                        </details>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Pagina {auditMeta.current_page} de {auditMeta.last_page} · {auditMeta.total} linhas
            </span>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={auditLoading || auditMeta.current_page <= 1}
                onClick={() => setAuditPage((page) => Math.max(page - 1, 1))}
              >
                Anterior
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={auditLoading || auditMeta.current_page >= auditMeta.last_page}
                onClick={() => setAuditPage((page) => Math.min(page + 1, auditMeta.last_page || 1))}
              >
                Seguinte
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>
      ) : null}
    </div>
  );
}
