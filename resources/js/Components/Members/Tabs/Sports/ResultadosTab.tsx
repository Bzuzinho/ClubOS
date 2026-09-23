import { CompetitionHistory } from './CompetitionHistory';
import type { User, ResultadoProva, Event } from '@/types';
import { useKV } from '@/hooks/useKV';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { Trophy } from 'lucide-react';
import { useMemo } from 'react';

interface ResultadosTabProps {
  user: User;
}

export function ResultadosTab({ user }: ResultadosTabProps) {
  const [resultadosProvas] = useKV<ResultadoProva[]>('club-resultados-provas', []);
  const [events] = useKV<Event[]>('club-events', []);

  const atletaResultados = useMemo(() => {
    return (resultadosProvas || [])
      .filter((result) => result.atleta_id === user.id)
      .sort((a, b) => new Date(b.data).getTime() - new Date(a.data).getTime());
  }, [resultadosProvas, user.id]);

  return (
    <div className="space-y-4">
      <CompetitionHistory key={user.id} athleteId={user.id} />

      <section className="space-y-2" aria-label="Resultados de Eventos">
        <div>
          <h3 className="text-sm font-semibold">Resultados de Eventos</h3>
          <p className="text-xs text-muted-foreground">
            Consulta de registos associados a Eventos. A criação, edição e eliminação destes registos é gerida em Eventos &gt; Resultados.
          </p>
        </div>

        {atletaResultados.length === 0 ? (
          <div className="rounded-lg border p-8 text-center">
            <Trophy className="mx-auto mb-2 text-muted-foreground" size={32} />
            <p className="text-sm text-muted-foreground">Nenhum resultado de Evento registado</p>
          </div>
        ) : (
          <ScrollArea className="h-[400px] rounded-lg border">
            <Table responsive>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Evento</TableHead>
                  <TableHead className="text-xs">Local</TableHead>
                  <TableHead className="text-xs">Data</TableHead>
                  <TableHead className="text-xs">Prova</TableHead>
                  <TableHead className="text-xs">Piscina</TableHead>
                  <TableHead className="text-xs">Tempo</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {atletaResultados.map((resultado) => {
                  const evento = events?.find((candidate) => candidate.id === resultado.evento_id);
                  const eventoNome = resultado.evento_nome || evento?.titulo || '-';
                  const piscinaLabel = resultado.piscina === 'piscina_25m'
                    ? '25m'
                    : resultado.piscina === 'piscina_50m'
                      ? '50m'
                      : 'Águas Abertas';

                  return (
                    <TableRow key={resultado.id}>
                      <TableCell label="Evento" className="text-xs font-medium">{eventoNome}</TableCell>
                      <TableCell label="Local" className="text-xs">{resultado.local}</TableCell>
                      <TableCell label="Data" className="text-xs">
                        {format(new Date(resultado.data), 'dd/MM/yyyy', { locale: pt })}
                      </TableCell>
                      <TableCell label="Prova" className="text-xs">{resultado.prova}</TableCell>
                      <TableCell label="Piscina" className="text-xs">
                        <Badge variant="outline" className="text-xs">{piscinaLabel}</Badge>
                      </TableCell>
                      <TableCell label="Tempo" className="text-xs font-semibold">{resultado.tempo_final}</TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </ScrollArea>
        )}
      </section>
    </div>
  );
}
