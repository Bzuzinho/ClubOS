import { User, EventoPresenca, Event } from '@/types';
import { useKV } from '@/hooks/useKV';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { useMemo } from 'react';

interface RegistoPresencasTabProps {
  user: User;
}

export function RegistoPresencasTab({ user }: RegistoPresencasTabProps) {
  const [presencas] = useKV<EventoPresenca[]>('club-presencas', []);
  const [events] = useKV<Event[]>('club-events', []);

  const atletaPresencas = useMemo(() => {
    return (presencas || [])
      .filter(p => p.user_id === user.id)
      .map(p => {
        const evento = (events || []).find(e => e.id === p.evento_id);
        return { ...p, evento };
      })
      .filter(p => p.evento)
      .sort((a, b) => new Date(b.evento!.data_inicio).getTime() - new Date(a.evento!.data_inicio).getTime());
  }, [presencas, events, user.id]);

  const getEstadoBadge = (estado: string) => {
    if (estado === 'presente') {
      return (
        <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">
          Presente
        </Badge>
      );
    }

    if (estado === 'justificado') {
      return (
        <Badge variant="outline" className="text-xs bg-amber-50 text-amber-700 border-amber-200">
          Justificado
        </Badge>
      );
    }

    return (
      <Badge variant="outline" className="text-xs bg-red-50 text-red-700 border-red-200">
        Ausente
      </Badge>
    );
  };

  if (atletaPresencas.length === 0) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-muted-foreground">Nenhuma presença registada</p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <p className="text-xs text-muted-foreground">Registo de presenças do atleta em eventos</p>
      <div className="border rounded-md overflow-hidden">
        <div className="max-h-[400px] overflow-y-auto">
          <Table responsive>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Evento</TableHead>
                <TableHead className="text-xs">Data</TableHead>
                <TableHead className="text-xs">Estado</TableHead>
                <TableHead className="text-xs">Hora Chegada</TableHead>
                <TableHead className="text-xs">Observações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {atletaPresencas.map((p) => {
                const evento = p.evento!;

                return (
                  <TableRow key={p.id}>
                    <TableCell label="Evento" className="text-xs font-medium">{evento.titulo}</TableCell>
                    <TableCell label="Data" className="text-xs">
                      {format(new Date(evento.data_inicio), 'dd/MM/yyyy', { locale: pt })}
                    </TableCell>
                    <TableCell label="Estado" className="text-xs">{getEstadoBadge(p.estado)}</TableCell>
                    <TableCell label="Hora Chegada" className="text-xs">{p.hora_chegada || '-'}</TableCell>
                    <TableCell label="Observações" className="text-xs">{p.observacoes || '-'}</TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      </div>
    </div>
  );
}
