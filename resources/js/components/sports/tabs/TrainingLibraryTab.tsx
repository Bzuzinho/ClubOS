import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { SectionTitle } from '@/components/sports/shared';

export type TrainingLibraryOption = {
  id: string;
  code?: string | null;
  codigo?: string | null;
  name?: string | null;
  nome?: string | null;
};

type Material = { id: string; code?: string | null; name: string };
type Series = {
  id?: string | null;
  repeticoes?: number | null;
  distancia_m?: number | null;
  exercicio?: string | null;
  sports_stroke_id?: string | null;
  training_zone_config_id?: string | null;
  zona_codigo?: string | null;
  intervalo?: string | null;
  saida?: string | null;
  timing_mode?: string | null;
  observacoes?: string | null;
  materials: Material[];
};
type Block = { id?: string | null; name: string; rounds: number; notes?: string | null; series: Series[] };
type Distribution = { label: string; meters: number; percent: number };

export type TrainingLibraryPlan = {
  id: string;
  nome: string;
  codigo?: string | null;
  descricao?: string | null;
  sports_modality_id?: string | null;
  modalidade?: string | null;
  tags: string[];
  estado: string;
  archived: boolean;
  autor?: string | null;
  updated_at?: string | null;
  current_version?: {
    id: string;
    version: number;
    tipo_treino?: string | null;
    descricao_treino?: string | null;
    notas_gerais?: string | null;
    volume_planeado_m?: number | null;
    instrucao?: string | null;
    blocks: Block[];
    zone_distribution: Distribution[];
    stroke_distribution: Distribution[];
    materials: Material[];
  } | null;
  versions: Array<{
    id: string;
    version: number;
    motivo_revisao?: string | null;
    created_at?: string | null;
    sessions_count: number;
  }>;
};

type Props = {
  plans: TrainingLibraryPlan[];
  modalities: TrainingLibraryOption[];
  trainingTypes: TrainingLibraryOption[];
  zones: TrainingLibraryOption[];
  strokes: TrainingLibraryOption[];
  materials: TrainingLibraryOption[];
};

type TimingMode = 'none' | 'each_rep' | 'whole_series';
type EditSeries = {
  id: string;
  repeticoes: string;
  distancia_m: string;
  exercicio: string;
  sports_stroke_id: string;
  training_zone_config_id: string;
  intervalo: string;
  saida: string;
  timing_mode: TimingMode;
  material_ids: string[];
  observacoes: string;
};
type EditBlock = { id: string; nome: string; rondas: string; notas: string; series: EditSeries[] };
type Builder = {
  nome: string;
  codigo: string;
  descricao: string;
  sports_modality_id: string;
  tags: string;
  tipo_treino: string;
  descricao_treino: string;
  notas_gerais: string;
  motivo_revisao: string;
  blocks: EditBlock[];
};

const label = (item: TrainingLibraryOption) => item.name ?? item.nome ?? item.code ?? item.codigo ?? item.id;
const code = (item: TrainingLibraryOption) => item.code ?? item.codigo ?? label(item);
const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const uid = () => crypto.randomUUID();
const createSeries = (): EditSeries => ({
  id: uid(), repeticoes: '1', distancia_m: '', exercicio: '', sports_stroke_id: '', training_zone_config_id: '',
  intervalo: '', saida: '', timing_mode: 'none', material_ids: [], observacoes: '',
});
const createBlock = (nome = 'Aquecimento'): EditBlock => ({ id: uid(), nome, rondas: '1', notas: '', series: [createSeries()] });
const emptyBuilder = (modalities: TrainingLibraryOption[], types: TrainingLibraryOption[]): Builder => ({
  nome: '', codigo: '', descricao: '', sports_modality_id: modalities[0]?.id ?? '', tags: '',
  tipo_treino: types[0] ? label(types[0]) : '', descricao_treino: '', notas_gerais: '', motivo_revisao: '',
  blocks: [createBlock()],
});

function builderFromPlan(plan: TrainingLibraryPlan, modalities: TrainingLibraryOption[], types: TrainingLibraryOption[]): Builder {
  const version = plan.current_version;
  const blocks = (version?.blocks ?? []).map((block) => ({
    id: uid(), nome: block.name, rondas: String(block.rounds || 1), notas: block.notes ?? '',
    series: block.series.map((series) => ({
      id: uid(), repeticoes: String(series.repeticoes ?? 1), distancia_m: String(series.distancia_m ?? ''),
      exercicio: series.exercicio ?? '', sports_stroke_id: series.sports_stroke_id ?? '',
      training_zone_config_id: series.training_zone_config_id ?? '', intervalo: series.intervalo ?? '', saida: series.saida ?? '',
      timing_mode: (series.timing_mode as TimingMode) || 'none', material_ids: (series.materials ?? []).map((item) => item.id),
      observacoes: series.observacoes ?? '',
    })),
  })).filter((block) => block.series.length > 0);

  return {
    nome: plan.nome, codigo: plan.codigo ?? '', descricao: plan.descricao ?? '',
    sports_modality_id: plan.sports_modality_id ?? modalities[0]?.id ?? '', tags: (plan.tags ?? []).join(', '),
    tipo_treino: version?.tipo_treino ?? (types[0] ? label(types[0]) : ''),
    descricao_treino: version?.descricao_treino ?? '', notas_gerais: version?.notas_gerais ?? '', motivo_revisao: '',
    blocks: blocks.length ? blocks : [createBlock('Treino')],
  };
}

function parseLine(line: string, zones: TrainingLibraryOption[], strokes: TrainingLibraryOption[]): EditSeries | null {
  const match = line.trim().match(/^(\d+)\s*[x×]\s*(\d+)\s*(.*)$/i);
  if (!match) return null;

  let tail = match[3].trim();
  const zoneMatch = tail.match(/\bZ\s*(\d+)\b/i);
  const zone = zoneMatch ? zones.find((item) => normalize(code(item)) === normalize(`Z${zoneMatch[1]}`)) : undefined;
  const sendOff = tail.match(/@\s*([0-9:.'"]+)/)?.[1] ?? '';
  const rest = tail.match(/(?:c\/|desc\.?\s*)([0-9:.'"]+)/i)?.[1] ?? '';
  tail = tail.replace(/\bZ\s*\d+\b/i, '').replace(/@\s*[0-9:.'"]+/, '').replace(/(?:c\/|desc\.?\s*)[0-9:.'"]+/i, '').trim();

  const aliases: Record<string, string> = { L: 'LIVRE', C: 'COSTAS', B: 'BRUCOS', M: 'MARIPOSA', E: 'ESTILOS' };
  const token = tail.split(/\s+/)[0]?.toUpperCase() ?? '';
  const alias = aliases[token];
  const stroke = alias
    ? strokes.find((item) => normalize(code(item)) === normalize(alias))
    : strokes.find((item) => normalize(tail).includes(normalize(label(item))));
  if (alias) tail = tail.replace(/^\S+\s*/, '');

  return {
    ...createSeries(), repeticoes: match[1], distancia_m: match[2], exercicio: tail || (stroke ? label(stroke) : ''),
    sports_stroke_id: stroke?.id ?? '', training_zone_config_id: zone?.id ?? '', saida: sendOff, intervalo: rest,
    timing_mode: Number(match[1]) > 1 ? 'each_rep' : 'none',
  };
}

function parseQuick(text: string, zones: TrainingLibraryOption[], strokes: TrainingLibraryOption[]): EditBlock[] {
  const blocks: EditBlock[] = [];
  let current: EditBlock = { ...createBlock('Treino'), series: [] };
  const flush = () => { if (current.series.length) blocks.push(current); };

  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line) continue;
    const circuit = line.match(/^(\d+)\s*[x×]\s*\[(.+)\]$/);
    if (circuit) {
      const heading = current.series.length === 0 && current.nome !== 'Treino' ? current.nome : 'Circuito';
      flush();
      current = {
        id: uid(), nome: heading, rondas: circuit[1], notas: '',
        series: circuit[2].split(/\s*\+\s*/).map((part) => parseLine(part, zones, strokes)).filter((part): part is EditSeries => !!part),
      };
      continue;
    }
    const parsed = parseLine(line, zones, strokes);
    if (parsed) current.series.push(parsed);
    else { flush(); current = { id: uid(), nome: line.replace(/:$/, ''), rondas: '1', notas: '', series: [] }; }
  }
  flush();
  return blocks.length ? blocks : [createBlock()];
}

export function TrainingLibraryTab({ plans, modalities, trainingTypes, zones, strokes, materials }: Props) {
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('active');
  const [type, setType] = useState('all');
  const [view, setView] = useState<'list' | 'cards'>('list');
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<'structured' | 'quick'>('structured');
  const [quick, setQuick] = useState('');
  const [editing, setEditing] = useState<TrainingLibraryPlan | null>(null);
  const [history, setHistory] = useState<TrainingLibraryPlan | null>(null);
  const [builder, setBuilder] = useState<Builder>(() => emptyBuilder(modalities, trainingTypes));

  const filtered = useMemo(() => plans.filter((plan) => {
    if (status === 'active' && plan.archived) return false;
    if (status === 'archived' && !plan.archived) return false;
    if (status === 'draft' && plan.estado !== 'draft') return false;
    if (status === 'published' && plan.estado !== 'published') return false;
    if (type !== 'all' && plan.current_version?.tipo_treino !== type) return false;
    const needle = normalize(query.trim());
    if (!needle) return true;
    return normalize([
      plan.nome, plan.codigo, plan.modalidade, plan.current_version?.tipo_treino,
      ...(plan.tags ?? []), ...(plan.current_version?.materials ?? []).map((item) => item.name),
      ...(plan.current_version?.zone_distribution ?? []).map((item) => item.label),
      ...(plan.current_version?.stroke_distribution ?? []).map((item) => item.label),
    ].filter(Boolean).join(' ')).includes(needle);
  }), [plans, query, status, type]);

  const volume = useMemo(() => builder.blocks.reduce((total, block) => total +
    block.series.reduce((subtotal, series) => subtotal + (Number(series.repeticoes) || 0) * (Number(series.distancia_m) || 0), 0) * Math.max(1, Number(block.rondas) || 1), 0),
  [builder.blocks]);

  const start = (plan?: TrainingLibraryPlan) => {
    setEditing(plan ?? null);
    setBuilder(plan ? builderFromPlan(plan, modalities, trainingTypes) : emptyBuilder(modalities, trainingTypes));
    setQuick('AQUECIMENTO\n1x400 L Z1\n4x50 técnica c/15"\n\nPRINCIPAL\n3x [4x100 L Z4 @1:30 + 4x50 L Z5 @0:45]\n\nRECUPERAÇÃO\n1x200 L Z1');
    setMode('structured');
    setOpen(true);
  };

  const patchBlock = (id: string, patch: Partial<EditBlock>) => setBuilder((value) => ({
    ...value, blocks: value.blocks.map((block) => block.id === id ? { ...block, ...patch } : block),
  }));
  const patchSeries = (blockId: string, seriesId: string, patch: Partial<EditSeries>) => setBuilder((value) => ({
    ...value,
    blocks: value.blocks.map((block) => block.id !== blockId ? block : {
      ...block, series: block.series.map((series) => series.id === seriesId ? { ...series, ...patch } : series),
    }),
  }));

  const save = (publish: boolean) => {
    const data = {
      nome: builder.nome, codigo: builder.codigo || null, descricao: builder.descricao || null,
      sports_modality_id: builder.sports_modality_id || null,
      tags: builder.tags.split(',').map((value) => value.trim()).filter(Boolean),
      estado: publish ? 'published' : 'draft', publicar: publish,
      tipo_treino: builder.tipo_treino || null, descricao_treino: builder.descricao_treino || null,
      notas_gerais: builder.notas_gerais || null, motivo_revisao: builder.motivo_revisao || null,
      blocks: builder.blocks.map((block) => ({
        nome: block.nome, rondas: Math.max(1, Number(block.rondas) || 1), notas: block.notas || null,
        series: block.series.map((series) => ({
          repeticoes: Math.max(1, Number(series.repeticoes) || 1), distancia_m: Number(series.distancia_m) || null,
          exercicio: series.exercicio || null, sports_stroke_id: series.sports_stroke_id || null,
          training_zone_config_id: series.training_zone_config_id || null, intervalo: series.intervalo || null,
          saida: series.saida || null, timing_mode: series.timing_mode, material_ids: series.material_ids,
          observacoes: series.observacoes || null,
        })),
      })),
    };
    const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
    if (editing) router.put(route('desportivo.biblioteca.planos.revise', editing.id), data, options);
    else router.post(route('desportivo.biblioteca.planos.store'), data, options);
  };

  const archive = (plan: TrainingLibraryPlan) => {
    if (window.confirm(`Arquivar o plano “${plan.nome}”? O histórico e as sessões permanecem intactos.`)) {
      router.delete(route('desportivo.biblioteca.planos.archive', plan.id), { preserveScroll: true });
    }
  };

  return <div className="space-y-3">
    <SectionTitle title="Biblioteca" subtitle="Planos reutilizáveis e versionados. As sessões concretas vivem exclusivamente em Treinos." />

    <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
      <Input className="h-9 lg:max-w-md" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Pesquisar nome, zona, estilo, material ou tag…" />
      <Select value={status} onValueChange={setStatus}><SelectTrigger className="h-9 lg:w-44"><SelectValue /></SelectTrigger><SelectContent>
        <SelectItem value="active">Ativos</SelectItem><SelectItem value="published">Publicados</SelectItem><SelectItem value="draft">Rascunhos</SelectItem><SelectItem value="archived">Arquivados</SelectItem><SelectItem value="all">Todos</SelectItem>
      </SelectContent></Select>
      <Select value={type} onValueChange={setType}><SelectTrigger className="h-9 lg:w-52"><SelectValue placeholder="Todos os tipos" /></SelectTrigger><SelectContent>
        <SelectItem value="all">Todos os tipos</SelectItem>{trainingTypes.map((item) => <SelectItem key={item.id} value={label(item)}>{label(item)}</SelectItem>)}
      </SelectContent></Select>
      <div className="flex gap-1 lg:ml-auto">
        <Button variant="outline" size="sm" className="h-9" onClick={() => setView(view === 'list' ? 'cards' : 'list')}>{view === 'list' ? 'Cards' : 'Lista'}</Button>
        <Button size="sm" className="h-9" onClick={() => start()}>+ Novo plano</Button>
      </div>
    </div>

    {view === 'list' ? <Card><CardContent className="overflow-x-auto p-0">
      <table className="w-full min-w-[900px] text-xs"><thead className="border-b bg-muted/30 text-muted-foreground"><tr>
        {['Plano', 'Tipo / foco', 'Volume', 'Composição', 'Versão', 'Estado', ''].map((item) => <th key={item} className="px-3 py-2 text-left font-medium">{item}</th>)}
      </tr></thead><tbody>{filtered.map((plan) => <tr key={plan.id} className="border-b last:border-0">
        <td className="px-3 py-2.5"><button className="font-semibold hover:underline" onClick={() => start(plan)}>{plan.nome}</button><div className="text-[11px] text-muted-foreground">{plan.modalidade || 'Sem modalidade'}{plan.codigo ? ` · ${plan.codigo}` : ''}</div></td>
        <td className="px-3 py-2.5">{plan.current_version?.tipo_treino || '—'}<div className="mt-1 flex gap-1">{(plan.current_version?.zone_distribution ?? []).slice(0, 2).map((row) => <Badge key={row.label} variant="outline" className="text-[10px]">{row.label} {row.percent}%</Badge>)}</div></td>
        <td className="px-3 py-2.5 font-medium">{(plan.current_version?.volume_planeado_m ?? 0).toLocaleString('pt-PT')} m</td>
        <td className="px-3 py-2.5"><div className="flex max-w-[260px] flex-wrap gap-1">{(plan.current_version?.stroke_distribution ?? []).slice(0, 2).map((row) => <Badge key={row.label} variant="secondary" className="text-[10px]">{row.label} {row.percent}%</Badge>)}{(plan.current_version?.materials ?? []).slice(0, 2).map((material) => <Badge key={material.id} variant="outline" className="text-[10px]">{material.name}</Badge>)}</div></td>
        <td className="px-3 py-2.5">v{plan.current_version?.version ?? '—'}</td>
        <td className="px-3 py-2.5"><Badge variant={plan.archived ? 'secondary' : 'outline'}>{plan.archived ? 'Arquivado' : plan.estado === 'published' ? 'Publicado' : 'Rascunho'}</Badge></td>
        <td className="px-3 py-2.5"><div className="flex justify-end gap-1">
          {!plan.archived && <Button variant="outline" size="sm" className="h-7" onClick={() => start(plan)}>Abrir</Button>}
          <Button variant="ghost" size="sm" className="h-7" onClick={() => setHistory(plan)}>Versões</Button>
          {!plan.archived && <Button variant="ghost" size="sm" className="h-7" onClick={() => router.post(route('desportivo.biblioteca.planos.duplicate', plan.id), {}, { preserveScroll: true })}>Duplicar</Button>}
          {plan.archived ? <Button variant="outline" size="sm" className="h-7" onClick={() => router.post(route('desportivo.biblioteca.planos.restore', plan.id), {}, { preserveScroll: true })}>Reativar</Button>
            : <Button variant="ghost" size="sm" className="h-7" onClick={() => archive(plan)}>Arquivar</Button>}
        </div></td>
      </tr>)}</tbody></table>
      {!filtered.length && <p className="p-5 text-center text-xs text-muted-foreground">Nenhum plano corresponde aos filtros.</p>}
    </CardContent></Card> : <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{filtered.map((plan) => <Card key={plan.id} className="cursor-pointer transition hover:shadow-sm" onClick={() => !plan.archived && start(plan)}><CardContent className="p-3">
      <div className="flex justify-between gap-2"><div><p className="text-sm font-semibold">{plan.nome}</p><p className="text-[11px] text-muted-foreground">{plan.modalidade || 'Sem modalidade'} · v{plan.current_version?.version ?? '—'}</p></div><Badge variant="outline">{plan.archived ? 'Arquivado' : plan.estado === 'published' ? 'Publicado' : 'Rascunho'}</Badge></div>
      <div className="mt-3 grid grid-cols-3 gap-1.5"><div className="rounded border p-2"><b>{(plan.current_version?.volume_planeado_m ?? 0).toLocaleString('pt-PT')}</b><span className="block text-[10px] text-muted-foreground">metros</span></div><div className="rounded border p-2"><b>{plan.current_version?.blocks.length ?? 0}</b><span className="block text-[10px] text-muted-foreground">blocos</span></div><div className="rounded border p-2"><b>{plan.versions.length}</b><span className="block text-[10px] text-muted-foreground">versões</span></div></div>
    </CardContent></Card>)}</div>}

    <Dialog open={open} onOpenChange={setOpen}><DialogContent className="h-[96vh] w-[98vw] max-w-[1600px] overflow-hidden p-0">
      <DialogHeader className="border-b px-4 py-3"><div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between"><div><DialogTitle>{editing ? `Editar ${editing.nome}` : 'Novo plano de treino'}</DialogTitle><p className="mt-1 text-xs text-muted-foreground">Alterar conteúdo cria uma nova versão; sessões existentes não são reescritas.</p></div><div className="flex gap-1"><Button size="sm" variant={mode === 'quick' ? 'default' : 'outline'} onClick={() => setMode('quick')}>Escrita rápida</Button><Button size="sm" variant={mode === 'structured' ? 'default' : 'outline'} onClick={() => setMode('structured')}>Estruturado</Button></div></div></DialogHeader>
      <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden xl:grid-cols-[minmax(0,1fr)_300px]">
        <div className="min-h-0 overflow-y-auto p-4">
          <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
            <div><Label>Nome</Label><Input value={builder.nome} onChange={(event) => setBuilder({ ...builder, nome: event.target.value })} /></div>
            <div><Label>Modalidade</Label><Select value={builder.sports_modality_id || 'none'} onValueChange={(value) => setBuilder({ ...builder, sports_modality_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Sem modalidade</SelectItem>{modalities.map((item) => <SelectItem key={item.id} value={item.id}>{label(item)}</SelectItem>)}</SelectContent></Select></div>
            <div><Label>Tipo</Label><Select value={builder.tipo_treino || 'none'} onValueChange={(value) => setBuilder({ ...builder, tipo_treino: value === 'none' ? '' : value })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Sem tipo</SelectItem>{trainingTypes.map((item) => <SelectItem key={item.id} value={label(item)}>{label(item)}</SelectItem>)}</SelectContent></Select></div>
            <div><Label>Tags</Label><Input value={builder.tags} onChange={(event) => setBuilder({ ...builder, tags: event.target.value })} placeholder="velocidade, taper…" /></div>
          </div>

          {mode === 'quick' ? <div className="mt-4 space-y-2">
            <textarea className="min-h-[440px] w-full rounded-md border bg-background p-3 font-mono text-sm leading-6" value={quick} onChange={(event) => setQuick(event.target.value)} />
            <p className="text-xs text-muted-foreground">Ex.: <code>8x50 L Z4 @0:45</code> (saída), <code>8x50 L Z4 c/15&quot;</code> (descanso) ou <code>3x [4x100 L Z4 @1:30 + 4x50 L Z5 @0:45]</code>.</p>
            <Button onClick={() => { setBuilder((value) => ({ ...value, blocks: parseQuick(quick, zones, strokes) })); setMode('structured'); }}>Interpretar e estruturar</Button>
          </div> : <div className="mt-4 space-y-2">
            {builder.blocks.map((block, blockIndex) => <Card key={block.id}><CardContent className="p-3">
              <div className="flex gap-2"><div className="flex-1"><Label>Bloco {blockIndex + 1}</Label><Input value={block.nome} onChange={(event) => patchBlock(block.id, { nome: event.target.value })} /></div><div className="w-24"><Label>Rondas</Label><Input type="number" min={1} max={99} value={block.rondas} onChange={(event) => patchBlock(block.id, { rondas: event.target.value })} /></div><Button variant="ghost" size="sm" className="mt-5" onClick={() => setBuilder((value) => ({ ...value, blocks: value.blocks.filter((item) => item.id !== block.id) }))}>Remover</Button></div>
              <div className="mt-3 space-y-1.5">{block.series.map((series, seriesIndex) => <div key={series.id} className="rounded-md border p-2">
                <div className="grid gap-1.5 md:grid-cols-[58px_74px_minmax(130px,1fr)_110px_100px_110px_72px]">
                  <Input aria-label={`Repetições ${seriesIndex + 1}`} type="number" min={1} value={series.repeticoes} onChange={(event) => patchSeries(block.id, series.id, { repeticoes: event.target.value })} />
                  <Input aria-label="Distância" type="number" min={1} value={series.distancia_m} onChange={(event) => patchSeries(block.id, series.id, { distancia_m: event.target.value })} placeholder="m" />
                  <Input aria-label="Exercício" value={series.exercicio} onChange={(event) => patchSeries(block.id, series.id, { exercicio: event.target.value })} placeholder="Exercício" />
                  <Select value={series.sports_stroke_id || 'none'} onValueChange={(value) => patchSeries(block.id, series.id, { sports_stroke_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue placeholder="Estilo" /></SelectTrigger><SelectContent><SelectItem value="none">Sem estilo</SelectItem>{strokes.map((item) => <SelectItem key={item.id} value={item.id}>{label(item)}</SelectItem>)}</SelectContent></Select>
                  <Select value={series.training_zone_config_id || 'none'} onValueChange={(value) => patchSeries(block.id, series.id, { training_zone_config_id: value === 'none' ? '' : value })}><SelectTrigger><SelectValue placeholder="Zona" /></SelectTrigger><SelectContent><SelectItem value="none">Sem zona</SelectItem>{zones.map((item) => <SelectItem key={item.id} value={item.id}>{code(item)}</SelectItem>)}</SelectContent></Select>
                  <Select value={series.timing_mode} onValueChange={(value) => patchSeries(block.id, series.id, { timing_mode: value as TimingMode })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="none">Sem tempo</SelectItem><SelectItem value="each_rep">Cada repetição</SelectItem><SelectItem value="whole_series">Série completa</SelectItem></SelectContent></Select>
                  <Button variant="ghost" size="sm" onClick={() => patchBlock(block.id, { series: block.series.filter((item) => item.id !== series.id) })}>Remover</Button>
                </div>
                <details className="mt-2"><summary className="cursor-pointer text-[11px] text-muted-foreground">Saída, descanso, material e notas</summary>
                  <div className="mt-2 grid gap-2 md:grid-cols-3"><Input value={series.saida} onChange={(event) => patchSeries(block.id, series.id, { saida: event.target.value })} placeholder="Saída @1:30" /><Input value={series.intervalo} onChange={(event) => patchSeries(block.id, series.id, { intervalo: event.target.value })} placeholder={'Descanso 15"'} /><Input value={series.observacoes} onChange={(event) => patchSeries(block.id, series.id, { observacoes: event.target.value })} placeholder="Observações" /></div>
                  <div className="mt-2 flex flex-wrap gap-1">{materials.map((material) => { const selected = series.material_ids.includes(material.id); return <Button key={material.id} type="button" variant={selected ? 'default' : 'outline'} size="sm" className="h-7 text-[11px]" onClick={() => patchSeries(block.id, series.id, { material_ids: selected ? series.material_ids.filter((id) => id !== material.id) : [...series.material_ids, material.id] })}>{label(material)}</Button>; })}</div>
                </details>
              </div>)}<Button variant="outline" size="sm" onClick={() => patchBlock(block.id, { series: [...block.series, createSeries()] })}>+ Série</Button></div>
            </CardContent></Card>)}
            <Button variant="outline" onClick={() => setBuilder((value) => ({ ...value, blocks: [...value.blocks, createBlock(`Bloco ${value.blocks.length + 1}`)] }))}>+ Adicionar bloco</Button>
          </div>}

          <div className="mt-4 grid gap-2 md:grid-cols-2"><div><Label>Descrição técnica</Label><textarea className="min-h-20 w-full rounded-md border bg-background p-2 text-sm" value={builder.descricao_treino} onChange={(event) => setBuilder({ ...builder, descricao_treino: event.target.value })} /></div><div><Label>Notas gerais</Label><textarea className="min-h-20 w-full rounded-md border bg-background p-2 text-sm" value={builder.notas_gerais} onChange={(event) => setBuilder({ ...builder, notas_gerais: event.target.value })} /></div></div>
          {editing && <div className="mt-2"><Label>Motivo da revisão</Label><Input value={builder.motivo_revisao} onChange={(event) => setBuilder({ ...builder, motivo_revisao: event.target.value })} /></div>}
        </div>
        <aside className="overflow-y-auto border-t bg-muted/20 p-4 xl:border-l xl:border-t-0"><p className="text-sm font-semibold">Resumo</p><div className="mt-2 grid grid-cols-2 gap-2"><div className="rounded border bg-background p-2"><b className="block text-lg">{volume.toLocaleString('pt-PT')}</b><span className="text-[11px] text-muted-foreground">metros</span></div><div className="rounded border bg-background p-2"><b className="block text-lg">{builder.blocks.length}</b><span className="text-[11px] text-muted-foreground">blocos</span></div></div><div className="mt-3 rounded border bg-background p-3"><b className="text-xs">Preparado para Live</b><p className="mt-1 text-[11px] text-muted-foreground">8×50 preserva 8 repetições de 50 m; com “Cada repetição”, cada 50 m é uma unidade de cronometragem.</p></div><div className="mt-3 rounded border bg-background p-3"><b className="text-xs">Material técnico</b><p className="mt-1 text-[11px] text-muted-foreground">Não consulta nem altera stock. Inventário continua propriedade da Logística.</p></div></aside>
      </div>
      <div className="flex justify-end gap-2 border-t p-3"><Button variant="outline" onClick={() => save(false)}>Guardar rascunho</Button><Button onClick={() => save(true)}>Publicar nova versão</Button></div>
    </DialogContent></Dialog>

    <Dialog open={!!history} onOpenChange={(value) => !value && setHistory(null)}><DialogContent className="max-w-2xl"><DialogHeader><DialogTitle>Histórico · {history?.nome}</DialogTitle></DialogHeader><div className="max-h-[65vh] overflow-y-auto">{(history?.versions ?? []).map((version) => <div key={version.id} className="flex justify-between gap-3 border-b py-2"><div><b>v{version.version}</b><p className="text-xs text-muted-foreground">{version.motivo_revisao || 'Sem motivo registado'}</p></div><div className="text-right text-[11px] text-muted-foreground">{version.sessions_count} sessões<br />{version.created_at ? new Date(version.created_at).toLocaleString('pt-PT') : '—'}</div></div>)}</div></DialogContent></Dialog>
  </div>;
}
