import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Check, Clock3, Pause, Play, RefreshCw, Square, TimerReset, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { ClubMark } from '@/Components/ClubMark';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { useClubSettings } from '@/hooks/useClubSettings';

type SeriesLine = {
  id: string;
  ordem: number;
  bloco?: string | null;
  descricao_texto?: string | null;
  repeticoes?: number | null;
  distancia_m?: number | null;
  distancia_total_m?: number | null;
  estilo?: string | null;
  zona_intensidade?: string | null;
  intervalo?: string | null;
  observacoes?: string | null;
};

type TimerRow = {
  id: string;
  training_series_id?: string | null;
  exercise_label: string;
  repetition_number?: number | null;
  timer_state: 'running' | 'paused' | 'stopped';
  elapsed_ms: number;
  started_at?: string | null;
  last_resumed_at?: string | null;
};

type AthleteRow = {
  id: string;
  user_id: string;
  athlete_name: string;
  estado: string;
  presente: boolean;
  volume_real_m?: number | null;
  rpe?: number | null;
  observacoes_tecnicas?: string | null;
  cais_version?: number | null;
  cais_last_modified_at?: string | null;
  metrics: any[];
  timers: TimerRow[];
};

type PoolDeckSession = {
  training: {
    id: string;
    numero_treino?: string | null;
    data?: string | null;
    hora_inicio?: string | null;
    hora_fim?: string | null;
    tipo_treino?: string | null;
    descricao_treino?: string | null;
    instrucao?: string | null;
    volume_planeado_m?: number | null;
    local?: string | null;
    pool_deck_status?: 'planned' | 'open' | 'closed';
    series: SeriesLine[];
    groups: Array<{
      id: string;
      name?: string | null;
      instruction?: string | null;
      lanes: Array<{ id: string; name?: string | null; number?: number | null; planned_capacity?: number | null }>;
    }>;
    sync_conflicts?: any[];
  };
  athlete_records: AthleteRow[];
  subgroup_timers?: TimerRow[];
};

type Workspace = { sessions: PoolDeckSession[]; generated_at?: string };

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function jsonRequest(url: string, method: string, body?: unknown): Promise<any> {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const validation = payload?.errors ? Object.values(payload.errors).flat().join(' ') : '';
    throw new Error(validation || payload?.message || `Erro ${response.status}`);
  }
  return payload;
}

function elapsedNow(timer: TimerRow, tick: number): number {
  if (timer.timer_state !== 'running' || !timer.last_resumed_at) return Number(timer.elapsed_ms || 0);
  const resumed = new Date(timer.last_resumed_at).getTime();
  return Number(timer.elapsed_ms || 0) + Math.max(0, tick - resumed);
}

function formatMs(ms: number): string {
  const total = Math.max(0, Math.floor(ms));
  const minutes = Math.floor(total / 60000);
  const seconds = Math.floor((total % 60000) / 1000);
  const hundredths = Math.floor((total % 1000) / 10);
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}.${String(hundredths).padStart(2, '0')}`;
}

function parseSplits(value: string): Array<{ distance_m: number; duration_ms: number }> {
  return value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .map((item) => {
      const [distanceRaw, secondsRaw] = item.split(':');
      return {
        distance_m: Number(distanceRaw),
        duration_ms: Math.round(Number(secondsRaw) * 1000),
      };
    })
    .filter((item) => Number.isFinite(item.distance_m) && item.distance_m > 0 && Number.isFinite(item.duration_ms) && item.duration_ms >= 0);
}

function statusLabel(status: string): string {
  return ({
    presente: 'Presente',
    ausente: 'Ausente',
    justificado: 'Justificado',
    lesionado: 'Lesionado',
    limitado: 'Limitado',
    doente: 'Doente',
    dispensado: 'Dispensado',
  } as Record<string, string>)[status] ?? status;
}

function AthleteSheet({
  session,
  athlete,
  tick,
  onClose,
  onChanged,
}: {
  session: PoolDeckSession;
  athlete: AthleteRow;
  tick: number;
  onClose: () => void;
  onChanged: () => Promise<void>;
}) {
  const [status, setStatus] = useState(athlete.estado || 'presente');
  const [volume, setVolume] = useState(athlete.volume_real_m?.toString() ?? '');
  const [rpe, setRpe] = useState(athlete.rpe?.toString() ?? '');
  const [note, setNote] = useState(athlete.observacoes_tecnicas ?? '');
  const [seriesId, setSeriesId] = useState(session.training.series[0]?.id ?? '');
  const [distance, setDistance] = useState('');
  const [durationSeconds, setDurationSeconds] = useState('');
  const [repetitionMode, setRepetitionMode] = useState<'one_off' | 'repetition'>('one_off');
  const [repetitionNumber, setRepetitionNumber] = useState('');
  const [splits, setSplits] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const mutate = async (action: () => Promise<any>) => {
    setBusy(true);
    setError('');
    try {
      await action();
      await onChanged();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Não foi possível guardar.');
    } finally {
      setBusy(false);
    }
  };

  const selectedSeries = session.training.series.find((line) => line.id === seriesId);

  const saveAthlete = () => mutate(() => jsonRequest(
    route('desportivo.cais.runtime.athletes.update', {
      training: session.training.id,
      trainingAthlete: athlete.id,
    }),
    'PATCH',
    {
      estado: status,
      volume_real_m: volume === '' ? null : Number(volume),
      rpe: rpe === '' ? null : Number(rpe),
      observacoes_tecnicas: note || null,
      client_version: athlete.cais_version ?? 0,
      client_modified_at: new Date().toISOString(),
      client_event_id: crypto.randomUUID(),
    },
  ));

  const saveMetric = () => {
    if (!seriesId || !distance) {
      setError('Seleciona o exercício e indica a distância total.');
      return;
    }
    if (!durationSeconds) {
      setError('Indica o tempo total da medição.');
      return;
    }

    return mutate(() => jsonRequest(
      route('desportivo.cais.runtime.metrics.store', { training: session.training.id }),
      'POST',
      {
        training_athlete_id: athlete.id,
        training_series_id: seriesId,
        measurement_type: 'time',
        total_distance_m: Number(distance),
        duration_ms: Math.round(Number(durationSeconds) * 1000),
        repetition_mode: repetitionMode,
        repetition_number: repetitionMode === 'repetition' ? Number(repetitionNumber) : null,
        splits: parseSplits(splits),
        client_event_id: crypto.randomUUID(),
        client_recorded_at: new Date().toISOString(),
      },
    ));
  };

  const startTimer = () => {
    if (!seriesId) {
      setError('Seleciona primeiro o exercício do cronómetro.');
      return;
    }

    return mutate(() => jsonRequest(
      route('desportivo.cais.runtime.timers.start', { training: session.training.id }),
      'POST',
      {
        subject_type: 'athlete',
        training_athlete_id: athlete.id,
        training_series_id: seriesId,
        exercise_label: selectedSeries?.descricao_texto || `Série ${selectedSeries?.ordem ?? ''}`,
        repetition_number: repetitionMode === 'repetition' && repetitionNumber ? Number(repetitionNumber) : null,
        client_timer_id: crypto.randomUUID(),
        client_event_id: crypto.randomUUID(),
        occurred_at: new Date().toISOString(),
      },
    ));
  };

  const timerEvent = (timer: TimerRow, event: 'pause' | 'resume' | 'stop') => mutate(() => jsonRequest(
    route('desportivo.cais.runtime.timers.event', {
      training: session.training.id,
      timer: timer.id,
      event,
    }),
    'POST',
    { client_event_id: crypto.randomUUID(), occurred_at: new Date().toISOString() },
  ));

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-950/40" onMouseDown={onClose}>
      <div className="h-full w-full max-w-xl overflow-y-auto bg-white p-4 shadow-2xl sm:p-5" onMouseDown={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Atleta · {session.training.numero_treino}</p>
            <h2 className="text-xl font-bold text-slate-900">{athlete.athlete_name}</h2>
          </div>
          <Button variant="outline" size="icon" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        {error && <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

        <section className="mb-4 rounded-xl border bg-slate-50 p-3">
          <h3 className="mb-3 text-sm font-semibold">Presença e execução</h3>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <label className="col-span-2 sm:col-span-1 text-xs font-medium">Estado
              <select value={status} onChange={(e) => setStatus(e.target.value)} className="mt-1 w-full rounded-md border bg-white px-2 py-2 text-sm">
                {['presente', 'ausente', 'justificado', 'lesionado', 'limitado', 'doente', 'dispensado'].map((value) => <option key={value} value={value}>{statusLabel(value)}</option>)}
              </select>
            </label>
            <label className="text-xs font-medium">Volume real (m)
              <input type="number" value={volume} onChange={(e) => setVolume(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
            </label>
            <label className="text-xs font-medium">RPE
              <input type="number" min={1} max={10} value={rpe} onChange={(e) => setRpe(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
            </label>
          </div>
          <label className="mt-2 block text-xs font-medium">Nota técnica
            <textarea value={note} onChange={(e) => setNote(e.target.value)} rows={3} className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
          </label>
          <Button className="mt-2" disabled={busy} onClick={saveAthlete}><Check className="mr-2 h-4 w-4" />Guardar atleta</Button>
        </section>

        <section className="mb-4 rounded-xl border p-3">
          <h3 className="mb-3 text-sm font-semibold">Exercício, cronómetros e medição rápida</h3>
          <label className="block text-xs font-medium">Exercício
            <select value={seriesId} onChange={(e) => setSeriesId(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm">
              <option value="">Selecionar exercício…</option>
              {session.training.series.map((line) => (
                <option key={line.id} value={line.id}>{line.ordem}. {line.descricao_texto || 'Série'} {line.repeticoes ? `· ${line.repeticoes}x` : ''}</option>
              ))}
            </select>
          </label>

          <div className="mt-2 grid grid-cols-2 gap-2">
            <label className="text-xs font-medium">Modo
              <select value={repetitionMode} onChange={(e) => setRepetitionMode(e.target.value as any)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm">
                <option value="one_off">Medição única</option>
                <option value="repetition">Uma repetição</option>
              </select>
            </label>
            <label className="text-xs font-medium">N.º repetição
              <input disabled={repetitionMode !== 'repetition'} type="number" min={1} value={repetitionNumber} onChange={(e) => setRepetitionNumber(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm disabled:bg-slate-100" />
            </label>
          </div>

          <Button className="mt-2" variant="outline" disabled={busy || !seriesId || session.training.pool_deck_status !== 'open'} onClick={startTimer}>
            <Clock3 className="mr-2 h-4 w-4" />Iniciar cronómetro neste exercício
          </Button>

          {athlete.timers.filter((timer) => timer.timer_state !== 'stopped').length > 0 && (
            <div className="mt-3 space-y-2">
              {athlete.timers.filter((timer) => timer.timer_state !== 'stopped').map((timer) => (
                <div key={timer.id} className="flex items-center justify-between gap-2 rounded-lg bg-slate-900 px-3 py-2 text-white">
                  <div className="min-w-0">
                    <p className="truncate text-xs text-slate-300">{timer.exercise_label}</p>
                    <p className="font-mono text-xl font-bold">{formatMs(elapsedNow(timer, tick))}</p>
                  </div>
                  <div className="flex gap-1">
                    {timer.timer_state === 'running' ? (
                      <Button size="icon" variant="secondary" onClick={() => timerEvent(timer, 'pause')}><Pause className="h-4 w-4" /></Button>
                    ) : (
                      <Button size="icon" variant="secondary" onClick={() => timerEvent(timer, 'resume')}><Play className="h-4 w-4" /></Button>
                    )}
                    <Button size="icon" variant="secondary" onClick={() => timerEvent(timer, 'stop')}><Square className="h-4 w-4" /></Button>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="mt-4 grid grid-cols-2 gap-2">
            <label className="text-xs font-medium">Distância total (m)
              <input type="number" min={1} value={distance} onChange={(e) => setDistance(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
            </label>
            <label className="text-xs font-medium">Tempo total (segundos)
              <input type="number" min={0} step="0.01" value={durationSeconds} onChange={(e) => setDurationSeconds(e.target.value)} className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
            </label>
          </div>
          <label className="mt-2 block text-xs font-medium">Splits — formato distância:segundos, separados por vírgula
            <input value={splits} onChange={(e) => setSplits(e.target.value)} placeholder="50:30.10, 50:31.13" className="mt-1 w-full rounded-md border px-2 py-2 text-sm" />
          </label>
          <Button className="mt-2" disabled={busy || session.training.pool_deck_status !== 'open'} onClick={saveMetric}>
            <TimerReset className="mr-2 h-4 w-4" />Guardar medição
          </Button>
        </section>

        <section className="rounded-xl border p-3">
          <h3 className="mb-2 text-sm font-semibold">Histórico de medições</h3>
          {athlete.metrics.length === 0 ? <p className="text-sm text-slate-500">Sem medições registadas.</p> : (
            <div className="space-y-2">
              {athlete.metrics.map((metric) => (
                <div key={metric.id} className="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                  <div className="flex justify-between gap-2"><strong>{metric.total_distance_m ? `${metric.total_distance_m} m` : metric.measurement_type}</strong><span className="font-mono">{metric.duration_ms != null ? formatMs(metric.duration_ms) : metric.tempo}</span></div>
                  {metric.repetition_number && <p className="text-xs text-slate-500">Repetição {metric.repetition_number}</p>}
                  {Array.isArray(metric.splits) && metric.splits.length > 0 && <p className="text-xs text-slate-500">{metric.splits.map((s: any) => `${s.distance_m}m ${formatMs(s.duration_ms)}`).join(' · ')}</p>}
                </div>
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

export default function DesportivoCaisPage() {
  const { clubLogoUrl, clubName, clubShortName } = useClubSettings();
  const [workspace, setWorkspace] = useState<Workspace>({ sessions: [] });
  const [selected, setSelected] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [sheet, setSheet] = useState<{ trainingId: string; athleteId: string } | null>(null);
  const [tick, setTick] = useState(Date.now());

  useEffect(() => {
    const id = window.setInterval(() => setTick(Date.now()), 100);
    return () => window.clearInterval(id);
  }, []);

  const loadWorkspace = useCallback(async () => {
    setError('');
    try {
      const data = await jsonRequest(route('desportivo.cais.runtime.workspace'), 'GET');
      setWorkspace(data);
      setSelected((previous) => {
        const valid = previous.filter((id) => data.sessions.some((item: PoolDeckSession) => item.training.id === id));
        if (valid.length > 0) return valid;
        const open = data.sessions.filter((item: PoolDeckSession) => item.training.pool_deck_status === 'open').map((item: PoolDeckSession) => item.training.id);
        return open.length > 0 ? open : data.sessions.slice(0, 1).map((item: PoolDeckSession) => item.training.id);
      });
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Não foi possível carregar o Cais.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void loadWorkspace(); }, [loadWorkspace]);

  const selectedSessions = useMemo(
    () => workspace.sessions.filter((session) => selected.includes(session.training.id)),
    [workspace.sessions, selected],
  );

  const currentSheet = sheet ? (() => {
    const session = workspace.sessions.find((item) => item.training.id === sheet.trainingId);
    const athlete = session?.athlete_records.find((item) => item.id === sheet.athleteId);
    return session && athlete ? { session, athlete } : null;
  })() : null;

  const mutateSession = async (trainingId: string, action: 'open' | 'close') => {
    setError('');
    try {
      await jsonRequest(route(action === 'open' ? 'desportivo.cais.runtime.open' : 'desportivo.cais.runtime.close', { training: trainingId }), 'POST', {});
      await loadWorkspace();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Não foi possível atualizar a sessão.');
    }
  };

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      <Head title="Modo Cais" />
      <header className="sticky top-0 z-30 border-b bg-white/95 px-3 py-2 shadow-sm backdrop-blur sm:px-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2">
            <ClubMark logoUrl={clubLogoUrl} clubName={clubName} clubShortName={clubShortName} className="h-9 w-9 shrink-0 bg-white" imageClassName="h-9 w-9 object-contain" />
            <div className="min-w-0"><p className="truncate text-sm font-bold">Modo Cais</p><p className="truncate text-xs text-slate-500">Execução operacional · sem alterar o planeamento</p></div>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={() => void loadWorkspace()}><RefreshCw className="mr-1 h-4 w-4" />Atualizar</Button>
            <Button variant="outline" size="sm" onClick={() => router.get(route('desportivo.treinos'))}><ArrowLeft className="mr-1 h-4 w-4" />Sair</Button>
          </div>
        </div>
      </header>

      <main className="p-3 sm:p-4">
        {error && <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
        {loading ? <div className="py-20 text-center text-sm text-slate-500">A carregar sessões…</div> : (
          <div className="grid gap-3 xl:grid-cols-[260px_minmax(0,1fr)]">
            <aside className="rounded-xl border bg-white p-3 shadow-sm">
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Sessões próximas / abertas</p>
              <div className="space-y-2">
                {workspace.sessions.map((session) => {
                  const active = selected.includes(session.training.id);
                  return (
                    <button key={session.training.id} type="button" onClick={() => setSelected((prev) => active ? prev.filter((id) => id !== session.training.id) : [...prev, session.training.id])} className={`w-full rounded-lg border px-3 py-2 text-left transition ${active ? 'border-blue-500 bg-blue-50' : 'bg-white hover:bg-slate-50'}`}>
                      <div className="flex items-center justify-between gap-2"><strong className="text-sm">{session.training.numero_treino || 'Treino'}</strong><Badge variant={session.training.pool_deck_status === 'open' ? 'default' : 'secondary'}>{session.training.pool_deck_status === 'open' ? 'Aberto' : session.training.pool_deck_status === 'closed' ? 'Fechado' : 'Planeado'}</Badge></div>
                      <p className="mt-1 text-xs text-slate-600">{session.training.data} · {session.training.hora_inicio || '--:--'} · {session.training.local || '-'}</p>
                      <p className="text-xs text-slate-500">{session.athlete_records.length} atletas</p>
                    </button>
                  );
                })}
                {workspace.sessions.length === 0 && <p className="text-sm text-slate-500">Sem sessões próximas.</p>}
              </div>
            </aside>

            <div className="grid items-start gap-3 2xl:grid-cols-2">
              {selectedSessions.map((session) => (
                <section key={session.training.id} className="overflow-hidden rounded-xl border bg-white shadow-sm">
                  <div className="border-b bg-slate-50 px-3 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div><h2 className="font-bold">{session.training.numero_treino || 'Treino'} · {session.training.tipo_treino}</h2><p className="text-xs text-slate-500">{session.training.data} · {session.training.hora_inicio || '--:--'}–{session.training.hora_fim || '--:--'} · {session.training.local || '-'}</p></div>
                      <div className="flex gap-2">
                        {session.training.pool_deck_status === 'planned' && <Button size="sm" onClick={() => void mutateSession(session.training.id, 'open')}><Play className="mr-1 h-4 w-4" />Abrir sessão</Button>}
                        {session.training.pool_deck_status === 'open' && <Button size="sm" variant="outline" onClick={() => void mutateSession(session.training.id, 'close')}><Square className="mr-1 h-4 w-4" />Fechar</Button>}
                      </div>
                    </div>
                    {session.training.groups.length > 0 && <div className="mt-2 flex flex-wrap gap-1">{session.training.groups.map((group) => <Badge key={group.id} variant="secondary">{group.name || 'Grupo'}{group.lanes.length ? ` · pistas ${group.lanes.map((lane) => lane.number ?? lane.name).join(', ')}` : ''}</Badge>)}</div>}
                  </div>

                  <div className="grid gap-3 p-3 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,.95fr)]">
                    <div>
                      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Treino completo</p>
                      <div className="space-y-1.5">
                        {session.training.series.map((line) => (
                          <div key={line.id} className="rounded-lg border-l-4 border-blue-500 bg-slate-50 px-3 py-2">
                            <div className="flex justify-between gap-2 text-sm"><strong>{line.ordem}. {line.repeticoes ? `${line.repeticoes}x ` : ''}{line.descricao_texto || 'Série'}</strong><span className="shrink-0 text-slate-500">{line.distancia_total_m ? `${line.distancia_total_m}m` : ''}</span></div>
                            <p className="text-xs text-slate-500">{[line.bloco, line.estilo, line.zona_intensidade, line.intervalo].filter(Boolean).join(' · ')}</p>
                            {line.observacoes && <p className="mt-1 text-xs italic text-slate-600">{line.observacoes}</p>}
                          </div>
                        ))}
                        {session.training.series.length === 0 && <div className="rounded-lg border border-dashed p-4 text-sm text-slate-500">Sessão sem séries estruturadas. Usa a instrução técnica definida no planeamento.</div>}
                      </div>
                    </div>

                    <div>
                      <div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Atletas — todos atribuídos</p><Badge variant="secondary">{session.athlete_records.length}</Badge></div>
                      <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                        {session.athlete_records.map((athlete) => {
                          const activeTimers = athlete.timers.filter((timer) => timer.timer_state !== 'stopped');
                          const exception = athlete.estado !== 'presente';
                          return (
                            <button key={athlete.id} type="button" onClick={() => setSheet({ trainingId: session.training.id, athleteId: athlete.id })} className={`rounded-lg border px-3 py-2 text-left ${exception ? 'border-amber-300 bg-amber-50' : 'bg-white hover:bg-slate-50'}`}>
                              <div className="flex items-start justify-between gap-2"><span className="truncate text-sm font-semibold">{athlete.athlete_name}</span>{activeTimers.length > 0 && <Badge>{activeTimers.length} timer</Badge>}</div>
                              <p className={`text-xs ${exception ? 'font-semibold text-amber-700' : 'text-emerald-700'}`}>{statusLabel(athlete.estado)}</p>
                              {activeTimers.slice(0, 1).map((timer) => <p key={timer.id} className="mt-1 font-mono text-sm font-bold">{formatMs(elapsedNow(timer, tick))}</p>)}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  </div>

                  {(session.training.sync_conflicts?.length ?? 0) > 0 && <div className="border-t bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">Existem {session.training.sync_conflicts?.length} conflitos de sincronização offline para rever.</div>}
                </section>
              ))}
              {selectedSessions.length === 0 && <div className="rounded-xl border border-dashed bg-white p-10 text-center text-sm text-slate-500">Seleciona uma ou mais sessões para trabalhar em simultâneo.</div>}
            </div>
          </div>
        )}
      </main>

      {currentSheet && <AthleteSheet key={`${currentSheet.session.training.id}-${currentSheet.athlete.id}-${currentSheet.athlete.cais_version}-${currentSheet.athlete.metrics.length}-${currentSheet.athlete.timers.length}`} session={currentSheet.session} athlete={currentSheet.athlete} tick={tick} onClose={() => setSheet(null)} onChanged={loadWorkspace} />}
    </div>
  );
}
