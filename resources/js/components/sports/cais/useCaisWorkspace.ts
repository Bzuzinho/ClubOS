import { FormEvent, useEffect, useMemo, useState } from 'react';
import { caisJsonRequest } from './api';
import type { FullRegisterState } from './CaisRegisterDialogs';
import type { AthleteRegister, CaisAthlete, CaisStatus, MetricDefinition, QuickType, SelectedSession, ViewMode } from './types';

export function useCaisWorkspace(selectedSession: SelectedSession | null, metricDefinitions: MetricDefinition[]) {
  const [athletes, setAthletes] = useState<CaisAthlete[]>(selectedSession?.athletes ?? []);
  const [search, setSearch] = useState('');
  const [view, setView] = useState<ViewMode>('list');
  const [syncState, setSyncState] = useState<'idle' | 'saving' | 'error'>('idle');
  const [quick, setQuick] = useState<{ open: boolean; athleteId: string | null; type: QuickType; value: string | null }>({ open: false, athleteId: null, type: 'behavior', value: null });
  const [full, setFull] = useState<FullRegisterState>({ open: false, athleteId: null, status: 'presente', behavior: '', material: '', technical_note: '', advice: '', metrics: {} });

  useEffect(() => setAthletes(selectedSession?.athletes ?? []), [selectedSession?.id, selectedSession?.athletes]);

  const behaviorDefinition = metricDefinitions.find((item) => item.code === 'behavior');
  const materialDefinition = metricDefinitions.find((item) => item.code === 'material');
  const extraDefinitions = metricDefinitions.filter((item) => !['behavior', 'material'].includes(item.code));
  const filteredAthletes = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase('pt-PT');
    return needle ? athletes.filter((athlete) => athlete.name.toLocaleLowerCase('pt-PT').includes(needle)) : athletes;
  }, [athletes, search]);
  const counters = useMemo(() => ({
    presente: athletes.filter((item) => item.status === 'presente').length,
    ausente: athletes.filter((item) => item.status === 'ausente').length,
    dispensado: athletes.filter((item) => item.status === 'dispensado').length,
    atrasado: athletes.filter((item) => item.status === 'atrasado').length,
  }), [athletes]);

  const quickAthlete = quick.athleteId ? athletes.find((item) => item.id === quick.athleteId) ?? null : null;
  const fullAthlete = full.athleteId ? athletes.find((item) => item.id === full.athleteId) ?? null : null;
  const quickDefinition = quick.type === 'behavior' ? behaviorDefinition : materialDefinition;
  const patchAthlete = (athleteId: string, patch: Partial<CaisAthlete>) => setAthletes((rows) => rows.map((row) => row.id === athleteId ? { ...row, ...patch } : row));
  const patchRegister = (athleteId: string, register: AthleteRegister, status?: CaisStatus) => setAthletes((rows) => rows.map((row) => row.id === athleteId ? { ...row, register, status: status ?? row.status } : row));

  const setPresence = async (athlete: CaisAthlete, status: CaisStatus) => {
    if (!selectedSession) return;
    const previous = athlete.status;
    patchAthlete(athlete.id, { status });
    setSyncState('saving');
    try {
      await caisJsonRequest(route('desportivo.cais.presence', { training: selectedSession.id, athlete: athlete.id }), 'PATCH', { status });
      setSyncState('idle');
    } catch (error) {
      patchAthlete(athlete.id, { status: previous });
      setSyncState('error');
      window.alert(error instanceof Error ? error.message : 'Não foi possível atualizar a presença.');
    }
  };

  const openQuick = (athlete: CaisAthlete, type: QuickType) => setQuick({
    open: true, athleteId: athlete.id, type,
    value: type === 'behavior' ? athlete.register.behavior ?? null : athlete.register.material ?? null,
  });

  const saveQuick = async () => {
    if (!selectedSession || !quick.athleteId || !quickDefinition) return;
    setSyncState('saving');
    try {
      const result = await caisJsonRequest<{ status: CaisStatus; register: AthleteRegister }>(route('desportivo.cais.quick', { training: selectedSession.id, athlete: quick.athleteId }), 'POST', { code: quickDefinition.code, value: quick.value });
      patchRegister(quick.athleteId, result.register, result.status);
      setQuick((current) => ({ ...current, open: false }));
      setSyncState('idle');
    } catch (error) {
      setSyncState('error');
      window.alert(error instanceof Error ? error.message : 'Não foi possível guardar o registo rápido.');
    }
  };

  const openFull = (athlete: CaisAthlete) => setFull({
    open: true, athleteId: athlete.id, status: athlete.status,
    behavior: athlete.register.behavior ?? '', material: athlete.register.material ?? '',
    technical_note: athlete.register.technical_note ?? '', advice: athlete.register.advice ?? '',
    metrics: Object.fromEntries((athlete.register.metrics ?? []).map((metric) => [metric.code, metric.value ?? ''])),
  });

  const saveFull = async (event: FormEvent) => {
    event.preventDefault();
    if (!selectedSession || !full.athleteId) return;
    setSyncState('saving');
    try {
      const result = await caisJsonRequest<{ status: CaisStatus; register: AthleteRegister }>(route('desportivo.cais.register', { training: selectedSession.id, athlete: full.athleteId }), 'PUT', {
        status: full.status, behavior: full.behavior || null, material: full.material || null,
        technical_note: full.technical_note || null, advice: full.advice || null,
        metrics: extraDefinitions.map((definition) => ({ code: definition.code, value: full.metrics[definition.code] || null })),
      });
      patchRegister(full.athleteId, result.register, result.status);
      setFull((current) => ({ ...current, open: false }));
      setSyncState('idle');
    } catch (error) {
      setSyncState('error');
      window.alert(error instanceof Error ? error.message : 'Não foi possível guardar o registo.');
    }
  };

  return {
    athletes, search, setSearch, view, setView, syncState, filteredAthletes, counters,
    behaviorDefinition, materialDefinition, extraDefinitions, quickDefinition, quickAthlete, fullAthlete,
    quick, setQuick, full, setFull, setPresence, openQuick, saveQuick, openFull, saveFull,
  };
}
