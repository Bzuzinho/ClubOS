import { TrainingRecords } from './TrainingRecords';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import { format } from 'date-fns';
import { User } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

interface Participation {
  id: string;
  training_id: string;
  numero_treino: string | null;
  data: string | null;
  tipo_treino: string | null;
  descricao_treino: string | null;
  session_status: string | null;
  season: string | null;
  age_groups: string[];
  attendance_status: string;
}

interface HistoryPage {
  can_view_records: boolean;
  data: Participation[];
  current_page: number;
  last_page: number;
  total: number;
}

const sessionLabels: Record<string, string> = {
  draft: 'Rascunho', published: 'Publicado', completed: 'Concluído', cancelled: 'Cancelado',
};
const attendanceLabels: Record<string, string> = {
  presente: 'Presente', ausente: 'Ausente', atrasado: 'Atrasado', justificado: 'Justificado',
  lesionado: 'Lesionado', limitado: 'Limitado', doente: 'Doente', dispensado: 'Dispensado',
};

export function TreinosTab({ user }: { user: User }) {
  return <AthleteHistory key={user.id} athleteId={user.id} />;
}

function AthleteHistory({ athleteId }: { athleteId: string }) {
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Participation | null>(null);
  const history = useQuery<HistoryPage>({
    queryKey: ['member-training-history', athleteId, page],
    enabled: Boolean(athleteId),
    queryFn: async ({ signal }) => {
      const response = await axios.get<HistoryPage>('/api/desportivo/trainings', {
        params: { athlete_id: athleteId, page }, signal,
      });
      return response.data;
    },
  });

  if (!athleteId) return <p className="p-4 text-sm text-muted-foreground">Guarde a ficha para consultar as participações em treinos.</p>;
  if (history.isPending) return <p role="status" className="p-4 text-sm text-muted-foreground">A carregar participações em treinos...</p>;
  if (history.isError) return (
    <div role="alert" className="space-y-2 p-4">
      <p className="text-sm text-red-600">Não foi possível carregar as participações em treinos.</p>
      <Button variant="outline" onClick={() => void history.refetch()}>Tentar novamente</Button>
    </div>
  );

  const result = history.data;
  return (
    <div className="space-y-2">
      <Card className="p-2">
        <p className="text-xs text-muted-foreground">
          Treinos em que o atleta foi incluído e presenças registadas no Cais, independentemente do escalão atual.
        </p>
      </Card>
      {result.data.length === 0 ? <p className="p-4 text-sm text-muted-foreground">Sem participações em treinos nesta página.</p> : (
        <div className="border rounded-lg">
          <Table responsive>
            <TableHeader><TableRow>
              {['Treino', 'Data', 'Época', 'Tipo', 'Escalões do treino', 'Descrição', 'Estado do treino', 'Presença'].map(label => (
                <TableHead key={label} className="text-xs">{label}</TableHead>
              ))}
            </TableRow></TableHeader>
            <TableBody>{result.data.map(training => (
              <TableRow key={training.id}>
                <TableCell label="Treino" className="text-xs font-medium">{training.numero_treino || '—'}
                  {result.can_view_records && <Button variant="outline" size="sm" className="mt-2" aria-expanded={selected?.id === training.id} onClick={() => setSelected(selected?.id === training.id ? null : training)}>Ver registos</Button>}
                </TableCell>
                <TableCell label="Data" className="text-xs">{training.data ? format(new Date(`${training.data}T12:00:00`), 'dd/MM/yyyy') : '—'}</TableCell>
                <TableCell label="Época" className="text-xs">{training.season || 'Sem época associada'}</TableCell>
                <TableCell label="Tipo" className="text-xs">{training.tipo_treino || '—'}</TableCell>
                <TableCell label="Escalões do treino" className="text-xs">{training.age_groups.join(', ') || 'Sem escalão associado'}</TableCell>
                <TableCell label="Descrição" className="text-xs">{training.descricao_treino || '—'}</TableCell>
                <TableCell label="Estado do treino" className="text-xs"><Badge variant="outline">{sessionLabels[training.session_status || ''] || 'Não definido'}</Badge></TableCell>
                <TableCell label="Presença" className="text-xs"><Badge variant="outline">{attendanceLabels[training.attendance_status] || training.attendance_status}</Badge></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        </div>
      )}
      {selected && result.can_view_records && <TrainingRecords athleteId={athleteId} trainingId={selected.training_id} number={selected.numero_treino || 'Treino'} onClose={() => setSelected(null)} />}
      <nav aria-label="Páginas de participações em treinos" className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs">{result.total} participações · Página {result.current_page} de {result.last_page}</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => { setSelected(null); setPage(page - 1); }}>Anterior</Button>
          <Button variant="outline" size="sm" disabled={page >= result.last_page} onClick={() => { setSelected(null); setPage(page + 1); }}>Seguinte</Button>
        </div>
      </nav>
    </div>
  );
}
