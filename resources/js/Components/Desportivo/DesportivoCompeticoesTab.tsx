/**
 * DesportivoCompeticoesTab
 *
 * Sub-tabs internas:
 * - Competições (vista atual)
 * - Resultados (fluxo por competição -> resultados CRUD)
 */

import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ResultadosCompeticoesForm } from './ResultadosCompeticoesForm';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import { MagnifyingGlass, CalendarBlank, Trophy } from '@phosphor-icons/react';
import type { Competition, EventResult, TeamResult, User } from './types';

interface EventItem {
  id: string;
  titulo: string;
  data_inicio: string;
  data_fim?: string;
  hora_inicio?: string;
  hora_fim?: string;
  tipo?: string;
  estado?: string;
  local?: string;
  descricao?: string;
  centro_custo_id?: string;
  local_detalhes?: string;
  tipo_piscina?: string;
  escaloes_elegiveis?: string[];
  transporte_necessario?: boolean;
  hora_partida?: string;
  local_partida?: string;
  transporte_detalhes?: string;
  taxa_inscricao?: string | number | null;
  custo_inscricao_por_prova?: string | number | null;
  custo_inscricao_por_salto?: string | number | null;
  custo_inscricao_estafeta?: string | number | null;
  observacoes?: string;
  visibilidade?: string;
  recorrente?: boolean;
  recorrencia_data_inicio?: string;
  recorrencia_data_fim?: string;
  recorrencia_dias_semana?: string[];
}

interface ProvaTipoItem {
  id: string;
  nome: string;
}

interface ConvocationAthleteItem {
  atleta_id: string;
  atleta_nome?: string;
  provas?: string[];
  estafetas?: number;
  presente?: boolean;
  confirmado?: boolean;
}

interface ConvocationGroupItem {
  id: string;
  evento_id: string;
  evento_titulo?: string;
  evento_data?: string;
  atletas_ids?: string[];
  athletes?: ConvocationAthleteItem[];
}

interface CostCenterItem {
  id: string;
  nome: string;
  codigo?: string;
  ativo?: boolean;
}

interface EventTypeItem {
  id: string;
  nome: string;
  visibilidade_default?: string;
  ativo?: boolean;
}

interface Props {
  competitions: Competition[];
  results: EventResult[];
  users: User[];
  teamResults?: TeamResult[];
  eventos?: EventItem[];
  ageGroups?: Array<{ id: string; nome: string }>;
  costCenters?: CostCenterItem[];
  eventTypes?: EventTypeItem[];
  convocationGroups?: ConvocationGroupItem[];
  provaTipos?: ProvaTipoItem[];
}

const TIPO_BADGE: Record<string, string> = {
  prova: 'bg-indigo-100 text-indigo-800 border-indigo-200',
  treino: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  reuniao: 'bg-slate-100 text-slate-600 border-slate-200',
};

const normalize = (value: string | null | undefined): string =>
  (value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const formatDate = (value: string | null | undefined): string => (value || '').slice(0, 10) || '—';

  const formatCurrency = (value: string | number | null | undefined): string => {
    if (value === null || value === undefined || value === '') return '—';
    const numeric = Number(value);
    return Number.isNaN(numeric) ? String(value) : `${numeric.toFixed(2)} EUR`;
  };

  const formatPoolType = (value: string | null | undefined): string => {
    switch (value) {
      case 'piscina_25m':
        return 'Piscina de 25m';
      case 'piscina_50m':
        return 'Piscina de 50m';
      case 'aguas_abertas':
        return 'Aguas Abertas';
      default:
        return value || '—';
    }
  };

  const formatVisibility = (value: string | null | undefined): string => {
    switch (value) {
      case 'publico':
        return 'Publico';
      case 'privado':
        return 'Privado';
      case 'interno':
        return 'Interno';
      default:
        return value || '—';
    }
  };

  const formatStatusLabel = (value: string | null | undefined): string => {
    switch (value) {
      case 'agendado':
        return 'Agendado';
      case 'em_curso':
        return 'A decorrer';
      case 'concluido':
        return 'Concluido';
      case 'cancelado':
        return 'Cancelado';
      case 'rascunho':
        return 'Rascunho';
      default:
        return value || '—';
    }
  };

  const formatWeekDays = (days: string[] | null | undefined): string => {
    if (!days || days.length === 0) return '—';

    const map: Record<string, string> = {
      monday: 'Seg',
      tuesday: 'Ter',
      wednesday: 'Qua',
      thursday: 'Qui',
      friday: 'Sex',
      saturday: 'Sab',
      sunday: 'Dom',
    };

    return days.map((day) => map[day] || day).join(', ');
  };

export function DesportivoCompeticoesTab({
  competitions,
  results,
  users,
  teamResults = [],
  eventos = [],
  ageGroups = [],
  costCenters = [],
  eventTypes = [],
  convocationGroups = [],
  provaTipos = [],
}: Props) {
  const [subTab, setSubTab] = useState<'competicoes' | 'resultados'>('competicoes');
  const [search, setSearch] = useState('');
  const [view, setView] = useState<'list' | 'calendar'>('list');
  const [selectedCompetitionId, setSelectedCompetitionId] = useState<string>('');
  const [addResultTrigger, setAddResultTrigger] = useState(0);
  const [summaryCompetitionId, setSummaryCompetitionId] = useState<string>('');
  const [resultSearch, setResultSearch] = useState('');
  const [resultStatusFilter, setResultStatusFilter] = useState<'all' | 'running' | 'finished'>('all');
  const [resultEscalaoFilter, setResultEscalaoFilter] = useState<string>('all');

  const filtered = competitions.filter((c) =>
    c.titulo.toLowerCase().includes(search.toLowerCase()) ||
    (c.local ?? '').toLowerCase().includes(search.toLowerCase())
  );

  const byMonth = filtered.reduce<Record<string, Competition[]>>((acc, comp) => {
    const month = (comp.data_inicio || '').slice(0, 7) || 'sem-data';
    if (!acc[month]) acc[month] = [];
    acc[month].push(comp);
    return acc;
  }, {});

  const sortedMonths = Object.keys(byMonth).sort((a, b) => b.localeCompare(a));
  const totalAthletes = new Set(users.map((u) => u.id)).size;

  const convocationGroupsByCompetition = useMemo(() => {
    const byCompetition = new Map<string, ConvocationGroupItem[]>();

    competitions.forEach((competition) => {
      const competitionTitle = normalize(competition.titulo);
      const competitionDay = formatDate(competition.data_inicio);

      const matchingGroups = convocationGroups.filter((group) => {
        if (group.evento_id === competition.id) return true;

        const groupTitle = normalize(group.evento_titulo);
        const groupDay = formatDate(group.evento_data);

        return Boolean(groupTitle && groupTitle === competitionTitle && groupDay === competitionDay);
      });

      byCompetition.set(competition.id, matchingGroups);
    });

    return byCompetition;
  }, [competitions, convocationGroups]);

  const metricsByCompetition = filtered.reduce<Record<string, { athletes: number; provas: number }>>((acc, comp) => {
    const eventResults = results.filter((r) => r.event?.id === comp.id);
    const linkedGroups = convocationGroupsByCompetition.get(comp.id) ?? [];
    const convokedAthletes = new Set<string>();

    linkedGroups.forEach((group) => {
      (group.athletes || []).forEach((athlete) => {
        if (athlete.atleta_id) convokedAthletes.add(athlete.atleta_id);
      });
    });

    acc[comp.id] = {
      athletes: convokedAthletes.size,
      provas: eventResults.length,
    };
    return acc;
  }, {});

  const getEstado = (dataInicio: string): string => {
    const day = (dataInicio || '').slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);
    if (!day) return 'indefinido';
    if (day < today) return 'concluida';
    if (day === today) return 'hoje';
    return 'agendada';
  };

  const competitionEscaloes = useMemo(() => {
    const byCompetition = new Map<string, Set<string>>();

    competitions.forEach((competition) => {
      const competitionId = competition.id;
      const competitionTitle = normalize(competition.titulo);
      const competitionDay = (competition.data_inicio || '').slice(0, 10);

      const matchingGroups = convocationGroupsByCompetition.get(competitionId) ?? [];

      const escalaoIds = new Set<string>();

      matchingGroups.forEach((group) => {
        (group.athletes || []).forEach((athlete) => {
          const user = users.find((u) => u.id === athlete.atleta_id);
          (user?.escalao || []).forEach((id) => escalaoIds.add(id));
        });
      });

      byCompetition.set(competitionId, escalaoIds);
    });

    return byCompetition;
  }, [competitions, convocationGroupsByCompetition, users]);

  const filteredCompetitionsForResults = useMemo(() => {
    return competitions.filter((competition) => {
      const status = getEstado(competition.data_inicio);
      const isRunning = status === 'hoje' || status === 'agendada';
      const isFinished = status === 'concluida';

      if (resultStatusFilter === 'running' && !isRunning) return false;
      if (resultStatusFilter === 'finished' && !isFinished) return false;

      if (resultSearch.trim()) {
        const q = resultSearch.toLowerCase();
        const matchesText =
          competition.titulo.toLowerCase().includes(q) ||
          (competition.local ?? '').toLowerCase().includes(q);

        if (!matchesText) return false;
      }

      if (resultEscalaoFilter !== 'all') {
        const escaloes = competitionEscaloes.get(competition.id) ?? new Set<string>();
        if (!escaloes.has(resultEscalaoFilter)) return false;
      }

      return true;
    });
  }, [competitions, resultStatusFilter, resultSearch, resultEscalaoFilter, competitionEscaloes]);

  const selectedCompetition = useMemo(
    () => competitions.find((competition) => competition.id === selectedCompetitionId) ?? null,
    [competitions, selectedCompetitionId]
  );

  const summaryCompetition = useMemo(
    () => competitions.find((competition) => competition.id === summaryCompetitionId) ?? null,
    [competitions, summaryCompetitionId]
  );

  const summaryEvent = useMemo(() => {
    if (!summaryCompetition) return null;

    const direct = eventos.find((evento) => evento.id === summaryCompetition.id);
    if (direct) return direct;

    const competitionTitle = normalize(summaryCompetition.titulo);
    const competitionDay = formatDate(summaryCompetition.data_inicio);

    return eventos.find((evento) => {
      return normalize(evento.titulo) === competitionTitle && formatDate(evento.data_inicio) === competitionDay;
    }) || null;
  }, [eventos, summaryCompetition]);

  const costCenterLabelMap = useMemo(() => {
    return new Map(costCenters.map((cc) => [cc.id, cc.codigo ? `${cc.codigo} - ${cc.nome}` : cc.nome]));
  }, [costCenters]);

  const eventTypeLabelMap = useMemo(() => {
    return new Map(eventTypes.map((item) => [item.id, item.nome]));
  }, [eventTypes]);

  const ageGroupLabelMap = useMemo(() => {
    return new Map(ageGroups.map((group) => [group.id, group.nome]));
  }, [ageGroups]);

  const summaryEscaloes = useMemo(() => {
    const values = summaryEvent?.escaloes_elegiveis || [];
    if (values.length === 0) return '—';
    return values.map((value) => ageGroupLabelMap.get(value) || value).join(', ');
  }, [summaryEvent, ageGroupLabelMap]);

  useEffect(() => {
    if (!selectedCompetitionId && filteredCompetitionsForResults.length > 0) {
      setSelectedCompetitionId(filteredCompetitionsForResults[0].id);
      return;
    }

    if (selectedCompetitionId && !filteredCompetitionsForResults.some((competition) => competition.id === selectedCompetitionId)) {
      setSelectedCompetitionId(filteredCompetitionsForResults[0]?.id ?? '');
    }
  }, [filteredCompetitionsForResults, selectedCompetitionId]);

  return (
    <div className="space-y-3">
      <Tabs value={subTab} onValueChange={(value) => setSubTab(value as typeof subTab)} className="w-full">
        <TabsList className="grid w-full grid-cols-2 h-9">
          <TabsTrigger value="competicoes" className="text-xs">Competições</TabsTrigger>
          <TabsTrigger value="resultados" className="text-xs">Resultados</TabsTrigger>
        </TabsList>

        <TabsContent value="competicoes" className="mt-3 space-y-3">
          <div className="flex items-center gap-2 flex-wrap">
            <div className="relative flex-1 min-w-48">
              <MagnifyingGlass size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Pesquisar competição..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-7 h-8 text-xs"
              />
            </div>
            <div className="flex gap-1">
              <Button size="sm" variant={view === 'list' ? 'default' : 'outline'} onClick={() => setView('list')}>
                Lista
              </Button>
              <Button size="sm" variant={view === 'calendar' ? 'default' : 'outline'} onClick={() => setView('calendar')}>
                <CalendarBlank size={13} className="mr-1" />
                Mes
              </Button>
            </div>
            <Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.competicoes'))}>
              Gerir Competições →
            </Button>
          </div>

          <div className="grid gap-2 grid-cols-3">
            <Card className="p-2.5">
              <p className="text-[11px] text-muted-foreground">Total</p>
              <p className="text-lg font-semibold leading-none mt-1">{competitions.length}</p>
            </Card>
            <Card className="p-2.5">
              <p className="text-[11px] text-muted-foreground">Resultados registados</p>
              <p className="text-lg font-semibold leading-none mt-1">{results.length}</p>
            </Card>
            <Card className="p-2.5">
              <p className="text-[11px] text-muted-foreground">Atletas com resultados</p>
              <p className="text-lg font-semibold leading-none mt-1">
                {new Set(results.map((r) => r.athlete?.nome_completo).filter(Boolean)).size}
              </p>
            </Card>
          </div>

          {view === 'list' && (
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">Competições ({filtered.length})</CardTitle>
              </CardHeader>
              <CardContent className="space-y-1.5">
                {filtered.length === 0 && <p className="text-xs text-muted-foreground">Sem resultados para a pesquisa.</p>}
                {filtered.map((comp) => (
                  <div key={comp.id} className="grid grid-cols-12 items-center gap-2 border rounded-md px-3 py-2">
                    <div className="col-span-6 min-w-0">
                      <p className="text-xs font-medium truncate">{comp.titulo}</p>
                      <p className="text-[11px] text-muted-foreground">
                        {formatDate(comp.data_inicio)}
                        {comp.local ? ` · ${comp.local}` : ''}
                      </p>
                    </div>
                    <div className="col-span-3 text-[11px] text-muted-foreground">
                      <p>Estado: {getEstado(comp.data_inicio)}</p>
                      <p>Atletas: {metricsByCompetition[comp.id]?.athletes ?? 0}</p>
                      <p>Resultados: {metricsByCompetition[comp.id]?.provas ?? 0}</p>
                    </div>
                    <Badge
                      variant="outline"
                      className={`text-[10px] shrink-0 col-span-1 ${TIPO_BADGE[comp.tipo] ?? TIPO_BADGE.reuniao}`}
                    >
                      {comp.tipo}
                    </Badge>
                    <Button
                      size="sm"
                      variant="ghost"
                      className="h-6 px-2 text-[11px] shrink-0 col-span-2"
                      onClick={() => setSummaryCompetitionId(comp.id)}
                    >
                      Ver
                    </Button>
                  </div>
                ))}
              </CardContent>
            </Card>
          )}

          {view === 'calendar' && (
            <div className="space-y-3">
              {sortedMonths.map((month) => (
                <Card key={month}>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm flex items-center gap-2">
                      <CalendarBlank size={14} />
                      {month}
                      <Badge variant="secondary" className="text-[10px]">
                        {byMonth[month].length}
                      </Badge>
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-1">
                    {byMonth[month].map((comp) => (
                      <div key={comp.id} className="flex items-center gap-3 border rounded-md px-3 py-1.5">
                        <Trophy size={12} className="text-amber-500 shrink-0" />
                        <p className="text-xs font-medium flex-1 truncate">{comp.titulo}</p>
                        <p className="text-[11px] text-muted-foreground shrink-0">{formatDate(comp.data_inicio).slice(8, 10)}</p>
                      </div>
                    ))}
                  </CardContent>
                </Card>
              ))}
              {sortedMonths.length === 0 && (
                <Card>
                  <CardContent className="py-8 text-center text-xs text-muted-foreground">Sem competições registadas.</CardContent>
                </Card>
              )}
            </div>
          )}
        </TabsContent>

        <TabsContent value="resultados" className="mt-3">
          <div className="grid gap-3 lg:grid-cols-12">
            <Card className="lg:col-span-4">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm">Competições</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <div className="relative">
                  <MagnifyingGlass size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    value={resultSearch}
                    onChange={(e) => setResultSearch(e.target.value)}
                    placeholder="Pesquisar competição..."
                    className="h-8 pl-7 text-xs"
                  />
                </div>

                <div className="grid grid-cols-2 gap-1">
                  <Button
                    size="sm"
                    variant={resultStatusFilter === 'running' ? 'default' : 'outline'}
                    className="h-7 text-[11px]"
                    onClick={() => setResultStatusFilter('running')}
                  >
                    A decorrer
                  </Button>
                  <Button
                    size="sm"
                    variant={resultStatusFilter === 'finished' ? 'default' : 'outline'}
                    className="h-7 text-[11px]"
                    onClick={() => setResultStatusFilter('finished')}
                  >
                    Concluídas
                  </Button>
                </div>

                <Button
                  size="sm"
                  variant={resultStatusFilter === 'all' ? 'default' : 'outline'}
                  className="w-full h-7 text-[11px]"
                  onClick={() => setResultStatusFilter('all')}
                >
                  Todas
                </Button>

                <div>
                  <label className="text-[11px] text-muted-foreground">Escalão</label>
                  <select
                    value={resultEscalaoFilter}
                    onChange={(e) => setResultEscalaoFilter(e.target.value)}
                    className="mt-1 h-8 w-full rounded-md border bg-background px-2 text-xs"
                  >
                    <option value="all">Todos os escalões</option>
                    {ageGroups.map((group) => (
                      <option key={group.id} value={group.id}>{group.nome}</option>
                    ))}
                  </select>
                </div>

                <div className="max-h-[420px] overflow-y-auto space-y-1.5 pt-1">
                  {filteredCompetitionsForResults.length === 0 && (
                    <p className="text-xs text-muted-foreground py-3 text-center">Sem competições para estes filtros.</p>
                  )}

                  {filteredCompetitionsForResults.map((competition) => {
                    const status = getEstado(competition.data_inicio);
                    return (
                      <button
                        key={competition.id}
                        type="button"
                        onClick={() => setSelectedCompetitionId(competition.id)}
                        className={`w-full text-left border rounded-md p-2 transition-colors ${
                          selectedCompetitionId === competition.id ? 'border-primary bg-primary/5' : 'hover:bg-muted/40'
                        }`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <p className="text-xs font-medium truncate">{competition.titulo}</p>
                          <Badge variant="outline" className="text-[10px] capitalize">{status}</Badge>
                        </div>
                        <p className="text-[11px] text-muted-foreground mt-1">
                          {competition.data_inicio}{competition.local ? ` · ${competition.local}` : ''}
                        </p>
                      </button>
                    );
                  })}
                </div>
              </CardContent>
            </Card>

            <Card className="lg:col-span-8">
              <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                  <CardTitle className="text-sm flex items-center gap-2">
                    <Trophy size={14} className="text-amber-500" />
                    Gestão de Resultados
                  </CardTitle>
                  <Button
                    size="sm"
                    onClick={() => setAddResultTrigger((prev) => prev + 1)}
                    disabled={!selectedCompetition}
                    className="h-7 text-xs"
                  >
                    Adicionar Resultado
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                  {selectedCompetition
                    ? `${selectedCompetition.titulo} · ${formatDate(selectedCompetition.data_inicio)}`
                    : 'Selecione uma competição para gerir os resultados.'}
                </p>
              </CardHeader>
              <CardContent>
                <ResultadosCompeticoesForm
                  athletes={users}
                  competitions={competitions}
                  convocationGroups={convocationGroups}
                  provaTipos={provaTipos}
                  selectedCompetitionId={selectedCompetitionId}
                  openAddTrigger={addResultTrigger}
                />
              </CardContent>
            </Card>
          </div>
        </TabsContent>

              </Tabs>

      <Dialog open={Boolean(summaryCompetition)} onOpenChange={(open) => !open && setSummaryCompetitionId('')}>
        <DialogContent className="max-w-4xl p-3 pt-2 gap-0.5">
          <DialogHeader className="space-y-0 pb-0 mb-0">
            <DialogTitle className="text-base leading-tight">{summaryCompetition?.titulo ?? 'Resumo da competição'}</DialogTitle>
          </DialogHeader>

          {summaryCompetition && (
            <div className="space-y-2 max-h-[75vh] overflow-y-auto pr-1 mt-0 pt-0 [&_[data-slot=card]]:gap-0.5">
              <div className="space-y-0.5">
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Titulo</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryCompetition.titulo}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Tipo</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{eventTypeLabelMap.get(summaryEvent?.tipo || '') || summaryEvent?.tipo || summaryCompetition.tipo}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Centro de Custo</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{costCenterLabelMap.get(summaryEvent?.centro_custo_id || '') || '—'}</p>
                  </Card>
                  <Card className="p-2 lg:col-span-3 sm:col-span-2">
                    <p className="text-[11px] text-muted-foreground">Descricao</p>
                    <p className="text-xs font-medium mt-0 leading-tight whitespace-pre-wrap">{summaryEvent?.descricao || '—'}</p>
                  </Card>
                  <Card className="p-2 lg:col-span-3 sm:col-span-2">
                    <p className="text-[11px] text-muted-foreground">Escaloes Elegiveis</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEscaloes}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Data e Hora</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Data Inicio</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatDate(summaryEvent?.data_inicio || summaryCompetition.data_inicio)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Hora Inicio</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.hora_inicio || '—'}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Data Fim</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatDate(summaryEvent?.data_fim)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Hora Fim</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.hora_fim || '—'}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Local</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Local</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.local || summaryCompetition.local || '—'}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Tipo de Piscina</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatPoolType(summaryEvent?.tipo_piscina)}</p>
                  </Card>
                  <Card className="p-2 lg:col-span-3 sm:col-span-2">
                    <p className="text-[11px] text-muted-foreground">Morada / Detalhes</p>
                    <p className="text-xs font-medium mt-0 leading-tight whitespace-pre-wrap">{summaryEvent?.local_detalhes || '—'}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Transporte</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Transporte Necessario</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.transporte_necessario ? 'Sim' : 'Nao'}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Hora de Partida</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.hora_partida || '—'}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Local de Partida</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.local_partida || '—'}</p>
                  </Card>
                  <Card className="p-2 lg:col-span-4 sm:col-span-2">
                    <p className="text-[11px] text-muted-foreground">Detalhes do Transporte</p>
                    <p className="text-xs font-medium mt-0 leading-tight whitespace-pre-wrap">{summaryEvent?.transporte_detalhes || '—'}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Custos de Inscricao</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Taxa de Inscricao</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatCurrency(summaryEvent?.taxa_inscricao)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Custo por Prova</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatCurrency(summaryEvent?.custo_inscricao_por_prova)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Custo por Salto</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatCurrency(summaryEvent?.custo_inscricao_por_salto)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Custo Estafeta</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatCurrency(summaryEvent?.custo_inscricao_estafeta)}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Observacoes e Estado</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-2 lg:col-span-4 sm:col-span-2">
                    <p className="text-[11px] text-muted-foreground">Observacoes</p>
                    <p className="text-xs font-medium mt-0 leading-tight whitespace-pre-wrap">{summaryEvent?.observacoes || '—'}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Visibilidade</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatVisibility(summaryEvent?.visibilidade)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Estado</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatStatusLabel(summaryEvent?.estado) || getEstado(summaryCompetition.data_inicio)}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Recorrencia</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Evento Recorrente</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{summaryEvent?.recorrente ? 'Sim' : 'Nao'}</p>
                  </Card>
                  <Card className="p-2 lg:col-span-3">
                    <p className="text-[11px] text-muted-foreground">Dias da Semana</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatWeekDays(summaryEvent?.recorrencia_dias_semana)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Inicio Recorrencia</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatDate(summaryEvent?.recorrencia_data_inicio)}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Fim Recorrencia</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{formatDate(summaryEvent?.recorrencia_data_fim)}</p>
                  </Card>
                </div>
              </div>

              <div className="space-y-2">
                <h3 className="text-xs font-semibold border-b pb-1.5">Resumo Operacional</h3>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Atletas convocados</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{metricsByCompetition[summaryCompetition.id]?.athletes ?? 0}</p>
                  </Card>
                  <Card className="p-1.5 gap-0">
                    <p className="text-[11px] text-muted-foreground">Resultados registados</p>
                    <p className="text-xs font-medium mt-0 leading-tight">{metricsByCompetition[summaryCompetition.id]?.provas ?? 0}</p>
                  </Card>
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
