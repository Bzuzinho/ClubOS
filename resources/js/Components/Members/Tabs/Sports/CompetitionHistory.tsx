import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { format } from 'date-fns';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

interface CompetitionResult {
  id: string;
  competition_nome: string;
  competition_date: string | null;
  competition_local: string | null;
  competition_status: string | null;
  prova: string;
  tempo_oficial: string | number | null;
  posicao: number | null;
  pontos_fina: number | null;
  status: string;
  observacoes: string | null;
  splits: { distance_m: number; time: string | number }[];
}
interface ResultsPage {
  data: CompetitionResult[];
  total: number;
  current_page: number;
  last_page: number;
}
const statusLabels: Record<string, string> = { ok: 'Válido', dsq: 'Desclassificado', dns: 'Não partiu', dnf: 'Não terminou' };
const time = (value: string | number | null) => value === null ? '—' : `${Number(value).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} s`;

export function CompetitionHistory({ athleteId }: { athleteId: string }) {
  const [page, setPage] = useState(1);
  const query = useQuery<ResultsPage>({
    queryKey: ['member-competition-history', athleteId, page],
    enabled: Boolean(athleteId),
    queryFn: async ({ signal }) => (await axios.get<ResultsPage>('/api/desportivo/competition-results', {
      params: { athlete_id: athleteId, page }, signal,
    })).data,
  });
  return <section aria-label="Histórico de competições" className="min-w-0 space-y-3">
    <h3 className="text-sm font-semibold">Resultados de Competições</h3>
    <p className="text-xs text-muted-foreground">Resultados oficiais e parciais do atleta, independentemente do escalão atual. Tempos apresentados em segundos.</p>
    {!athleteId ? <p className="text-sm">Guarde a ficha para consultar os resultados.</p> : query.isPending ? <p role="status" className="text-sm">A carregar resultados de competições...</p> : query.isError ? <div role="alert" className="space-y-2">
      <p className="text-sm">{axios.isAxiosError(query.error) && query.error.response?.status === 403 ? 'Sem permissão para consultar Resultados desportivos.' : 'Não foi possível carregar os resultados de competições.'}</p>
      <Button variant="outline" size="sm" onClick={() => void query.refetch()}>Tentar novamente</Button>
    </div> : query.data && <>
      {query.data.data.length === 0 ? <p className="text-sm text-muted-foreground">Sem resultados de competições nesta página.</p> : <Table responsive>
        <TableHeader><TableRow>{['Competição', 'Data', 'Local', 'Prova', 'Estado', 'Tempo oficial', 'Classificação', 'Pontos FINA', 'Parciais', 'Observações'].map(label => <TableHead key={label}>{label}</TableHead>)}</TableRow></TableHeader>
        <TableBody>{query.data.data.map(result => <TableRow key={result.id}>
          <TableCell label="Competição">{result.competition_nome}{result.competition_status === 'cancelled' && <p className="text-xs">Competição cancelada</p>}</TableCell>
          <TableCell label="Data">{result.competition_date ? format(new Date(`${result.competition_date}T12:00:00`), 'dd/MM/yyyy') : '—'}</TableCell>
          <TableCell label="Local">{result.competition_local || '—'}</TableCell>
          <TableCell label="Prova">{result.prova}</TableCell>
          <TableCell label="Estado">{statusLabels[result.status] || result.status}</TableCell>
          <TableCell label="Tempo oficial">{time(result.tempo_oficial)}</TableCell>
          <TableCell label="Classificação">{result.posicao ?? '—'}</TableCell>
          <TableCell label="Pontos FINA">{result.pontos_fina ?? '—'}</TableCell>
          <TableCell label="Parciais">{result.splits.length ? result.splits.map(split => `${split.distance_m} m: ${time(split.time)}`).join(' · ') : '—'}</TableCell>
          <TableCell label="Observações">{result.observacoes || '—'}</TableCell>
        </TableRow>)}</TableBody>
      </Table>}
      <nav aria-label="Páginas de resultados de competições" className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs">{query.data.total} resultados · Página {query.data.current_page} de {query.data.last_page}</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
          <Button variant="outline" size="sm" disabled={page >= query.data.last_page} onClick={() => setPage(page + 1)}>Seguinte</Button>
        </div>
      </nav>
    </>}
  </section>;
}
