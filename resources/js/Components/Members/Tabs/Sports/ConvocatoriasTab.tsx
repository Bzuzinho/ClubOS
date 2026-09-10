import { User, ConvocatoriaAtleta, ConvocatoriaGrupo, Event } from '@/types';
import { useKV } from '@/hooks/useKV';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import axios from 'axios';

interface Prova {
  id: string;
  name: string;
}

interface NavigationContext {
  eventId?: string;
  convocatoriaId?: string;
  tab?: string;
}

interface ConvocatoriasTabProps {
  user: User;
  onNavigate?: (view: string, context?: NavigationContext) => void;
}

export function ConvocatoriasTab({ user, onNavigate }: ConvocatoriasTabProps) {
  const [convocatoriasAtleta] = useKV<ConvocatoriaAtleta[]>('club-convocatorias-atleta', []);
  const [convocatoriasGrupo] = useKV<ConvocatoriaGrupo[]>('club-convocatorias-grupo', []);
  const [events] = useKV<Event[]>('club-events', []);
  const [cachedProvas, setCachedProvas] = useKV<Prova[]>('club-prova-tipos', []);
  const [selectedConvocatoria, setSelectedConvocatoria] = useState<any | null>(null);
  const [provasLoading, setProvasLoading] = useState((cachedProvas || []).length === 0);
  const hasRequestedProvasRef = useRef(false);

  useEffect(() => {
    if ((cachedProvas || []).length > 0) {
      setProvasLoading(false);
      return;
    }

    if (hasRequestedProvasRef.current) {
      return;
    }

    hasRequestedProvasRef.current = true;
    let active = true;
    setProvasLoading(true);

    void axios.get('/api/prova-tipos')
      .then((response) => {
        if (!active) return;

        const payload = Array.isArray(response.data) ? response.data : [];
        void setCachedProvas(payload.map((prova: any) => ({
          id: String(prova.id),
          name: prova.nome || prova.name || prova.id,
        })));
        setProvasLoading(false);
      })
      .catch(() => {
        if (!active) return;
        setProvasLoading(false);
      });

    return () => {
      active = false;
    };
  }, [cachedProvas, setCachedProvas]);

  const provas = cachedProvas || [];

  const provaNameById = useMemo(() => {
    return new Map((provas || []).map((prova) => [prova.id, prova.name]));
  }, [provas]);

  const convocatoriasGrupoById = useMemo(() => {
    return new Map((convocatoriasGrupo || []).map((grupo) => [grupo.id, grupo]));
  }, [convocatoriasGrupo]);

  const eventsById = useMemo(() => {
    return new Map((events || []).map((evento) => [evento.id, evento]));
  }, [events]);

  const atletaConvocatorias = useMemo(() => {
    const userId = String(user.id);
    const atletaConvs = (convocatoriasAtleta || []).filter((ca) => String(ca.atleta_id) === userId);

    return atletaConvs.map(ca => {
      const grupo = convocatoriasGrupoById.get(ca.convocatoria_grupo_id);
      const evento = grupo ? eventsById.get(grupo.evento_id) : undefined;
      return { ...ca, grupo, evento };
    }).filter(ca => ca.evento && ca.grupo)
      .sort((a, b) => new Date(b.evento!.data_inicio).getTime() - new Date(a.evento!.data_inicio).getTime());
  }, [convocatoriasAtleta, convocatoriasGrupoById, eventsById, user.id]);

  const getProvaLabels = (provasIds: string[] | undefined) => {
    const ids = Array.isArray(provasIds) ? provasIds : [];
    return ids
      .map((provaId) => provaNameById.get(String(provaId)) || null)
      .filter((label): label is string => Boolean(label));
  };

  const getEstadoBadge = (estado: string) => {
    switch (estado) {
      case 'concluido':
        return <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">Concluído</Badge>;
      case 'em_curso':
        return <Badge variant="outline" className="text-xs bg-blue-50 text-blue-700 border-blue-200">Em Curso</Badge>;
      case 'agendado':
        return <Badge variant="outline" className="text-xs bg-orange-50 text-orange-700 border-orange-200">Agendado</Badge>;
      case 'cancelado':
        return <Badge variant="outline" className="text-xs bg-red-50 text-red-700 border-red-200">Cancelado</Badge>;
      default:
        return <Badge variant="outline" className="text-xs">Rascunho</Badge>;
    }
  };

  const getConfirmacaoBadge = (convocatoria: ConvocatoriaAtleta) => {
    if (convocatoria.confirmado) {
      return <Badge variant="outline" className="text-xs bg-green-50 text-green-700 border-green-200">Confirmado</Badge>;
    }

    return <Badge variant="outline" className="text-xs bg-amber-50 text-amber-700 border-amber-200">Pendente</Badge>;
  };

  const openConvocatoria = (convocatoria: any) => {
    setSelectedConvocatoria(convocatoria);
  };

  if (atletaConvocatorias.length === 0) {
    return (
      <div className="p-8 border rounded-lg text-center">
        <p className="text-sm text-muted-foreground">Nenhuma convocatória registada</p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <p className="text-xs text-muted-foreground">Lista de convocatórias onde o atleta foi incluído</p>
      <div className="border rounded-md overflow-hidden">
        <div className="max-h-[400px] overflow-y-auto">
          <Table responsive>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Evento</TableHead>
                <TableHead className="text-xs">Data</TableHead>
                <TableHead className="text-xs">Estado</TableHead>
                <TableHead className="text-xs">Provas</TableHead>
                <TableHead className="text-xs text-right">Consultar</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {atletaConvocatorias.map((ca) => {
                const evento = ca.evento!;
                const provaLabels = getProvaLabels(ca.provas);

                return (
                  <TableRow
                    key={ca.convocatoria_grupo_id}
                    className="cursor-pointer hover:bg-accent/50"
                    onClick={() => openConvocatoria(ca)}
                  >
                    <TableCell label="Evento" className="text-xs font-medium">{evento.titulo}</TableCell>
                    <TableCell label="Data" className="text-xs">
                      {format(new Date(evento.data_inicio), 'dd/MM/yyyy', { locale: pt })}
                    </TableCell>
                    <TableCell label="Estado" className="text-xs">{getEstadoBadge(evento.estado)}</TableCell>
                    <TableCell label="Provas" className="text-xs">
                      <div className="flex flex-wrap gap-1">
                        {provaLabels.length > 0 ? (
                          <>
                            {provaLabels.map((prova, idx) => (
                              <Badge key={idx} variant="secondary" className="text-xs">{prova}</Badge>
                            ))}
                          </>
                        ) : (
                          <Badge variant="outline" className="text-xs text-muted-foreground">
                            {provasLoading ? 'A carregar provas...' : 'Sem provas'}
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell label="Consultar" className="text-right">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={(event) => {
                          event.stopPropagation();
                          openConvocatoria(ca);
                        }}
                      >
                        Consultar
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      </div>

      <Dialog open={!!selectedConvocatoria} onOpenChange={(open) => !open && setSelectedConvocatoria(null)}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle className="text-sm">
              {selectedConvocatoria?.evento?.titulo || 'Convocatória'}
            </DialogTitle>
            <DialogDescription>
              Consulta da convocatória do atleta.
            </DialogDescription>
          </DialogHeader>

          {selectedConvocatoria && (
            <div className="space-y-2">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                <Card className="p-2 space-y-1">
                  <h3 className="text-xs font-semibold">Evento</h3>
                  <div className="text-xs text-muted-foreground space-y-1">
                    <p><span className="font-medium text-foreground">Nome:</span> {selectedConvocatoria.evento?.titulo || '-'}</p>
                    <p><span className="font-medium text-foreground">Data:</span> {selectedConvocatoria.evento?.data_inicio ? format(new Date(selectedConvocatoria.evento.data_inicio), 'dd/MM/yyyy', { locale: pt }) : '-'}</p>
                    <p><span className="font-medium text-foreground">Estado:</span> {selectedConvocatoria.evento?.estado ? getEstadoBadge(selectedConvocatoria.evento.estado) : '-'}</p>
                  </div>
                </Card>

                <Card className="p-2 space-y-1">
                  <h3 className="text-xs font-semibold">Convocatória</h3>
                  <div className="text-xs text-muted-foreground space-y-1">
                    <p><span className="font-medium text-foreground">Confirmação:</span> {getConfirmacaoBadge(selectedConvocatoria)}</p>
                    <p><span className="font-medium text-foreground">Presença:</span> {selectedConvocatoria.presente ? 'Sim' : 'Não'}</p>
                    <p><span className="font-medium text-foreground">Estafetas:</span> {selectedConvocatoria.estafetas ?? 0}</p>
                  </div>
                </Card>
              </div>

              <Card className="p-2 space-y-1">
                <h3 className="text-xs font-semibold">Informações de Encontro</h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-2 text-xs text-muted-foreground">
                  <p><span className="font-medium text-foreground">Local:</span> {selectedConvocatoria.grupo?.local_encontro || '-'}</p>
                  <p><span className="font-medium text-foreground">Hora:</span> {selectedConvocatoria.grupo?.hora_encontro || '-'}</p>
                  <p><span className="font-medium text-foreground">Criada em:</span> {selectedConvocatoria.grupo?.data_criacao ? format(new Date(selectedConvocatoria.grupo.data_criacao), 'dd/MM/yyyy HH:mm', { locale: pt }) : '-'}</p>
                </div>
              </Card>

              <Card className="p-2 space-y-1">
                <h3 className="text-xs font-semibold">Provas</h3>
                {Array.isArray(selectedConvocatoria.provas) && selectedConvocatoria.provas.length > 0 ? (
                  <div className="flex flex-wrap gap-1">
                    {selectedConvocatoria.provas.map((provaId: string) => {
                      const provaLabel = provaNameById.get(String(provaId));
                      return (
                        <Badge key={provaId} variant="secondary" className="text-xs">
                          {provaLabel || (provasLoading ? 'A carregar prova...' : 'Prova')}
                        </Badge>
                      );
                    })}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground">Sem provas associadas.</p>
                )}
              </Card>

              <Card className="p-2 space-y-1">
                <h3 className="text-xs font-semibold">Observações</h3>
                <p className="text-xs text-muted-foreground whitespace-pre-wrap">
                  {selectedConvocatoria.grupo?.observacoes || 'Sem observações.'}
                </p>
              </Card>

              <div className="flex justify-end gap-2">
                {onNavigate && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 text-xs"
                    onClick={() => {
                      onNavigate('events', {
                        convocatoriaId: selectedConvocatoria.convocatoria_grupo_id,
                        tab: 'convocatorias',
                      });
                    }}
                  >
                    Abrir no módulo Eventos
                  </Button>
                )}
                {!onNavigate && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 text-xs"
                    onClick={() => toast.info('Consulta disponível nesta janela.')}
                  >
                    Consulta local
                  </Button>
                )}
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
