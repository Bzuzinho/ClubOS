import { useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

type Timing = { id: string; exercise: string | null; distance_m: number; stroke: string | null; round: number; repetition: number; final_ms: number | null; splits: { sequence: number; elapsed_ms: number }[] };
type Metric = { id: string; metric: string; value: string | null; unit: string | null; note: string | null; exercise: string | null };
type Register = { id: string; code: string; value: string | null; note: string | null };
type Records = { execution: Timing[]; live_metrics: Metric[]; cais_registers: Register[] };
const seconds = (ms: number | null) => ms === null ? '—' : `${(ms / 1000).toFixed(3)} s`;
const labels: Record<string, string> = { behavior: 'Comportamento', material: 'Material', technical_note: 'Nota técnica', advice: 'Aconselhamento' };

export function TrainingRecords({ athleteId, trainingId, number, onClose }: { athleteId: string; trainingId: string; number: string; onClose: () => void }) {
  const section = useRef<HTMLElement>(null);
  useEffect(() => { section.current?.scrollIntoView({ block: 'start', inline: 'nearest' }); }, [trainingId]);
  const query = useQuery({
    queryKey: ['member-training-records', athleteId, trainingId],
    queryFn: async ({ signal }) => (await axios.get<{ data: Records[] }>(route('desportivo.registos.athlete', { athlete: athleteId }), {
      params: { training_id: trainingId }, signal,
    })).data.data[0] ?? null,
  });
  const records = query.data;
  return (
    <section ref={section} aria-label={`Registos de ${number}`} className="min-w-0 space-y-3 rounded-lg border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">Registos · {number}</h3>
        <Button variant="outline" size="sm" onClick={onClose}>Fechar registos</Button>
      </div>
      {query.isPending && <p role="status" className="text-sm">A carregar registos...</p>}
      {query.isError && <div role="alert" className="space-y-2"><p className="text-sm">Não foi possível consultar os registos. Verifique se tem acesso a Registos desportivos.</p><Button variant="outline" onClick={() => void query.refetch()}>Tentar novamente</Button></div>}
      {query.isSuccess && !records && <p className="text-sm">Não foi encontrada uma participação neste treino.</p>}
      {records && <>
        <h4 className="text-sm font-semibold">Tempos do Live</h4>
        {records.execution.length === 0 ? <p className="text-xs text-muted-foreground">Sem tempos consolidados.</p> : <Table responsive>
          <TableHeader><TableRow>{['Exercício', 'Distância / estilo', 'Volta / repetição', 'Tempo final', 'Parciais'].map(label => <TableHead key={label}>{label}</TableHead>)}</TableRow></TableHeader>
          <TableBody>{records.execution.map(row => <TableRow key={row.id}>
            <TableCell label="Exercício">{row.exercise || 'Medição livre'}</TableCell>
            <TableCell label="Distância / estilo">{row.distance_m} m · {row.stroke || '—'}</TableCell>
            <TableCell label="Volta / repetição">{row.round} / {row.repetition}</TableCell>
            <TableCell label="Tempo final">{seconds(row.final_ms)}</TableCell>
            <TableCell label="Parciais">{row.splits.length ? row.splits.map(split => seconds(split.elapsed_ms)).join(' · ') : '—'}</TableCell>
          </TableRow>)}</TableBody>
        </Table>}
        <h4 className="text-sm font-semibold">Métricas do Live</h4>
        {records.live_metrics.length === 0 ? <p className="text-xs text-muted-foreground">Sem métricas registadas.</p> : <ul className="space-y-2">{records.live_metrics.map(row => <li key={row.id} className="rounded border p-2 text-xs [overflow-wrap:anywhere]">
          <p><strong>{row.metric}</strong>: {row.value ?? '—'} {row.unit}</p>
          {row.exercise && <p>Exercício: {row.exercise}</p>}{row.note && <p>Observação: {row.note}</p>}
        </li>)}</ul>}
        <h4 className="text-sm font-semibold">Registos do Cais</h4>
        {records.cais_registers.length === 0 ? <p className="text-xs text-muted-foreground">Sem registos do Cais.</p> : <ul className="space-y-2">{records.cais_registers.map(row => <li key={row.id} className="rounded border p-2 text-xs [overflow-wrap:anywhere]">
          <p><strong>{labels[row.code] || row.code}</strong>: {row.value ?? '—'}</p>
          {row.note && row.note !== row.value && <p>Observação: {row.note}</p>}
        </li>)}</ul>}
      </>}
    </section>
  );
}
