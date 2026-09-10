import { useMemo } from 'react';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { User } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useAgeGroups } from '@/hooks/useAgeGroups';
import { useTrainings } from '@/hooks/sports';

interface TreinosTabProps {
  user: User;
}

export function TreinosTab({ user }: TreinosTabProps) {
  const { data: trainings = [], loading, error } = useTrainings();
  const { data: ageGroups = [] } = useAgeGroups();

  const athleteEscaloes = useMemo(() => {
    if (Array.isArray((user as any).escalao) && (user as any).escalao.length > 0) {
      return (user as any).escalao.filter(Boolean);
    }

    if ((user as any).escalao_id) {
      return [String((user as any).escalao_id)];
    }

    return [] as string[];
  }, [user]);

  const ageGroupLabelById = useMemo(() => {
    return new Map((ageGroups || []).map((group) => [group.id, group.nome]));
  }, [ageGroups]);

  const trainingsByAgeGroup = useMemo(() => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return (trainings || [])
      .filter((training) => {
        if (!training.data || !Array.isArray(training.escaloes) || training.escaloes.length === 0) {
          return false;
        }

        return training.escaloes.some((escalaoId) => athleteEscaloes.includes(escalaoId));
      })
      .map((training) => {
        const trainingDate = new Date(training.data as string);
        trainingDate.setHours(0, 0, 0, 0);

        return {
          ...training,
          estadoTreino: trainingDate >= today ? ('agendado' as const) : ('concluido' as const),
        };
      })
      .sort((left, right) => {
        if (left.estadoTreino !== right.estadoTreino) {
          return left.estadoTreino === 'agendado' ? -1 : 1;
        }

        const leftTime = new Date(left.data as string).getTime();
        const rightTime = new Date(right.data as string).getTime();

        if (left.estadoTreino === 'agendado') {
          return leftTime - rightTime;
        }

        return rightTime - leftTime;
      });
  }, [trainings, athleteEscaloes]);

  const getEscaloesLabel = (escalaoIds?: string[] | null) => {
    if (!Array.isArray(escalaoIds) || escalaoIds.length === 0) {
      return 'Sem escalão';
    }

    return escalaoIds
      .map((escalaoId) => ageGroupLabelById.get(escalaoId) ?? escalaoId)
      .join(', ');
  };

  const getEstadoBadge = (estadoTreino: 'agendado' | 'concluido') => {
    if (estadoTreino === 'agendado') {
      return <Badge variant="outline" className="text-xs bg-orange-50 text-orange-700 border-orange-200">Agendado</Badge>;
    }

    return <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">Concluído</Badge>;
  };

  if (loading) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-muted-foreground">A carregar treinos do escalão do atleta...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-red-600">Não foi possível carregar os treinos.</p>
      </div>
    );
  }

  if (athleteEscaloes.length === 0) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-muted-foreground">O atleta não tem escalão atribuído.</p>
      </div>
    );
  }

  if (trainingsByAgeGroup.length === 0) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-muted-foreground">Sem treinos agendados ou concluídos para o escalão do atleta.</p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <Card className="p-2">
        <p className="text-xs text-muted-foreground">
          Treinos agendados e concluídos para o escalão do atleta.
        </p>
      </Card>

      <div className="max-h-[420px] overflow-y-auto border rounded-lg">
        <Table responsive>
          <TableHeader>
            <TableRow>
              <TableHead className="text-xs">Treino</TableHead>
              <TableHead className="text-xs">Data</TableHead>
              <TableHead className="text-xs">Tipo</TableHead>
              <TableHead className="text-xs">Escalão</TableHead>
              <TableHead className="text-xs">Descrição</TableHead>
              <TableHead className="text-xs">Estado</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {trainingsByAgeGroup.map((training) => (
              <TableRow key={training.id}>
                <TableCell label="Treino" className="text-xs font-medium">{training.numero_treino || '-'}</TableCell>
                <TableCell label="Data" className="text-xs whitespace-nowrap">
                  {training.data ? format(new Date(training.data), 'dd/MM/yyyy', { locale: pt }) : '-'}
                </TableCell>
                <TableCell label="Tipo" className="text-xs">{training.tipo_treino || '-'}</TableCell>
                <TableCell label="Escalão" className="text-xs">{getEscaloesLabel(training.escaloes)}</TableCell>
                <TableCell label="Descrição" className="text-xs" title={training.descricao_treino || ''}>
                  {training.descricao_treino || '-'}
                </TableCell>
                <TableCell label="Estado" className="text-xs">{getEstadoBadge(training.estadoTreino)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
