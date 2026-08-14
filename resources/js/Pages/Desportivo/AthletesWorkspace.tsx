import { useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { AddressBook, ChartBar, SquaresFour, Rows, UsersThree } from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';

type Option = { id: string; name: string };
type GroupOption = Option & { primary?: boolean };
type AgeGroupOption = Option & { modality_id?: string };

type AthleteRow = {
  id: string;
  name: string;
  member_number?: string | null;
  state: 'active' | 'inactive';
  modalities: Option[];
  groups: GroupOption[];
  age_groups: AgeGroupOption[];
  attendance_30d: number | null;
  scheduled_30d: number;
  volume_30d_m: number;
  avg_rpe_30d: number | null;
  podiums_12m: number;
  latest_evaluation_score: number | null;
  medical_document: { status: 'validated' | 'pending'; validated_at?: string | null };
};

type Detail = {
  athlete: { id: string; name: string; member_number?: string | null; state: 'active' | 'inactive' };
  sports_profile: any;
  analysis: any | null;
  medical_document: { status: 'validated' | 'pending'; validated_at?: string | null };
};

type Props = {
  athletes: AthleteRow[];
  stats: { total: number; active: number; without_group: number; low_attendance: number; medical_pending: number };
  filters: { modalities: Option[]; groups: Option[]; age_groups: Option[]; states: Option[] };
  principles: {
    canonical_participation: boolean;
    medical_document_owner: string;
    legacy_medical_json_active: boolean;
    attendance_is_real_training_assignment: boolean;
  };
};

const pct = (value: number | null) => value === null ? '—' : `${value.toFixed(1)}%`;
const km = (value: number) => `${(value / 1000).toFixed(1)} km`;
const numeric = (value: number | null, digits = 1) => value === null ? '—' : Number(value).toFixed(digits);

export default function AthletesWorkspace({ athletes, stats, filters, principles }: Props) {
  const [query, setQuery] = useState('');
  const [stateFilter, setStateFilter] = useState('active');
  const [modalityFilter, setModalityFilter] = useState('');
  const [groupFilter, setGroupFilter] = useState('');
  const [ageGroupFilter, setAgeGroupFilter] = useState('');
  const [viewMode, setViewMode] = useState<'list' | 'cards'>('list');
  const [selectedId, setSelectedId] = useState<string>('');
  const [detail, setDetail] = useState<Detail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const filteredAthletes = useMemo(() => {
    const normalizedQuery = query.trim().toLowerCase();
    return athletes.filter((athlete) => {
      if (stateFilter && athlete.state !== stateFilter) return false;
      if (modalityFilter && !athlete.modalities.some((item) => item.id === modalityFilter)) return false;
      if (groupFilter && !athlete.groups.some((item) => item.id === groupFilter)) return false;
      if (ageGroupFilter && !athlete.age_groups.some((item) => item.id === ageGroupFilter)) return false;
      if (!normalizedQuery) return true;

      return athlete.name.toLowerCase().includes(normalizedQuery)
        || String(athlete.member_number ?? '').toLowerCase().includes(normalizedQuery);
    });
  }, [athletes, query, stateFilter, modalityFilter, groupFilter, ageGroupFilter]);

  useEffect(() => {
    if (!selectedId) {
      setDetail(null);
      return;
    }

    setDetailLoading(true);
    axios.get(route('desportivo.atletas.show', { athlete: selectedId }))
      .then((response) => setDetail(response.data))
      .finally(() => setDetailLoading(false));
  }, [selectedId]);

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div className="flex w-full flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold tracking-tight">Atletas</h1>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Consulta operacional canónica da participação, grupos, escalões e atividade desportiva
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.index'))}>
              <ChartBar size={15} /> Dashboard
            </Button>
            <Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.estrutura.index'))}>
              <UsersThree size={15} /> Estrutura
            </Button>
            <Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.analise.index'))}>
              <AddressBook size={15} /> Análise
            </Button>
          </div>
        </div>
      }
    >
      <Head title="Atletas · Desportivo" />

      <div className="mx-auto max-w-[1600px] space-y-3 p-3">
        <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
          <Metric label="Atletas canónicos" value={stats.total} />
          <Metric label="Ativos" value={stats.active} />
          <Metric label="Sem grupo" value={stats.without_group} />
          <Metric label="Assiduidade < 50%" value={stats.low_attendance} />
          <Metric label="Atestado pendente" value={stats.medical_pending} />
        </div>

        <Card>
          <CardContent className="p-3">
            <div className="grid gap-2 lg:grid-cols-[2fr_repeat(4,minmax(150px,1fr))_auto]">
              <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Pesquisar por nome ou nº de sócio…" />
              <Filter value={stateFilter} onChange={setStateFilter} options={filters.states} allLabel="Todos os estados" />
              <Filter value={modalityFilter} onChange={setModalityFilter} options={filters.modalities} allLabel="Todas as modalidades" />
              <Filter value={groupFilter} onChange={setGroupFilter} options={filters.groups} allLabel="Todos os grupos" />
              <Filter value={ageGroupFilter} onChange={setAgeGroupFilter} options={filters.age_groups} allLabel="Todos os escalões" />
              <div className="flex gap-1">
                <Button size="sm" variant={viewMode === 'list' ? 'default' : 'outline'} onClick={() => setViewMode('list')} title="Lista"><Rows size={16} /></Button>
                <Button size="sm" variant={viewMode === 'cards' ? 'default' : 'outline'} onClick={() => setViewMode('cards')} title="Cards"><SquaresFour size={16} /></Button>
              </div>
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_430px]">
          <div>
            {viewMode === 'list' ? (
              <div className="overflow-hidden rounded-lg border bg-background">
                <div className="hidden grid-cols-[2fr_1.2fr_1.2fr_110px_110px_90px_110px] gap-2 border-b bg-muted/40 px-3 py-2 text-[10px] font-medium uppercase tracking-wide text-muted-foreground md:grid">
                  <span>Atleta</span><span>Modalidade / grupo</span><span>Escalão</span><span>Assiduidade</span><span>Volume 30d</span><span>RPE</span><span>Estado</span>
                </div>
                {filteredAthletes.map((athlete) => (
                  <button type="button" key={athlete.id} onClick={() => setSelectedId(athlete.id)} className={`grid w-full gap-2 border-b px-3 py-3 text-left text-xs transition last:border-b-0 md:grid-cols-[2fr_1.2fr_1.2fr_110px_110px_90px_110px] ${selectedId === athlete.id ? 'bg-sky-50' : 'hover:bg-muted/40'}`}>
                    <div><div className="font-semibold">{athlete.name}</div><div className="text-[10px] text-muted-foreground">Sócio {athlete.member_number || '—'}</div></div>
                    <div><b className="md:hidden">Modalidade / grupo · </b>{labels(athlete.modalities)}<div className="text-[10px] text-muted-foreground">{labels(athlete.groups)}</div></div>
                    <div><b className="md:hidden">Escalão · </b>{labels(athlete.age_groups)}</div>
                    <div><b className="md:hidden">Assiduidade · </b>{pct(athlete.attendance_30d)}</div>
                    <div><b className="md:hidden">Volume · </b>{km(athlete.volume_30d_m)}</div>
                    <div><b className="md:hidden">RPE · </b>{numeric(athlete.avg_rpe_30d)}</div>
                    <div className="flex flex-wrap gap-1"><StateBadge state={athlete.state} /><MedicalBadge status={athlete.medical_document.status} /></div>
                  </button>
                ))}
                {filteredAthletes.length === 0 && <EmptyState />}
              </div>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {filteredAthletes.map((athlete) => (
                  <button type="button" key={athlete.id} onClick={() => setSelectedId(athlete.id)} className="text-left">
                    <Card className={selectedId === athlete.id ? 'border-sky-300 bg-sky-50/40' : ''}>
                      <CardHeader className="pb-2"><CardTitle className="text-sm">{athlete.name}</CardTitle></CardHeader>
                      <CardContent className="space-y-2 text-xs">
                        <p className="text-muted-foreground">{labels(athlete.groups)} · {labels(athlete.age_groups)}</p>
                        <div className="grid grid-cols-3 gap-1"><Mini label="Assid." value={pct(athlete.attendance_30d)} /><Mini label="Volume" value={km(athlete.volume_30d_m)} /><Mini label="RPE" value={numeric(athlete.avg_rpe_30d)} /></div>
                        <div className="flex gap-1"><StateBadge state={athlete.state} /><MedicalBadge status={athlete.medical_document.status} /></div>
                      </CardContent>
                    </Card>
                  </button>
                ))}
                {filteredAthletes.length === 0 && <EmptyState />}
              </div>
            )}
          </div>

          <Card className="h-fit xl:sticky xl:top-3">
            <CardHeader><CardTitle className="text-sm">Ficha operacional 360º</CardTitle></CardHeader>
            <CardContent>
              {!selectedId && <p className="text-xs text-muted-foreground">Seleciona um atleta para consultar o detalhe canónico.</p>}
              {selectedId && detailLoading && <p className="text-xs text-muted-foreground">A carregar detalhe…</p>}
              {detail && !detailLoading && <DetailPanel detail={detail} />}
            </CardContent>
          </Card>
        </div>

        <p className="rounded-md border bg-muted/20 p-2 text-[10px] text-muted-foreground">
          Atleta ativo é determinado por SportsAthleteParticipation. A assiduidade usa apenas atribuições reais em training_athletes. O estado do atestado é lido pelo contrato de documentos de Membros; informação clínica legacy não é interpretada nesta workspace. Participação canónica: {String(principles.canonical_participation)}.
        </p>
      </div>
    </AuthenticatedLayout>
  );
}

function DetailPanel({ detail }: { detail: Detail }) {
  const modalities = detail.sports_profile?.modalities ?? [];
  const activeModalities = modalities.filter((item: any) => item.active);
  const groups = activeModalities.flatMap((item: any) => item.groups ?? []).filter((item: any) => !item.ends_at);
  const currentSeasons = activeModalities.flatMap((item: any) => item.seasons ?? []).filter((item: any) => item.is_current);
  const analysis = detail.analysis;

  return (
    <div className="space-y-3 text-xs">
      <div>
        <h2 className="text-base font-semibold">{detail.athlete.name}</h2>
        <p className="text-muted-foreground">Sócio {detail.athlete.member_number || '—'}</p>
        <div className="mt-2 flex gap-1"><StateBadge state={detail.athlete.state} /><MedicalBadge status={detail.medical_document.status} /></div>
      </div>

      {analysis && <div className="grid grid-cols-2 gap-2"><Mini label="Assiduidade" value={pct(analysis.kpis?.attendance_rate ?? null)} /><Mini label="Volume 12 sem." value={km(Number(analysis.kpis?.volume_m ?? 0))} /><Mini label="RPE médio" value={numeric(analysis.kpis?.avg_rpe ?? null)} /><Mini label="Pódios" value={String(analysis.kpis?.podiums ?? 0)} /></div>}

      <section className="rounded-md border p-3"><b>Modalidades ativas</b><p className="mt-1 text-muted-foreground">{activeModalities.map((item: any) => item.name).join(' · ') || 'Sem modalidade ativa'}</p></section>
      <section className="rounded-md border p-3"><b>Grupos atuais</b><div className="mt-1 space-y-1">{groups.length ? groups.map((group: any) => <p key={group.id}>{group.group_name}{group.is_primary ? ' · principal' : ''}</p>) : <p className="text-muted-foreground">Sem grupo atual</p>}</div></section>
      <section className="rounded-md border p-3"><b>Escalão oficial</b><div className="mt-1 space-y-1">{currentSeasons.length ? currentSeasons.map((season: any) => <p key={season.id}>{season.name}: {season.placement?.official_age_group_name || 'Sem escalão oficial'}</p>) : <p className="text-muted-foreground">Sem perfil sazonal corrente</p>}</div></section>
      <section className="rounded-md border p-3"><b>Atestado médico</b><p className="mt-1 text-muted-foreground">{detail.medical_document.status === 'validated' ? 'Documento registado em Membros' : 'Documento pendente em Membros'}{detail.medical_document.validated_at ? ` · ${detail.medical_document.validated_at}` : ''}</p></section>
      {analysis && <section className="rounded-md border p-3"><b>Últimos resultados</b><div className="mt-1 space-y-1">{(analysis.results ?? []).slice(-4).reverse().map((result: any) => <p key={result.id}>{result.competition} · {result.race} · {result.position ? `${result.position}.º` : result.status}</p>)}</div>{(analysis.results ?? []).length === 0 && <p className="mt-1 text-muted-foreground">Sem resultados na janela de análise.</p>}</section>}
    </div>
  );
}

function Metric({ label, value }: { label: string; value: string | number }) {
  return <Card><CardContent className="p-3"><b className="block text-lg">{value}</b><span className="text-[10px] text-muted-foreground">{label}</span></CardContent></Card>;
}

function Mini({ label, value }: { label: string; value: string }) {
  return <div className="rounded-md border p-2"><b className="block text-sm">{value}</b><span className="text-[10px] text-muted-foreground">{label}</span></div>;
}

function Filter({ value, onChange, options, allLabel }: { value: string; onChange: (value: string) => void; options: Option[]; allLabel: string }) {
  return <select className="h-9 rounded-md border bg-background px-2 text-xs" value={value} onChange={(event) => onChange(event.target.value)}><option value="">{allLabel}</option>{options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select>;
}

function StateBadge({ state }: { state: 'active' | 'inactive' }) {
  return <Badge variant="outline" className={state === 'active' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-slate-300 bg-slate-50 text-slate-600'}>{state === 'active' ? 'Ativo' : 'Histórico'}</Badge>;
}

function MedicalBadge({ status }: { status: 'validated' | 'pending' }) {
  return <Badge variant="outline" className={status === 'validated' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-amber-300 bg-amber-50 text-amber-700'}>{status === 'validated' ? 'Atestado registado' : 'Atestado pendente'}</Badge>;
}

function labels(items: Array<{ name: string }>) {
  return items.map((item) => item.name).join(' · ') || '—';
}

function EmptyState() {
  return <div className="p-8 text-center text-xs text-muted-foreground">Nenhum atleta corresponde aos filtros.</div>;
}
