import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { toast } from 'sonner';
import { fetchFinanceiro } from '@/Pages/Financeiro/request';

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
  const [aliasRows, setAliasRows] = useState<AliasRow[]>([]);
  const [rejectedRows, setRejectedRows] = useState<SuggestionRow[]>([]);
  const [aliasMeta, setAliasMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [rejectedMeta, setRejectedMeta] = useState<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 20, total: 0 });
  const [aliasesLoading, setAliasesLoading] = useState(false);
  const [rejectedLoading, setRejectedLoading] = useState(false);
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

  useEffect(() => {
    setAliasPage(1);
  }, [aliasSearch, aliasStatusFilter, aliasTargetFilter, aliasSourceFilter, aliasPerPage]);

  useEffect(() => {
    setRejectedPage(1);
  }, [rejectedSearch, rejectedPerPage]);

  useEffect(() => {
    void loadAliases();
  }, [loadAliases]);

  useEffect(() => {
    void loadRejectedSuggestions();
  }, [loadRejectedSuggestions]);

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
      </Card>

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
    </div>
  );
}
