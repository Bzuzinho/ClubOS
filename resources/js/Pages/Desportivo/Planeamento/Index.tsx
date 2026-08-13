import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { CalendarBlank, GearSix, PencilSimple, Plus, Trash } from '@phosphor-icons/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';

type Id = string;
interface Person { id: Id; name: string }
interface AgeGroup { id: Id; nome: string }
interface Season { id: Id; nome: string; ano_temporada: string; data_inicio: string; data_fim: string; status: string; sports_modality_id: Id; modality?: { name: string } }
interface Micro { id: Id; mesociclo_id: Id; semana: string; data_inicio?: string | null; data_fim?: string | null; volume_previsto?: number | null; objetivo_principal?: string | null; objetivo_secundario?: string | null; is_recovery_week: boolean; active: boolean; notas?: string | null }
interface Meso { id: Id; macrociclo_id: Id; nome: string; data_inicio: string; data_fim: string; objetivo_principal?: string | null; objetivo_secundario?: string | null; active: boolean; microcycles?: Micro[] }
interface Macro { id: Id; epoca_id: Id; nome: string; tipo: string; data_inicio: string; data_fim: string; objetivo_principal?: string | null; objetivo_secundario?: string | null; active: boolean; mesocycles?: Meso[] }
interface Lane { id: Id; lane_number?: number | null; name?: string | null; capacity?: number | null; active: boolean; pivot?: { planned_capacity?: number | null } }
interface Pool { id: Id; sports_venue_id: Id; name: string; code: string; active: boolean; lanes?: Lane[] }
interface Venue { id: Id; name: string; code: string; active: boolean; pools?: Pool[] }
interface Group { id: Id; name: string; code: string }
interface PlanVersion { id: Id; version: number; nome_snapshot?: string | null; plan?: { nome: string } }
interface SessionGroup { training_group_id: Id; training_plan_version_id?: Id | null; instruction?: string | null; group?: Group; lanes?: Lane[] }
interface Session { id: Id; numero_treino?: string | null; data: string; hora_inicio?: string | null; hora_fim?: string | null; tipo_treino?: string | null; session_status: string; schedule_review_required: boolean; schedule_conflicts_snapshot?: any[] | null; athlete_records_count?: number; athlete_records?: Array<{ user_id: Id }>; microciclo_id?: Id | null; sports_pool_id?: Id | null; responsavel_id?: Id | null; training_plan_version_id?: Id | null; instrucao?: string | null; venue?: Venue | null; pool?: Pool | null; session_groups?: SessionGroup[] }
interface RecurrenceGroup { training_group_id: Id; training_plan_version_id?: Id | null; instruction?: string | null; group?: Group; lanes?: Lane[] }
interface Recurrence { id: Id; name: string; starts_on: string; ends_on?: string | null; frequency: string; interval: number; weekdays?: number[]; start_time: string; end_time: string; active: boolean; last_generated_until?: string | null; microcycle_id?: Id | null; sports_pool_id?: Id | null; responsavel_id?: Id | null; training_plan_version_id?: Id | null; instruction?: string | null; training_type?: string | null; session_status_template: string; groups?: RecurrenceGroup[] }
interface Objective { id: Id; target_type: string; target_id?: Id | null; due_at?: string | null; latest_version?: { title: string; description?: string | null; objective_type: string; target_value?: string | number | null; target_text?: string | null; target_unit?: string | null } }
interface Competition { id: Id; nome: string; data_inicio: string; local?: string | null; status: string }
interface Props { seasons: Season[]; selectedSeason?: Season | null; macrocycles: Macro[]; sessions: Session[]; recurrences: Recurrence[]; groups: Group[]; athletes: Person[]; coaches: Person[]; locations: Venue[]; planVersions: PlanVersion[]; objectives: Objective[]; competitions: Competition[]; ageGroups: AgeGroup[] }

type Kind = 'macro' | 'meso' | 'micro' | 'session' | 'recurrence' | 'objective';
type AssignmentDraft = { training_group_id: string; training_plan_version_id: string; instruction: string; lane_ids: string[] };
const selectClass = 'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';
const textareaClass = 'min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm';
const dateOnly = (value?: string | null) => (value ?? '').slice(0, 10);
const timeOnly = (value?: string | null) => (value ?? '').slice(0, 5);
const weekdays: Array<[number, string]> = [[1, 'Seg'], [2, 'Ter'], [3, 'Qua'], [4, 'Qui'], [5, 'Sex'], [6, 'Sáb'], [7, 'Dom']];

export default function PlanningWorkspace(props: Props) {
  const { seasons, selectedSeason, macrocycles, sessions, recurrences, groups, athletes, coaches, locations, planVersions, objectives, competitions } = props;
  const [tab, setTab] = useState('periodizacao');
  const [dialog, setDialog] = useState<{ kind: Kind; item?: any; parentId?: string } | null>(null);
  const [form, setForm] = useState<Record<string, any>>({});
  const [assignments, setAssignments] = useState<AssignmentDraft[]>([]);
  const seasonId = selectedSeason?.id ?? '';

  const allMesos = useMemo(() => macrocycles.flatMap((macro) => macro.mesocycles ?? []), [macrocycles]);
  const allMicros = useMemo(() => allMesos.flatMap((meso) => meso.microcycles ?? []), [allMesos]);
  const pools = useMemo(() => locations.flatMap((venue) => (venue.pools ?? []).map((pool) => ({ ...pool, venue }))), [locations]);
  const selectedPool = pools.find((pool) => pool.id === form.sports_pool_id);
  const availableLanes = selectedPool?.lanes ?? [];

  const stats = {
    macros: macrocycles.filter((item) => item.active).length,
    micros: allMicros.filter((item) => item.active).length,
    sessions: sessions.length,
    review: sessions.filter((item) => item.schedule_review_required).length,
  };

  const set = (key: string, value: any) => setForm((current) => ({ ...current, [key]: value }));
  const emptyAssignment = (): AssignmentDraft => ({ training_group_id: '', training_plan_version_id: '', instruction: '', lane_ids: [] });
  const mapAssignments = (rows: Array<SessionGroup | RecurrenceGroup> = []): AssignmentDraft[] => rows.map((row) => ({
    training_group_id: row.training_group_id,
    training_plan_version_id: row.training_plan_version_id ?? '',
    instruction: row.instruction ?? '',
    lane_ids: (row.lanes ?? []).map((lane) => lane.id),
  }));

  const open = (kind: Kind, item?: any, parentId?: string) => {
    const selectedMicroId = item?.microciclo_id ?? item?.microcycle_id ?? parentId ?? allMicros.find((micro) => micro.active)?.id ?? '';
    const selectedMicro = allMicros.find((micro) => micro.id === selectedMicroId);
    let values: Record<string, any> = { season_id: seasonId };
    setAssignments([]);

    if (kind === 'macro') {
      values = {
        epoca_id: seasonId,
        nome: item?.nome ?? '',
        tipo: item?.tipo ?? 'Preparação geral',
        data_inicio: dateOnly(item?.data_inicio) || dateOnly(selectedSeason?.data_inicio),
        data_fim: dateOnly(item?.data_fim) || dateOnly(selectedSeason?.data_fim),
        objetivo_principal: item?.objetivo_principal ?? '',
        objetivo_secundario: item?.objetivo_secundario ?? '',
      };
    }

    if (kind === 'meso') {
      const parent = macrocycles.find((macro) => macro.id === (item?.macrociclo_id ?? parentId));
      values = {
        season_id: seasonId,
        macrociclo_id: item?.macrociclo_id ?? parentId ?? '',
        nome: item?.nome ?? '',
        data_inicio: dateOnly(item?.data_inicio) || dateOnly(parent?.data_inicio),
        data_fim: dateOnly(item?.data_fim) || dateOnly(parent?.data_fim),
        objetivo_principal: item?.objetivo_principal ?? '',
        objetivo_secundario: item?.objetivo_secundario ?? '',
      };
    }

    if (kind === 'micro') {
      const parent = allMesos.find((meso) => meso.id === (item?.mesociclo_id ?? parentId));
      values = {
        season_id: seasonId,
        mesociclo_id: item?.mesociclo_id ?? parentId ?? '',
        semana: item?.semana ?? '',
        data_inicio: dateOnly(item?.data_inicio) || dateOnly(parent?.data_inicio),
        data_fim: dateOnly(item?.data_fim) || dateOnly(parent?.data_fim),
        volume_previsto: item?.volume_previsto ?? '',
        objetivo_principal: item?.objetivo_principal ?? '',
        objetivo_secundario: item?.objetivo_secundario ?? '',
        is_recovery_week: item?.is_recovery_week ?? false,
        notas: item?.notas ?? '',
      };
    }

    if (kind === 'session') {
      values = {
        season_id: seasonId,
        microciclo_id: selectedMicroId,
        data: dateOnly(item?.data) || dateOnly(selectedMicro?.data_inicio),
        hora_inicio: timeOnly(item?.hora_inicio) || '18:00',
        hora_fim: timeOnly(item?.hora_fim) || '19:30',
        sports_pool_id: item?.sports_pool_id ?? '',
        responsavel_id: item?.responsavel_id ?? '',
        training_plan_version_id: item?.training_plan_version_id ?? '',
        tipo_treino: item?.tipo_treino ?? '',
        instrucao: item?.instrucao ?? '',
        session_status: item?.session_status ?? 'draft',
        volume_planeado_m: item?.volume_planeado_m ?? '',
        athlete_ids: (item?.athlete_records ?? []).map((row: { user_id: string }) => row.user_id),
      };
      setAssignments(mapAssignments(item?.session_groups ?? []));
    }

    if (kind === 'recurrence') {
      values = {
        season_id: seasonId,
        microcycle_id: selectedMicroId,
        name: item?.name ?? '',
        starts_on: dateOnly(item?.starts_on) || dateOnly(selectedMicro?.data_inicio),
        ends_on: dateOnly(item?.ends_on) || dateOnly(selectedMicro?.data_fim),
        frequency: item?.frequency ?? 'weekly',
        interval: item?.interval ?? 1,
        weekdays: item?.weekdays ?? [],
        start_time: timeOnly(item?.start_time) || '18:00',
        end_time: timeOnly(item?.end_time) || '19:30',
        sports_pool_id: item?.sports_pool_id ?? '',
        responsavel_id: item?.responsavel_id ?? '',
        training_plan_version_id: item?.training_plan_version_id ?? '',
        instruction: item?.instruction ?? '',
        training_type: item?.training_type ?? '',
        session_status_template: item?.session_status_template ?? 'draft',
      };
      setAssignments(mapAssignments(item?.groups ?? []));
    }

    if (kind === 'objective') {
      values = {
        season_id: seasonId,
        target_type: item?.target_type ?? 'season',
        target_id: item?.target_id ?? seasonId,
        title: item?.latest_version?.title ?? '',
        description: item?.latest_version?.description ?? '',
        objective_type: item?.latest_version?.objective_type ?? 'text',
        target_value: item?.latest_version?.target_value ?? '',
        target_text: item?.latest_version?.target_text ?? '',
        target_unit: item?.latest_version?.target_unit ?? '',
        due_at: dateOnly(item?.due_at),
      };
    }

    setForm(values);
    setDialog({ kind, item, parentId });
  };

  const mappedAssignments = () => assignments
    .filter((assignment) => assignment.training_group_id)
    .map((assignment, index) => ({
      training_group_id: assignment.training_group_id,
      training_plan_version_id: assignment.training_plan_version_id || null,
      instruction: assignment.instruction || null,
      sort_order: index,
      lanes: assignment.lane_ids.map((laneId) => ({ lane_id: laneId })),
    }));

  const save = () => {
    if (!dialog) return;
    const item = dialog.item;
    const opts = { preserveScroll: true, onSuccess: () => setDialog(null) };
    let payload: Record<string, any> = { ...form };

    if (dialog.kind === 'session') {
      payload = { ...payload, training_groups: mappedAssignments(), sports_venue_id: selectedPool?.venue.id ?? null };
    }
    if (dialog.kind === 'recurrence') {
      payload = { ...payload, groups: mappedAssignments(), sports_venue_id: selectedPool?.venue.id ?? null };
    }

    if (dialog.kind === 'macro') item
      ? router.put(route('desportivo.planeamento.macros.update', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.macros.store'), payload, opts);
    if (dialog.kind === 'meso') item
      ? router.put(route('desportivo.planeamento.mesos.update', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.mesos.store'), payload, opts);
    if (dialog.kind === 'micro') item
      ? router.put(route('desportivo.planeamento.micros.update', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.micros.store'), payload, opts);
    if (dialog.kind === 'session') item
      ? router.put(route('desportivo.planeamento.sessions.update', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.sessions.store'), payload, opts);
    if (dialog.kind === 'recurrence') item
      ? router.put(route('desportivo.planeamento.recurrences.update', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.recurrences.store'), payload, opts);
    if (dialog.kind === 'objective') item
      ? router.post(route('desportivo.planeamento.objectives.revise', item.id), payload, opts)
      : router.post(route('desportivo.planeamento.objectives.store'), payload, opts);
  };

  const remove = (routeName: string, id: string, label: string) => {
    if (window.confirm(`Remover/arquivar ${label}? O histórico utilizado será preservado.`)) {
      router.delete(route(routeName, id), { data: { season_id: seasonId }, preserveScroll: true });
    }
  };

  const generate = (recurrence: Recurrence) => {
    const until = window.prompt(
      'Gerar sessões até (AAAA-MM-DD):',
      dateOnly(recurrence.ends_on) || dateOnly(selectedSeason?.data_fim),
    );
    if (until) {
      router.post(route('desportivo.planeamento.recurrences.generate', recurrence.id), { until, season_id: seasonId }, { preserveScroll: true });
    }
  };

  const planningLocked = !selectedSeason || ['closed', 'archived'].includes(selectedSeason.status);

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div className="flex w-full items-center justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold">Planeamento Desportivo</h1>
            <p className="text-xs text-muted-foreground">Periodização, sessões, recorrências e objetivos</p>
          </div>
          <Button variant="outline" size="sm" onClick={() => router.get(route('desportivo.estrutura.index'))}>
            <GearSix size={15} className="mr-1" />Estrutura
          </Button>
        </div>
      }
    >
      <Head title="Planeamento Desportivo" />
      <div className="space-y-4 p-1 sm:p-2">
        <Card>
          <CardContent className="flex flex-col gap-3 p-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <div className="text-xs text-muted-foreground">Contexto de época</div>
              <div className="font-semibold">
                {selectedSeason?.nome ?? 'Sem época configurada'}
                {selectedSeason?.modality?.name && <span className="font-normal text-muted-foreground"> · {selectedSeason.modality.name}</span>}
              </div>
              {planningLocked && selectedSeason && <div className="text-xs text-destructive">Época encerrada/arquivada: o planeamento está em modo de consulta.</div>}
            </div>
            <select
              className={`${selectClass} max-w-sm`}
              value={seasonId}
              onChange={(event) => router.get(route('desportivo.planeamento'), { season_id: event.target.value }, { preserveScroll: true })}
            >
              {seasons.map((season) => <option key={season.id} value={season.id}>{season.nome} · {season.ano_temporada}</option>)}
            </select>
          </CardContent>
        </Card>

        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          {[
            ['Macrociclos', stats.macros], ['Microciclos', stats.micros], ['Sessões', stats.sessions], ['A rever', stats.review],
          ].map(([label, value]) => (
            <Card key={String(label)}><CardContent className="p-3"><div className="text-xs text-muted-foreground">{label}</div><div className="text-xl font-semibold">{value}</div></CardContent></Card>
          ))}
        </div>

        <Tabs value={tab} onValueChange={setTab}>
          <TabsList className="grid w-full grid-cols-2 lg:grid-cols-4">
            <TabsTrigger value="periodizacao">Periodização</TabsTrigger>
            <TabsTrigger value="sessoes">Sessões</TabsTrigger>
            <TabsTrigger value="recorrencias">Recorrências</TabsTrigger>
            <TabsTrigger value="objetivos">Objetivos</TabsTrigger>
          </TabsList>

          <TabsContent value="periodizacao" className="space-y-3">
            <div className="flex justify-end"><Button onClick={() => open('macro')} disabled={planningLocked}><Plus size={15} className="mr-1" />Macrociclo</Button></div>
            {selectedSeason && <Timeline season={selectedSeason} macros={macrocycles} competitions={competitions} />}
            <div className="space-y-2">
              {macrocycles.map((macro) => (
                <Card key={macro.id}>
                  <CardContent className="p-3">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <div className="font-semibold">{macro.nome} <Badge variant="outline">{macro.tipo}</Badge></div>
                        <div className="text-xs text-muted-foreground">{dateOnly(macro.data_inicio)} → {dateOnly(macro.data_fim)} · {macro.objetivo_principal || 'Sem objetivo'}</div>
                      </div>
                      <div className="flex">
                        <Button size="icon" variant="ghost" onClick={() => open('meso', undefined, macro.id)} disabled={planningLocked}><Plus size={15} /></Button>
                        <Button size="icon" variant="ghost" onClick={() => open('macro', macro)} disabled={planningLocked}><PencilSimple size={15} /></Button>
                        <Button size="icon" variant="ghost" onClick={() => remove('desportivo.planeamento.macros.destroy', macro.id, macro.nome)} disabled={planningLocked}><Trash size={15} /></Button>
                      </div>
                    </div>
                    <div className="mt-3 space-y-2 border-l pl-3">
                      {(macro.mesocycles ?? []).map((meso) => (
                        <div key={meso.id} className="rounded-md border p-2">
                          <div className="flex items-start justify-between gap-2">
                            <div><b className="text-sm">{meso.nome}</b><div className="text-xs text-muted-foreground">{dateOnly(meso.data_inicio)} → {dateOnly(meso.data_fim)} · {meso.objetivo_principal}</div></div>
                            <div className="flex">
                              <Button size="icon" variant="ghost" onClick={() => open('micro', undefined, meso.id)} disabled={planningLocked}><Plus size={14} /></Button>
                              <Button size="icon" variant="ghost" onClick={() => open('meso', meso)} disabled={planningLocked}><PencilSimple size={14} /></Button>
                              <Button size="icon" variant="ghost" onClick={() => remove('desportivo.planeamento.mesos.destroy', meso.id, meso.nome)} disabled={planningLocked}><Trash size={14} /></Button>
                            </div>
                          </div>
                          <div className="mt-2 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                            {(meso.microcycles ?? []).map((micro) => (
                              <div key={micro.id} className={`rounded border p-2 text-xs ${micro.is_recovery_week ? 'border-dashed bg-muted/50' : ''}`}>
                                <div className="flex justify-between gap-1"><b>{micro.semana}</b>{micro.is_recovery_week && <Badge variant="secondary">Descarga</Badge>}</div>
                                <div>{dateOnly(micro.data_inicio)} → {dateOnly(micro.data_fim)}</div>
                                <div className="text-muted-foreground">{micro.objetivo_principal || '—'}{micro.volume_previsto ? ` · ${micro.volume_previsto} m` : ''}</div>
                                <div className="mt-1 flex justify-end">
                                  <Button size="icon" variant="ghost" onClick={() => open('session', undefined, micro.id)} disabled={planningLocked}><CalendarBlank size={14} /></Button>
                                  <Button size="icon" variant="ghost" onClick={() => open('micro', micro)} disabled={planningLocked}><PencilSimple size={14} /></Button>
                                  <Button size="icon" variant="ghost" onClick={() => remove('desportivo.planeamento.micros.destroy', micro.id, micro.semana)} disabled={planningLocked}><Trash size={14} /></Button>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="sessoes" className="space-y-3">
            <div className="flex justify-end"><Button onClick={() => open('session')} disabled={planningLocked || allMicros.length === 0}><Plus size={15} className="mr-1" />Sessão</Button></div>
            <div className="grid gap-2">
              {sessions.map((session) => (
                <Card key={session.id}>
                  <CardContent className="flex flex-col gap-2 p-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                      <div className="flex flex-wrap items-center gap-2">
                        <b>{session.numero_treino || 'Treino'}</b>
                        <Badge variant={session.session_status === 'published' ? 'default' : 'outline'}>{session.session_status}</Badge>
                        {session.schedule_review_required && <Badge variant="destructive">Conflito/revisão</Badge>}
                      </div>
                      <div className="text-xs text-muted-foreground">{dateOnly(session.data)} · {timeOnly(session.hora_inicio)}–{timeOnly(session.hora_fim)} · {session.pool?.name || session.venue?.name || 'Sem local'} · {session.athlete_records_count ?? 0} atleta(s)</div>
                      {(session.schedule_conflicts_snapshot ?? []).map((conflict: any, index: number) => <div key={index} className="text-xs text-destructive">{conflict.message}</div>)}
                    </div>
                    <Button size="sm" variant="outline" onClick={() => open('session', session)} disabled={planningLocked}>Editar planeamento</Button>
                  </CardContent>
                </Card>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="recorrencias" className="space-y-3">
            <div className="flex justify-end"><Button onClick={() => open('recurrence')} disabled={planningLocked || allMicros.length === 0}><Plus size={15} className="mr-1" />Recorrência</Button></div>
            <div className="grid gap-2">
              {recurrences.map((recurrence) => (
                <Card key={recurrence.id}>
                  <CardContent className="flex flex-col gap-2 p-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                      <div className="flex gap-2"><b>{recurrence.name}</b><Badge variant={recurrence.active ? 'default' : 'secondary'}>{recurrence.active ? 'Ativa' : 'Arquivada'}</Badge></div>
                      <div className="text-xs text-muted-foreground">{dateOnly(recurrence.starts_on)} → {dateOnly(recurrence.ends_on) || 'sem fim'} · {timeOnly(recurrence.start_time)}–{timeOnly(recurrence.end_time)} · {(recurrence.weekdays ?? []).map((day) => weekdays.find(([value]) => value === day)?.[1]).filter(Boolean).join(', ')}</div>
                      <div className="text-xs text-muted-foreground">Gerada até: {dateOnly(recurrence.last_generated_until) || '—'}</div>
                    </div>
                    <div className="flex gap-1">
                      <Button size="sm" onClick={() => generate(recurrence)} disabled={!recurrence.active || planningLocked}>Gerar</Button>
                      <Button size="sm" variant="outline" onClick={() => open('recurrence', recurrence)} disabled={planningLocked}>Editar</Button>
                      <Button size="icon" variant="ghost" onClick={() => remove('desportivo.planeamento.recurrences.destroy', recurrence.id, recurrence.name)} disabled={planningLocked}><Trash size={15} /></Button>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="objetivos" className="space-y-3">
            <div className="flex justify-end"><Button onClick={() => open('objective')} disabled={planningLocked}><Plus size={15} className="mr-1" />Objetivo</Button></div>
            <div className="grid gap-2 md:grid-cols-2">
              {objectives.map((objective) => (
                <Card key={objective.id}>
                  <CardHeader className="pb-2"><CardTitle className="text-sm">{objective.latest_version?.title || 'Objetivo'}</CardTitle></CardHeader>
                  <CardContent className="text-xs">
                    <p>{objective.latest_version?.description}</p>
                    <p className="mt-1 text-muted-foreground">{objective.target_type === 'season' ? 'Época' : 'Escalão'} · alvo {objective.latest_version?.target_text || objective.latest_version?.target_value || 'qualitativo'} {objective.latest_version?.target_unit || ''}</p>
                    <Button className="mt-2" size="sm" variant="outline" onClick={() => open('objective', objective)} disabled={planningLocked}>Nova versão</Button>
                  </CardContent>
                </Card>
              ))}
            </div>
          </TabsContent>
        </Tabs>
      </div>

      <Dialog open={!!dialog} onOpenChange={(openValue) => !openValue && setDialog(null)}>
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader><DialogTitle>{dialog?.item ? 'Editar' : 'Novo'} {dialog?.kind}</DialogTitle></DialogHeader>
          {dialog && (
            <Editor
              kind={dialog.kind}
              form={form}
              set={set}
              props={props}
              assignments={assignments}
              setAssignments={setAssignments}
              availableLanes={availableLanes}
            />
          )}
          <DialogFooter><Button variant="outline" onClick={() => setDialog(null)}>Cancelar</Button><Button onClick={save}>Guardar</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </AuthenticatedLayout>
  );
}

function Editor({ kind, form, set, props, assignments, setAssignments, availableLanes }: {
  kind: Kind;
  form: Record<string, any>;
  set: (key: string, value: any) => void;
  props: Props;
  assignments: AssignmentDraft[];
  setAssignments: (rows: AssignmentDraft[]) => void;
  availableLanes: Lane[];
}) {
  const { macrocycles, groups, athletes, coaches, locations, planVersions, ageGroups } = props;
  const mesos = macrocycles.flatMap((macro) => macro.mesocycles ?? []);
  const micros = mesos.flatMap((meso) => meso.microcycles ?? []);
  const pools = locations.flatMap((venue) => (venue.pools ?? []).map((pool) => ({ ...pool, venueName: venue.name })));

  const field = (label: string, key: string, type = 'text') => (
    <div><Label>{label}</Label><Input type={type} value={form[key] ?? ''} onChange={(event) => set(key, event.target.value)} /></div>
  );
  const select = (label: string, key: string, options: Array<{ id: string; name: string }>, empty = false) => (
    <div><Label>{label}</Label><select className={selectClass} value={form[key] ?? ''} onChange={(event) => set(key, event.target.value)}>{empty && <option value="">—</option>}{options.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select></div>
  );
  const notes = (label: string, key: string) => (
    <div><Label>{label}</Label><textarea className={textareaClass} value={form[key] ?? ''} onChange={(event) => set(key, event.target.value)} /></div>
  );

  if (kind === 'macro') {
    return <div className="space-y-3">{field('Nome', 'nome')}{field('Tipo', 'tipo')}<div className="grid grid-cols-2 gap-3">{field('Início', 'data_inicio', 'date')}{field('Fim', 'data_fim', 'date')}</div>{field('Objetivo principal', 'objetivo_principal')}{field('Objetivo secundário', 'objetivo_secundario')}</div>;
  }

  if (kind === 'meso') {
    return <div className="space-y-3">{select('Macrociclo', 'macrociclo_id', macrocycles.filter((item) => item.active).map((item) => ({ id: item.id, name: item.nome })))}{field('Nome', 'nome')}<div className="grid grid-cols-2 gap-3">{field('Início', 'data_inicio', 'date')}{field('Fim', 'data_fim', 'date')}</div>{field('Objetivo principal', 'objetivo_principal')}{field('Objetivo secundário', 'objetivo_secundario')}</div>;
  }

  if (kind === 'micro') {
    return <div className="space-y-3">{select('Mesociclo', 'mesociclo_id', mesos.filter((item) => item.active).map((item) => ({ id: item.id, name: item.nome })))}{field('Semana/nome', 'semana')}<div className="grid grid-cols-2 gap-3">{field('Início', 'data_inicio', 'date')}{field('Fim', 'data_fim', 'date')}</div>{field('Volume previsto (m)', 'volume_previsto', 'number')}{field('Objetivo principal', 'objetivo_principal')}{field('Objetivo secundário', 'objetivo_secundario')}<label className="flex gap-2 text-sm"><input type="checkbox" checked={!!form.is_recovery_week} onChange={(event) => set('is_recovery_week', event.target.checked)} /> Semana de descarga</label>{notes('Notas', 'notas')}</div>;
  }

  if (kind === 'objective') {
    return (
      <div className="space-y-3">
        <div><Label>Âmbito</Label><select className={selectClass} value={form.target_type} onChange={(event) => { const value = event.target.value; set('target_type', value); set('target_id', value === 'season' ? form.season_id : ''); }}><option value="season">Época</option><option value="age_group">Escalão</option></select></div>
        {form.target_type === 'age_group' && select('Escalão', 'target_id', ageGroups.map((ageGroup) => ({ id: ageGroup.id, name: ageGroup.nome })))}
        {field('Título', 'title')}{notes('Descrição', 'description')}
        <div><Label>Tipo</Label><select className={selectClass} value={form.objective_type} onChange={(event) => set('objective_type', event.target.value)}><option value="text">Textual</option><option value="measurable">Mensurável</option></select></div>
        {form.objective_type === 'measurable' && <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">{field('Valor', 'target_value', 'number')}{field('Alvo textual', 'target_text')}{field('Unidade', 'target_unit')}</div>}
        {field('Prazo', 'due_at', 'date')}
      </div>
    );
  }

  const isSession = kind === 'session';
  return (
    <div className="space-y-3">
      {!isSession && field('Nome', 'name')}
      {select('Microciclo', isSession ? 'microciclo_id' : 'microcycle_id', micros.filter((item) => item.active).map((item) => ({ id: item.id, name: item.semana })))}

      {isSession ? (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">{field('Data', 'data', 'date')}{field('Início', 'hora_inicio', 'time')}{field('Fim', 'hora_fim', 'time')}</div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-2">{field('Início da regra', 'starts_on', 'date')}{field('Fim da regra', 'ends_on', 'date')}</div>
          <div className="grid grid-cols-2 gap-2">{field('Hora início', 'start_time', 'time')}{field('Hora fim', 'end_time', 'time')}</div>
          <div className="grid grid-cols-2 gap-2">
            <div><Label>Frequência</Label><select className={selectClass} value={form.frequency ?? 'weekly'} onChange={(event) => set('frequency', event.target.value)}><option value="weekly">Semanal</option><option value="daily">Diária</option></select></div>
            {field('Intervalo', 'interval', 'number')}
          </div>
          <div className="flex flex-wrap gap-3">{weekdays.map(([day, label]) => <label key={day} className="text-sm"><input type="checkbox" className="mr-1" checked={(form.weekdays ?? []).includes(day)} onChange={(event) => set('weekdays', event.target.checked ? [...(form.weekdays ?? []), day] : (form.weekdays ?? []).filter((value: number) => value !== day))} />{label}</label>)}</div>
        </>
      )}

      <div>
        <Label>Piscina/área</Label>
        <select
          className={selectClass}
          value={form.sports_pool_id ?? ''}
          onChange={(event) => {
            set('sports_pool_id', event.target.value);
            setAssignments(assignments.map((assignment) => ({ ...assignment, lane_ids: [] })));
          }}
        >
          <option value="">—</option>
          {pools.map((pool) => <option key={pool.id} value={pool.id}>{pool.venueName} · {pool.name}</option>)}
        </select>
      </div>

      {select('Treinador', 'responsavel_id', coaches, true)}
      {select('Treino da Biblioteca', 'training_plan_version_id', planVersions.map((plan) => ({ id: plan.id, name: `${plan.plan?.nome ?? plan.nome_snapshot ?? 'Plano'} · v${plan.version}` })), true)}
      {field('Tipo de treino', isSession ? 'tipo_treino' : 'training_type')}
      {notes('Instrução', isSession ? 'instrucao' : 'instruction')}

      {isSession && (
        <div>
          <Label>Atletas previstos (grupos são adicionados automaticamente)</Label>
          <select multiple className="min-h-28 w-full rounded-md border bg-background p-2 text-sm" value={form.athlete_ids ?? []} onChange={(event) => set('athlete_ids', Array.from(event.currentTarget.selectedOptions).map((option) => option.value))}>
            {athletes.map((athlete) => <option key={athlete.id} value={athlete.id}>{athlete.name}</option>)}
          </select>
        </div>
      )}

      <div className="space-y-2">
        <div className="flex items-center justify-between"><Label>Grupos e pistas</Label><Button type="button" size="sm" variant="outline" onClick={() => setAssignments([...assignments, { training_group_id: '', training_plan_version_id: '', instruction: '', lane_ids: [] }])}>Adicionar grupo</Button></div>
        {assignments.map((assignment, index) => (
          <div key={index} className="space-y-2 rounded-md border p-2">
            <div className="grid gap-2 md:grid-cols-2">
              <div><Label>Grupo</Label><select className={selectClass} value={assignment.training_group_id} onChange={(event) => setAssignments(assignments.map((row, rowIndex) => rowIndex === index ? { ...row, training_group_id: event.target.value } : row))}><option value="">Grupo…</option>{groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}</select></div>
              <div><Label>Plano específico do grupo</Label><select className={selectClass} value={assignment.training_plan_version_id} onChange={(event) => setAssignments(assignments.map((row, rowIndex) => rowIndex === index ? { ...row, training_plan_version_id: event.target.value } : row))}><option value="">Usar conteúdo global</option>{planVersions.map((plan) => <option key={plan.id} value={plan.id}>{plan.plan?.nome ?? plan.nome_snapshot ?? 'Plano'} · v{plan.version}</option>)}</select></div>
            </div>
            <div><Label>Instrução do grupo</Label><Input value={assignment.instruction} onChange={(event) => setAssignments(assignments.map((row, rowIndex) => rowIndex === index ? { ...row, instruction: event.target.value } : row))} /></div>
            <div><Label>Pistas (Ctrl/Cmd para várias)</Label><select multiple className="min-h-20 w-full rounded-md border bg-background p-2 text-sm" value={assignment.lane_ids} onChange={(event) => setAssignments(assignments.map((row, rowIndex) => rowIndex === index ? { ...row, lane_ids: Array.from(event.currentTarget.selectedOptions).map((option) => option.value) } : row))}>{availableLanes.map((lane) => <option key={lane.id} value={lane.id}>Pista {lane.lane_number ?? lane.name} · cap. {lane.capacity ?? '—'}</option>)}</select></div>
            <div className="flex justify-end"><Button type="button" size="sm" variant="ghost" onClick={() => setAssignments(assignments.filter((_, rowIndex) => rowIndex !== index))}>Remover grupo</Button></div>
          </div>
        ))}
      </div>

      {isSession ? (
        <div><Label>Estado</Label><select className={selectClass} value={form.session_status ?? 'draft'} onChange={(event) => set('session_status', event.target.value)}><option value="draft">Rascunho</option><option value="published">Publicado</option></select></div>
      ) : (
        <div><Label>Estado das sessões geradas</Label><select className={selectClass} value={form.session_status_template ?? 'draft'} onChange={(event) => set('session_status_template', event.target.value)}><option value="draft">Rascunho</option><option value="published">Publicar quando houver conteúdo</option></select></div>
      )}
    </div>
  );
}

function Timeline({ season, macros, competitions }: { season: Season; macros: Macro[]; competitions: Competition[] }) {
  const start = new Date(`${dateOnly(season.data_inicio)}T00:00:00`).getTime();
  const end = new Date(`${dateOnly(season.data_fim)}T00:00:00`).getTime();
  const span = Math.max(1, end - start);
  const percent = (date: string) => Math.max(0, Math.min(100, ((new Date(`${dateOnly(date)}T00:00:00`).getTime() - start) / span) * 100));
  const bar = (from: string, to: string) => ({ left: `${percent(from)}%`, width: `${Math.max(0.6, percent(to) - percent(from))}%` });

  return (
    <Card>
      <CardContent className="overflow-x-auto p-3">
        <div className="min-w-[900px] space-y-2">
          <div className="relative h-9 border-b">
            <span className="text-xs font-medium">{dateOnly(season.data_inicio)}</span>
            <span className="absolute right-0 text-xs font-medium">{dateOnly(season.data_fim)}</span>
            {competitions.map((competition) => <div key={competition.id} className="absolute bottom-0 h-5 border-l-2 border-destructive" style={{ left: `${percent(competition.data_inicio)}%` }} title={`${competition.nome} · ${dateOnly(competition.data_inicio)}`} />)}
          </div>
          {macros.map((macro) => (
            <div key={macro.id} className="space-y-1">
              <div className="relative h-7 rounded bg-muted"><div className="absolute h-7 overflow-hidden rounded bg-foreground/80 px-2 text-xs leading-7 text-background" style={bar(macro.data_inicio, macro.data_fim)}>{macro.nome}</div></div>
              {(macro.mesocycles ?? []).map((meso) => (
                <div key={meso.id} className="relative ml-4 h-6 rounded bg-muted/70">
                  <div className="absolute h-6 overflow-hidden rounded border bg-background px-2 text-[11px] leading-6" style={bar(meso.data_inicio, meso.data_fim)}>{meso.nome}</div>
                  {(meso.microcycles ?? []).filter((micro) => micro.data_inicio && micro.data_fim).map((micro) => (
                    <div key={micro.id} className={`absolute bottom-0 h-1.5 ${micro.is_recovery_week ? 'border border-dashed border-foreground' : 'bg-foreground/40'}`} style={bar(micro.data_inicio!, micro.data_fim!)} title={`${micro.semana}${micro.is_recovery_week ? ' · descarga' : ''}`} />
                  ))}
                </div>
              ))}
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
