import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { CaisAthleteList } from '@/components/sports/cais/CaisAthleteList';
import { CaisContextPanel } from '@/components/sports/cais/CaisContextPanel';
import { CaisRegisterDialogs } from '@/components/sports/cais/CaisRegisterDialogs';
import { useCaisWorkspace } from '@/components/sports/cais/useCaisWorkspace';
import type { CaisPageProps } from '@/components/sports/cais/types';

export default function CaisWorkspace({ date, sessions, selectedSession = null, metricDefinitions = [] }: CaisPageProps) {
  const state = useCaisWorkspace(selectedSession, metricDefinitions);

  return (
    <AuthenticatedLayout fullWidth collapseSidebarDesktop hideMobileHeader>
      <Head title="Modo Cais" />
      <div className="min-h-screen bg-slate-50 px-2 py-2 sm:px-3">
        <div className="mb-2 flex items-center justify-between rounded-lg border bg-white px-3 py-2">
          <div>
            <h1 className="text-sm font-semibold">Modo Cais</h1>
            <p className="text-[11px] text-muted-foreground">Operação de treino · sem menu lateral</p>
          </div>
          <div className="flex items-center gap-2">
            <span className={`text-[11px] ${state.syncState === 'error' ? 'text-rose-700' : state.syncState === 'saving' ? 'text-amber-700' : 'text-emerald-700'}`}>
              {state.syncState === 'saving' ? 'A sincronizar…' : state.syncState === 'error' ? 'Erro de sincronização' : '● Sincronizado'}
            </span>
            <Button size="sm" variant="outline" onClick={() => router.get(route('desportivo.treinos'))}>Treinos</Button>
          </div>
        </div>

        <div className="mb-2 flex gap-2 overflow-x-auto pb-1">
          {sessions.map((session) => (
            <button key={session.id} type="button" onClick={() => router.get(route('desportivo.cais'), { date, training_id: session.id }, { preserveScroll: true })} className={`min-w-[190px] rounded-lg border px-3 py-2 text-left ${selectedSession?.id === session.id ? 'border-sky-300 bg-sky-50' : 'bg-white hover:bg-muted/30'}`}>
              <div className="text-xs font-semibold">{session.start_time ?? '--:--'} · {session.label}</div>
              <div className="mt-0.5 text-[10px] text-muted-foreground">{session.number ?? 'Treino'} · {session.volume_m.toLocaleString('pt-PT')} m</div>
            </button>
          ))}
          <button type="button" onClick={() => { const next = window.prompt('Data do treino (AAAA-MM-DD):', date); if (next) router.get(route('desportivo.cais'), { date: next }); }} className="min-w-[170px] rounded-lg border bg-white px-3 py-2 text-left hover:bg-muted/30">
            <div className="text-xs font-semibold">+ Outro treino</div>
            <div className="text-[10px] text-muted-foreground">Selecionar por data</div>
          </button>
        </div>

        {selectedSession ? (
          <>
            <div className="mb-2">
              <h2 className="text-base font-semibold">{selectedSession.number ?? 'Treino'} · {selectedSession.training_type ?? selectedSession.label}</h2>
              <p className="text-[11px] text-muted-foreground">{selectedSession.start_time}–{selectedSession.end_time} · {selectedSession.label} · {selectedSession.coach ?? 'Sem treinador'}</p>
              <div className="mt-1 flex gap-1">
                <Badge variant="outline" className="text-[10px]">{selectedSession.venue ?? 'Sem local'}{selectedSession.pool ? ` · ${selectedSession.pool}` : ''}</Badge>
                {selectedSession.pool_length_m && <Badge variant="outline" className="text-[10px]">{selectedSession.pool_length_m} m</Badge>}
              </div>
            </div>

            <div className="grid gap-2 lg:grid-cols-[minmax(340px,0.8fr)_minmax(760px,1.7fr)]">
              <CaisContextPanel session={selectedSession} />
              <Card>
                <CardHeader className="border-b py-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-sm">Atletas</CardTitle>
                    <div className="flex items-center gap-2">
                      <Input value={state.search} onChange={(event) => state.setSearch(event.target.value)} placeholder="Pesquisar atleta…" className="h-8 w-[220px] text-xs" />
                      <div className="flex rounded-md bg-muted p-0.5">
                        <Button size="sm" variant={state.view === 'list' ? 'secondary' : 'ghost'} className="h-7 px-2 text-xs" onClick={() => state.setView('list')}>Lista</Button>
                        <Button size="sm" variant={state.view === 'cards' ? 'secondary' : 'ghost'} className="h-7 px-2 text-xs" onClick={() => state.setView('cards')}>Cards</Button>
                      </div>
                    </div>
                  </div>
                </CardHeader>
                <div className="flex flex-wrap gap-1.5 border-b bg-muted/20 px-2 py-2 text-[10px]">
                  <Badge variant="outline">{state.athletes.length} previstos</Badge>
                  <Badge variant="outline">{state.counters.presente} presentes</Badge>
                  <Badge variant="outline">{state.counters.ausente} ausentes</Badge>
                  <Badge variant="outline">{state.counters.dispensado} dispensados</Badge>
                  <Badge variant="outline">{state.counters.atrasado} atrasados</Badge>
                  <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">Por defeito: Presente</Badge>
                </div>
                <CaisAthleteList athletes={state.filteredAthletes} view={state.view} onPresence={(athlete, status) => void state.setPresence(athlete, status)} onQuick={state.openQuick} onFull={state.openFull} />
              </Card>
            </div>
          </>
        ) : (
          <Card><CardContent className="py-16 text-center text-sm text-muted-foreground">Não existem sessões operacionais nesta data.</CardContent></Card>
        )}
      </div>

      <CaisRegisterDialogs
        quick={state.quick}
        quickAthlete={state.quickAthlete}
        quickDefinition={state.quickDefinition}
        onQuickOpen={(open) => state.setQuick((current) => ({ ...current, open }))}
        onQuickValue={(value) => state.setQuick((current) => ({ ...current, value }))}
        onQuickSave={() => void state.saveQuick()}
        full={state.full}
        fullAthlete={state.fullAthlete}
        behaviorDefinition={state.behaviorDefinition}
        materialDefinition={state.materialDefinition}
        extraDefinitions={state.extraDefinitions}
        onFullChange={(patch) => state.setFull((current) => ({ ...current, ...patch }))}
        onFullSave={(event) => void state.saveFull(event)}
      />
    </AuthenticatedLayout>
  );
}
