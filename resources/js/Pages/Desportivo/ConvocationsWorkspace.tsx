import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

type Athlete = { id: string; name: string; member_number?: string };
type EventOption = { id: string; title: string; starts_at?: string; location?: string; type?: string };
type CostCenter = { id: string; name: string };
type Race = { id: string; code?: string; name: string; distance?: number; unit?: string; modality?: string };
type Row = {
  id: string;
  event: EventOption;
  competition?: { id: string; name: string } | null;
  publication_status: string;
  publication_version: number;
  published_at?: string | null;
  meeting_time?: string | null;
  meeting_location?: string | null;
  notes?: string | null;
  cost: any;
  athletes: any[];
  stats: { total: number; confirmed: number; pending: number; declined: number; club_transport: number };
};
type Props = { convocations: Row[]; events: EventOption[]; athletes: Athlete[]; cost_centers: CostCenter[]; race_catalog: Race[] };
type Detail = Row & { publications: any[] };
type View = 'convocations' | 'responses' | 'logistics' | 'history';
type DetailTab = 'summary' | 'athletes' | 'responses' | 'logistics' | 'publication' | 'costs';
type DraftAthlete = { user_id: string; race_ids: string[]; relays: number };

const emptyDraft = {
  event_id: '',
  athletes: [] as DraftAthlete[],
  meeting_time: '',
  meeting_location: '',
  notes: '',
  cost_type: 'sem_custo',
  value_per_race: '0',
  value_per_relay: '0',
  unit_registration_value: '0',
  cost_center_id: '',
  publish_now: false,
};

const fmt = (value?: string | null) => value ? new Date(value).toLocaleDateString('pt-PT') : '—';
const eur = (value: number) => new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(value || 0);

export default function ConvocationsWorkspace({ convocations, events, athletes, cost_centers, race_catalog }: Props) {
  const [view, setView] = useState<View>('convocations');
  const [query, setQuery] = useState('');
  const [createOpen, setCreateOpen] = useState(false);
  const [step, setStep] = useState(1);
  const [draft, setDraft] = useState(emptyDraft);
  const [detail, setDetail] = useState<Detail | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [detailTab, setDetailTab] = useState<DetailTab>('summary');
  const [saving, setSaving] = useState(false);

  const filtered = useMemo(() => convocations.filter(c => c.event.title.toLowerCase().includes(query.toLowerCase())), [convocations, query]);
  const selectedEvent = events.find(e => e.id === draft.event_id);
  const selectedIds = new Set(draft.athletes.map(a => a.user_id));

  const toggleAthlete = (id: string) => setDraft(current => ({
    ...current,
    athletes: selectedIds.has(id)
      ? current.athletes.filter(a => a.user_id !== id)
      : [...current.athletes, { user_id: id, race_ids: [], relays: 0 }],
  }));

  const updateAthlete = (id: string, patch: Partial<DraftAthlete>) => setDraft(current => ({
    ...current,
    athletes: current.athletes.map(a => a.user_id === id ? { ...a, ...patch } : a),
  }));

  const openCreate = () => { setDraft(emptyDraft); setStep(1); setCreateOpen(true); };
  const openDetail = async (id: string) => {
    const { data } = await axios.get<Detail>(route('desportivo.convocatorias.show', { convocationGroup: id }));
    setDetail(data); setDetailTab('summary'); setDetailOpen(true);
  };
  const publish = async (id: string) => {
    await axios.post(route('desportivo.convocatorias.publish', { convocationGroup: id }));
    router.reload();
  };
  const save = async () => {
    if (!draft.event_id || draft.athletes.length === 0) return;
    setSaving(true);
    try {
      await axios.post(route('desportivo.convocatorias.store'), {
        ...draft,
        value_per_race: Number(draft.value_per_race),
        value_per_relay: Number(draft.value_per_relay),
        unit_registration_value: Number(draft.unit_registration_value),
        cost_center_id: draft.cost_center_id || null,
      });
      setCreateOpen(false);
      router.reload();
    } finally { setSaving(false); }
  };

  const stepTitle = ['Evento / competição', 'Atletas', 'Provas / estafetas', 'Logística / custos', 'Rever / publicar'][step - 1];

  return <AuthenticatedLayout fullWidth>
    <Head title="Convocatórias · Desportivo" />
    <div className="mx-auto max-w-[1500px] space-y-3 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div><h1 className="text-lg font-semibold">Convocatórias</h1><p className="text-xs text-muted-foreground">Seleção, publicação, respostas e logística</p></div>
        <div className="flex gap-1"><Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.competicoes'))}>Competições</Button><Button size="sm" onClick={openCreate}>+ Convocatória</Button></div>
      </div>
      <div className="flex gap-1 overflow-auto">{([['convocations', 'Convocatórias'], ['responses', 'Respostas'], ['logistics', 'Logística'], ['history', 'Histórico']] as const).map(([key, label]) => <Button key={key} size="sm" variant={view === key ? 'default' : 'outline'} onClick={() => setView(key)}>{label}</Button>)}</div>

      {view === 'convocations' && <><Input placeholder="Pesquisar evento / competição…" value={query} onChange={e => setQuery(e.target.value)} /><div className="space-y-2">{filtered.map(c => <Card key={c.id}><CardContent className="p-3"><div className="flex flex-wrap justify-between gap-2"><div><b className="text-sm">{c.event.title}</b><p className="text-[10px] text-muted-foreground">{fmt(c.event.starts_at)} · {c.event.location || '—'} · {c.event.type || '—'}</p></div><Badge variant={c.publication_status === 'published' ? 'default' : 'outline'}>{c.publication_status === 'published' ? `Publicada · v${c.publication_version}` : 'Rascunho'}</Badge></div><div className="mt-3 grid grid-cols-2 gap-2 md:grid-cols-5"><K n={c.stats.total} l="Convocados" /><K n={c.stats.confirmed} l="Confirmados" /><K n={c.stats.pending} l="Pendentes" /><K n={c.stats.declined} l="Recusaram" /><K n={c.stats.club_transport} l="Transporte clube" /></div><div className="mt-3 flex justify-end gap-1"><Button size="sm" variant="outline" onClick={() => void openDetail(c.id)}>Consultar</Button><Button size="sm" onClick={() => void publish(c.id)}>{c.publication_status === 'published' ? 'Republicar' : 'Publicar'}</Button></div></CardContent></Card>)}</div></>}
      {view === 'responses' && <FlatRows title="Respostas dos membros" rows={convocations.flatMap(c => c.athletes.map(a => ({ title: `${a.name} · ${c.event.title}`, sub: a.justification || 'Sem justificação', badge: a.response_status })))} />}
      {view === 'logistics' && <FlatRows title="Logística transversal" rows={convocations.map(c => ({ title: c.event.title, sub: `${c.meeting_time || '—'} · ${c.meeting_location || '—'}`, badge: `${c.stats.club_transport} transporte clube` }))} />}
      {view === 'history' && <FlatRows title="Estado de publicação" rows={convocations.map(c => ({ title: c.event.title, sub: c.published_at ? `Última publicação ${fmt(c.published_at)}` : 'Ainda não publicada', badge: `v${c.publication_version}` }))} />}
    </div>

    <Dialog open={detailOpen} onOpenChange={setDetailOpen}><DialogContent className="max-w-5xl"><DialogHeader><DialogTitle>Consultar Convocatória · {detail?.event.title}</DialogTitle></DialogHeader>{detail && <div className="space-y-3"><div className="grid grid-cols-2 gap-2 md:grid-cols-5"><K n={detail.stats.total} l="Convocados" /><K n={detail.stats.confirmed} l="Confirmados" /><K n={detail.stats.pending} l="Pendentes" /><K n={detail.stats.declined} l="Recusaram" /><K n={eur(detail.cost.calculated_value)} l="Custo" /></div><div className="flex gap-1 overflow-auto">{([['summary', 'Resumo'], ['athletes', 'Atletas e provas'], ['responses', 'Respostas'], ['logistics', 'Logística'], ['publication', 'Publicação'], ['costs', 'Custos']] as const).map(([key, label]) => <Button key={key} size="sm" variant={detailTab === key ? 'default' : 'outline'} onClick={() => setDetailTab(key)}>{label}</Button>)}</div><DetailBody tab={detailTab} d={detail} races={race_catalog} /></div>}<DialogFooter><Button variant="outline" onClick={() => setDetailOpen(false)}>Fechar</Button>{detail && <Button onClick={() => void publish(detail.id)}>{detail.publication_status === 'published' ? 'Republicar' : 'Publicar'}</Button>}</DialogFooter></DialogContent></Dialog>

    <Dialog open={createOpen} onOpenChange={setCreateOpen}><DialogContent className="max-w-5xl"><DialogHeader><DialogTitle>Nova Convocatória · Passo {step} de 5 · {stepTitle}</DialogTitle></DialogHeader><div className="grid gap-4 md:grid-cols-[170px_1fr]"><div className="space-y-1">{['Evento', 'Atletas', 'Provas / estafetas', 'Logística / custos', 'Rever / publicar'].map((label, index) => <div key={label} className={`rounded-md px-3 py-2 text-xs ${step === index + 1 ? 'bg-sky-50 font-semibold text-sky-800' : ''}`}>{index + 1}. {label}</div>)}</div><div className="max-h-[65vh] overflow-auto pr-1"><WizardStep step={step} draft={draft} setDraft={setDraft} events={events} athletes={athletes} selectedEvent={selectedEvent} selectedIds={selectedIds} toggleAthlete={toggleAthlete} updateAthlete={updateAthlete} costCenters={cost_centers} races={race_catalog} /></div></div><DialogFooter className="gap-1"><Button variant="outline" disabled={step === 1} onClick={() => setStep(value => Math.max(1, value - 1))}>Anterior</Button>{step < 5 ? <Button onClick={() => setStep(value => Math.min(5, value + 1))}>Seguinte</Button> : <Button disabled={saving || !draft.event_id || draft.athletes.length === 0} onClick={() => void save()}>{saving ? 'A criar…' : 'Criar convocatória'}</Button>}</DialogFooter></DialogContent></Dialog>
  </AuthenticatedLayout>;
}

function WizardStep({ step, draft, setDraft, events, athletes, selectedEvent, selectedIds, toggleAthlete, updateAthlete, costCenters, races }: any) {
  if (step === 1) return <div className="space-y-3"><p className="rounded border bg-sky-50 p-3 text-xs">Seleciona o Evento canónico. Se for projeção de uma Competição, a relação é resolvida automaticamente.</p><div><Label>Evento *</Label><select className="h-9 w-full rounded border bg-background px-2" value={draft.event_id} onChange={e => setDraft((v: any) => ({ ...v, event_id: e.target.value }))}><option value="">Selecionar…</option>{events.map((e: EventOption) => <option key={e.id} value={e.id}>{e.title} · {e.starts_at}</option>)}</select></div>{selectedEvent && <div className="grid gap-2 md:grid-cols-2"><Info l="Data" v={selectedEvent.starts_at} /><Info l="Local" v={selectedEvent.location} /><Info l="Tipo" v={selectedEvent.type} /></div>}</div>;
  if (step === 2) return <div className="space-y-2"><div className="flex justify-between"><b className="text-sm">Atletas desportivamente ativos</b><Badge variant="outline">{draft.athletes.length} selecionados</Badge></div>{athletes.map((a: Athlete) => <label key={a.id} className="flex items-center justify-between rounded border p-2 text-xs"><span><input className="mr-2" type="checkbox" checked={selectedIds.has(a.id)} onChange={() => toggleAthlete(a.id)} /><b>{a.name}</b></span><span className="text-muted-foreground">{a.member_number || '—'}</span></label>)}</div>;
  if (step === 3) return <div className="space-y-2"><p className="rounded border bg-sky-50 p-3 text-xs">Seleciona as provas configuradas para cada atleta. A seleção é gravada por ID canónico e apresentada pelo respetivo nome.</p>{draft.athletes.map((a: DraftAthlete) => { const user = athletes.find((x: Athlete) => x.id === a.user_id); return <Card key={a.user_id}><CardHeader className="pb-2"><CardTitle className="text-xs">{user?.name}</CardTitle></CardHeader><CardContent className="space-y-2"><div className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">{races.map((race: Race) => <label key={race.id} className="flex items-start gap-2 rounded border p-2 text-xs"><input type="checkbox" checked={a.race_ids.includes(race.id)} onChange={() => updateAthlete(a.user_id, { race_ids: a.race_ids.includes(race.id) ? a.race_ids.filter((id: string) => id !== race.id) : [...a.race_ids, race.id] })} /><span><b>{race.name}</b>{race.code && <small className="block text-muted-foreground">{race.code}</small>}</span></label>)}</div><div className="max-w-[180px]"><Label>N.º de estafetas</Label><Input type="number" min="0" value={a.relays} onChange={e => updateAthlete(a.user_id, { relays: Number(e.target.value) })} /></div></CardContent></Card>; })}</div>;
  if (step === 4) return <div className="grid gap-3 md:grid-cols-2"><Card><CardHeader><CardTitle className="text-sm">Logística</CardTitle></CardHeader><CardContent className="space-y-2"><div><Label>Hora de encontro</Label><Input type="time" value={draft.meeting_time} onChange={e => setDraft((v: any) => ({ ...v, meeting_time: e.target.value }))} /></div><div><Label>Local de encontro</Label><Input value={draft.meeting_location} onChange={e => setDraft((v: any) => ({ ...v, meeting_location: e.target.value }))} /></div><div><Label>Observações</Label><Textarea value={draft.notes} onChange={e => setDraft((v: any) => ({ ...v, notes: e.target.value }))} /></div></CardContent></Card><Card><CardHeader><CardTitle className="text-sm">Custos</CardTitle></CardHeader><CardContent className="space-y-2"><div><Label>Tipo de custo</Label><select className="h-9 w-full rounded border bg-background px-2" value={draft.cost_type} onChange={e => setDraft((v: any) => ({ ...v, cost_type: e.target.value }))}><option value="sem_custo">Sem custo</option><option value="por_salto">Por salto</option><option value="por_prova">Por prova</option><option value="misto">Misto</option></select></div><div className="grid grid-cols-2 gap-2"><div><Label>Valor prova/salto</Label><Input type="number" min="0" step="0.01" value={draft.value_per_race} onChange={e => setDraft((v: any) => ({ ...v, value_per_race: e.target.value }))} /></div><div><Label>Valor estafeta</Label><Input type="number" min="0" step="0.01" value={draft.value_per_relay} onChange={e => setDraft((v: any) => ({ ...v, value_per_relay: e.target.value }))} /></div></div><div><Label>Inscrição unitária</Label><Input type="number" min="0" step="0.01" value={draft.unit_registration_value} onChange={e => setDraft((v: any) => ({ ...v, unit_registration_value: e.target.value }))} /></div><div><Label>Centro de custo</Label><select className="h-9 w-full rounded border bg-background px-2" value={draft.cost_center_id} onChange={e => setDraft((v: any) => ({ ...v, cost_center_id: e.target.value }))}><option value="">Sem centro</option>{costCenters.map((c: CostCenter) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div></CardContent></Card></div>;
  return <div className="space-y-3"><p className="rounded border bg-sky-50 p-3 text-xs"><b>Validação final:</b> evento, atletas, provas/estafetas, logística e custos.</p><Card><CardContent className="space-y-1 p-3 text-xs"><p><b>Evento:</b> {selectedEvent?.title || '—'}</p><p><b>Atletas:</b> {draft.athletes.length}</p><p><b>Provas atribuídas:</b> {draft.athletes.reduce((sum: number, a: DraftAthlete) => sum + a.race_ids.length, 0)}</p><p><b>Estafetas:</b> {draft.athletes.reduce((sum: number, a: DraftAthlete) => sum + a.relays, 0)}</p><p><b>Encontro:</b> {draft.meeting_time || '—'} · {draft.meeting_location || '—'}</p><p><b>Tipo de custo:</b> {draft.cost_type}</p></CardContent></Card><label className="flex items-start gap-2 rounded border p-3 text-xs"><input type="checkbox" checked={draft.publish_now} onChange={e => setDraft((v: any) => ({ ...v, publish_now: e.target.checked }))} /><span><b>Criar e publicar já</b><br />Se desligado, a convocatória é criada como rascunho sem comunicação.</span></label></div>;
}

function DetailBody({ tab, d, races }: { tab: DetailTab; d: Detail; races: Race[] }) {
  const raceNames = new Map(races.map(r => [r.id, r.name]));
  if (tab === 'summary') return <div className="grid gap-2 md:grid-cols-2"><Card><CardContent className="p-3 text-xs"><b>Evento / competição</b><p className="mt-2">{d.event.title}</p><p>{fmt(d.event.starts_at)} · {d.event.location || '—'}</p><p>{d.competition ? `Competição: ${d.competition.name}` : 'Evento sem competição associada'}</p></CardContent></Card><Card><CardContent className="p-3 text-xs"><b>Convocatória</b><p className="mt-2">{d.stats.total} atletas</p><p>Estado: {d.publication_status} · v{d.publication_version}</p></CardContent></Card></div>;
  if (tab === 'athletes') return <div className="space-y-1">{d.athletes.map(a => <div key={a.user_id} className="rounded border p-2 text-xs"><b>{a.name}</b><p>Provas: {(a.race_ids || []).map((id: string) => raceNames.get(id) || id).join(', ') || '—'} · Estafetas: {a.relays}</p></div>)}</div>;
  if (tab === 'responses') return <div className="space-y-1">{d.athletes.map(a => <div key={a.user_id} className="flex justify-between rounded border p-2 text-xs"><div><b>{a.name}</b><p>{a.justification || 'Sem justificação'}</p><p>{a.club_transport ? 'Transporte do clube' : 'Transporte não indicado / próprio'}</p></div><Badge variant="outline">{a.response_status}</Badge></div>)}</div>;
  if (tab === 'logistics') return <Card><CardContent className="p-3 text-xs"><p><b>Encontro:</b> {d.meeting_time || '—'} · {d.meeting_location || '—'}</p><p><b>Transporte clube:</b> {d.stats.club_transport}</p><p><b>Observações:</b> {d.notes || '—'}</p></CardContent></Card>;
  if (tab === 'publication') return <div className="space-y-1">{d.publications.length ? d.publications.map(p => <div key={p.version} className="rounded border p-2 text-xs"><b>v{p.version}</b> · {fmt(p.published_at)} · {p.recipient_count} destinatários · {p.communication_status || '—'}</div>) : <p className="rounded border p-3 text-xs">Sem publicações.</p>}</div>;
  return <Card><CardContent className="p-3 text-xs"><p><b>Tipo:</b> {d.cost.type}</p><p><b>Prova/salto:</b> {eur(d.cost.value_per_race)}</p><p><b>Estafeta:</b> {eur(d.cost.value_per_relay)}</p><p><b>Inscrição unitária:</b> {eur(d.cost.unit_registration_value)}</p><p><b>Total calculado:</b> {eur(d.cost.calculated_value)}</p><p><b>Movimento:</b> {d.cost.movement_id || '—'}</p></CardContent></Card>;
}

function K({ n, l }: { n: string | number; l: string }) { return <div className="rounded border p-2"><b className="block text-base">{n}</b><span className="text-[10px] text-muted-foreground">{l}</span></div>; }
function Info({ l, v }: { l: string; v?: string }) { return <div className="rounded border p-2 text-xs"><span className="text-muted-foreground">{l}</span><b className="block">{v || '—'}</b></div>; }
function FlatRows({ title, rows }: { title: string; rows: { title: string; sub: string; badge: string }[] }) { return <Card><CardHeader><CardTitle className="text-sm">{title}</CardTitle></CardHeader><CardContent className="space-y-1">{rows.map((r, i) => <div key={`${r.title}-${i}`} className="flex justify-between rounded border p-2 text-xs"><div><b>{r.title}</b><p className="text-muted-foreground">{r.sub}</p></div><Badge variant="outline">{r.badge}</Badge></div>)}</CardContent></Card>; }
