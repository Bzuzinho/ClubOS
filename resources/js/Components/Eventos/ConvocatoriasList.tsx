import { useMemo, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { useKV } from '@/hooks/useKV';
import { CreateConvocatoriaDialog } from './CreateConvocatoriaDialog';
import { EditConvocatoriaDialog } from './EditConvocatoriaDialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import {
  Plus,
  MagnifyingGlass,
  Trash,
  Users,
  PencilSimple,
  FilePdf,
} from '@phosphor-icons/react';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import { Checkbox } from '@/Components/ui/checkbox';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { toast } from 'sonner';
import axios from 'axios';

interface Convocation {
  id: string;
  evento_id: string;
  user_id: string;
  data_convocatoria: string;
  estado_confirmacao: string;
  transporte_clube?: boolean;
  event?: {
    id: string;
    titulo: string;
    data_inicio: string;
    tipo: string;
  };
  user?: {
    id: string;
    nome_completo: string;
  };
}

interface ConvocationGroup {
  id?: string;
  evento_id: string;
  evento_titulo: string;
  evento_data: string;
  evento_tipo: string;
  convocations: Convocation[];
  // Fields from KV store group
  local_encontro?: string | null;
  hora_encontro?: string | null;
  observacoes?: string | null;
  centro_custo_id?: string | null;
  valor_inscricao_calculado?: number;
  atletas_ids?: string[];
}

interface CostCenter {
  id: string;
  nome: string;
  ativo?: boolean;
}

interface ConvocationProps {
  events: any[];
  convocations?: Convocation[];
  users?: any[];
  ageGroups?: any[];
  costCenters?: CostCenter[];
}

export function ConvocatoriasList({
  events = [],
  convocations: initialConvocations = [],
  users = [],
  ageGroups = [],
  costCenters = [],
}: ConvocationProps) {
  // Os grupos guardam logística/custos; event_convocations guarda a resposta canónica do membro.
  const [kvConvocationGroups, setKvConvocationGroups] = useKV<any[]>('club-convocatorias-grupo', []);
  const [kvConvocationAthletes, setKvConvocationAthletes] = useKV<any[]>('club-convocatorias-atleta', []);
  
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [eventFilter, setEventFilter] = useState('todos');
  const [isViewDialogOpen, setIsViewDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState<ConvocationGroup | null>(null);
  const [deletingGroup, setDeletingGroup] = useState<ConvocationGroup | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  // Combinar os grupos com as respostas canónicas devolvidas pelo backend.
  const convocations: Convocation[] = useMemo(() => {
    return kvConvocationGroups
      .flatMap((group: any) => {
        return (group.atletas_ids || []).map((athleteId: string) => {
          const athlete = users.find((u: any) => u.id === athleteId);
          const event = events.find((e: any) => e.id === group.evento_id);
          const canonical = initialConvocations.find(
            (convocation) => convocation.evento_id === group.evento_id && convocation.user_id === athleteId
          );
          
          return {
            id: canonical?.id || `${group.id}-${athleteId}`,
            evento_id: group.evento_id,
            user_id: athleteId,
            data_convocatoria: canonical?.data_convocatoria || group.data_criacao || new Date().toISOString(),
            estado_confirmacao: canonical?.estado_confirmacao || 'pendente',
            transporte_clube: canonical?.transporte_clube || false,
            event: event ? {
              id: event.id,
              titulo: event.titulo,
              data_inicio: event.data_inicio,
              tipo: event.tipo,
            } : undefined,
            user: athlete ? {
              id: athlete.id,
              nome_completo: athlete.nome_completo || athlete.name || 'Sem nome',
            } : {
              id: athleteId,
              nome_completo: 'Atleta Desconhecido',
            },
          };
        });
      });
  }, [kvConvocationGroups, initialConvocations, users, events]);

  const allConvocationGroups: ConvocationGroup[] = useMemo(() => {
    return kvConvocationGroups
      .map((kvGroup: any) => {
        const event = events.find((item: any) => item.id === kvGroup.evento_id);

        return {
          id: kvGroup.id,
          evento_id: kvGroup.evento_id,
          evento_titulo: event?.titulo || 'Evento indisponível',
          evento_data: event?.data_inicio || kvGroup.data_criacao,
          evento_tipo: event?.tipo || 'evento',
          convocations: convocations.filter(
            (conv) => conv.evento_id === kvGroup.evento_id && (kvGroup.atletas_ids || []).includes(conv.user_id)
          ),
          local_encontro: kvGroup.local_encontro || undefined,
          hora_encontro: kvGroup.hora_encontro || undefined,
          observacoes: kvGroup.observacoes || undefined,
          centro_custo_id: kvGroup.centro_custo_id || undefined,
          valor_inscricao_calculado: kvGroup.valor_inscricao_calculado || undefined,
          atletas_ids: kvGroup.atletas_ids || [],
        };
      })
      .sort((a, b) => new Date(b.evento_data).getTime() - new Date(a.evento_data).getTime());
  }, [events, convocations, kvConvocationGroups]);

  const convocationGroups: ConvocationGroup[] = useMemo(() => {
    return allConvocationGroups.filter((group) => {
      const matchesEvent = eventFilter === 'todos' || group.evento_id === eventFilter;
      const matchesSearch = group.evento_titulo
        .toLowerCase()
        .includes(searchTerm.toLowerCase());

      return matchesEvent && matchesSearch;
    });
  }, [allConvocationGroups, eventFilter, searchTerm]);

  const eventsWithConvocations = useMemo(() => {
    return events
      .filter((event) => convocations.some((conv) => conv.evento_id === event.id))
      .sort((a, b) => a.titulo.localeCompare(b.titulo));
  }, [events, convocations]);

  const handleView = (group: ConvocationGroup) => {
    setSelectedGroup(group);
    setIsViewDialogOpen(true);
  };

  const handleDelete = (group: ConvocationGroup) => {
    setDeletingGroup(group);
    setIsDeleteDialogOpen(true);
  };

  const confirmDelete = async () => {
    if (!deletingGroup) return;

    setIsLoading(true);
    try {
      // Eliminar do KV store
      const groupId = deletingGroup.id;
      
      await setKvConvocationGroups(current => (current || []).filter(g => g.id !== groupId));
      if (groupId) {
        await setKvConvocationAthletes(current => (current || []).filter(a => a.convocatoria_grupo_id !== groupId));
      }

      toast.success('Convocatória eliminada com sucesso!');
      setIsDeleteDialogOpen(false);
      setDeletingGroup(null);
    } catch (error: any) {
      console.error('Erro ao eliminar convocatória:', error);
      const errors = error?.response?.data?.errors;
      const message = errors && typeof errors === 'object'
        ? Object.values(errors).flat().find((value) => typeof value === 'string')
        : error?.response?.data?.message;
      toast.error(typeof message === 'string' ? message : 'Erro ao eliminar convocatória');
    } finally {
      setIsLoading(false);
    }
  };

  const generatePDF = async (group: ConvocationGroup) => {
    try {
      // Fetch provas (race types) to map IDs to names
      let provaMap: Map<string, string> = new Map();
      try {
        const provasResponse = await axios.get('/api/prova-tipos');
        const provas = provasResponse.data || [];
        provas.forEach((prova: any) => {
          if (prova.id) {
            const name = prova.nome || prova.name || 'Prova';
            provaMap.set(prova.id, name.trim());
          }
        });
      } catch (err) {
        console.warn('Could not fetch provas data:', err);
      }

      const athleteProvasMap = new Map<string, string[]>();
      if (group.id) {
        (kvConvocationAthletes || [])
          .filter((item: any) => item.convocatoria_grupo_id === group.id)
          .forEach((item: any) => {
            athleteProvasMap.set(item.atleta_id, Array.isArray(item.provas) ? item.provas : []);
          });
      }

      // Criar HTML para PDF
      const htmlContent = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="UTF-8">
        <title>Convocatória - ${group.evento_titulo}</title>
        <style>
          @page { size: A4; margin: 2cm; }
          body { font-family: Arial, sans-serif; font-size: 12pt; }
          h1 { color: #1e40af; text-align: center; margin-bottom: 20px; }
          h2 { color: #475569; border-bottom: 2px solid #1e40af; padding-bottom: 5px; margin-top: 20px; }
          .info { margin: 20px 0; }
          .info-row { margin: 8px 0; display: flex; }
          .info-label { font-weight: bold; width: 150px; }
          .info-value { flex: 1; }
          table { width: 100%; border-collapse: collapse; margin-top: 20px; }
          th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
          th { background-color: #1e40af; color: white; font-weight: bold; }
          tr:nth-child(even) { background-color: #f9fafb; }
          .status-confirmado { color: #16a34a; font-weight: bold; }
          .status-pendente { color: #eab308; font-weight: bold; }
          .status-recusado { color: #dc2626; font-weight: bold; }
          .footer { margin-top: 40px; text-align: center; font-size: 10pt; color: #666; border-top: 1px solid #ccc; padding-top: 20px; }
          .empty-value { color: #999; font-style: italic; }
        </style>
      </head>
      <body>
        <h1>CONVOCATÓRIA</h1>
        
        <div class="info">
          <div class="info-row">
            <span class="info-label">Evento:</span>
            <span class="info-value">${group.evento_titulo}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Tipo:</span>
            <span class="info-value">${group.evento_tipo || '-'}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Data do Evento:</span>
            <span class="info-value">${format(new Date(group.evento_data), "dd 'de' MMMM 'de' yyyy", {
              locale: ptBR,
            })}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Local de Encontro:</span>
            <span class="info-value">${group.local_encontro || '-'}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Hora de Encontro:</span>
            <span class="info-value">${group.hora_encontro || '-'}</span>
          </div>
          <div class="info-row">
            <span class="info-label">Total de Convocados:</span>
            <span class="info-value"><strong>${group.convocations.length}</strong></span>
          </div>
        </div>

        <h2>Atletas Convocados</h2>
        <table>
          <thead>
            <tr>
              <th style="width: 50px;">Nº</th>
              <th>Nome</th>
              <th>Relação de Provas</th>
            </tr>
          </thead>
          <tbody>
            ${group.convocations
              .map(
                (conv, index) => {
                  const provaIds = athleteProvasMap.get(conv.user_id) || [];
                  // Map prova IDs to their names
                  const provaNames = provaIds
                    .map(id => provaMap.get(id) || id)
                    .filter(name => name); // Remove empty values
                  const provasText = provaNames.length > 0 ? provaNames.join(', ') : '-';
                  return `
              <tr>
                <td>${index + 1}</td>
                <td>${conv.user?.nome_completo || 'N/A'}</td>
                <td>${provasText}</td>
              </tr>
            `;
                }
              )
              .join('')}
          </tbody>
        </table>

        <div class="footer">
          <p>Documento gerado automaticamente em ${format(
            new Date(),
            "dd/MM/yyyy 'às' HH:mm"
          )}</p>
        </div>
      </body>
      </html>
    `;

      // Abrir em nova janela para impressão
      const printWindow = window.open('', '_blank');
      if (printWindow) {
        printWindow.document.write(htmlContent);
        printWindow.document.close();
        setTimeout(() => {
          printWindow.print();
        }, 250);
      }

      toast.success('PDF gerado! Use Ctrl+P para salvar ou imprimir.');
    } catch (error) {
      console.error('Erro ao gerar PDF:', error);
      toast.error('Erro ao gerar PDF');
    }
  };

  const getEstadoStats = (group: ConvocationGroup) => {
    const confirmados = group.convocations.filter(
      (c) => c.estado_confirmacao === 'confirmado'
    ).length;
    const pendentes = group.convocations.filter(
      (c) => c.estado_confirmacao === 'pendente'
    ).length;
    const recusados = group.convocations.filter(
      (c) => c.estado_confirmacao === 'recusado'
    ).length;

    return { confirmados, pendentes, recusados };
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="text-sm text-muted-foreground">
          {convocationGroups.length} de {allConvocationGroups.length} convocatórias
        </div>

        <Button
          className="h-8 text-xs"
          onClick={() => setIsCreateDialogOpen(true)}
        >
          <Plus size={14} className="mr-1.5" />
          Nova Convocatória
        </Button>
      </div>

      <CreateConvocatoriaDialog
        open={isCreateDialogOpen}
        onOpenChange={setIsCreateDialogOpen}
        events={events}
        users={users}
        ageGroups={ageGroups}
        costCenters={costCenters}
        onCreated={(newConvocations) => {
          // ✅ Com useKV, não precisa fazer nada - os dados recarregam automaticamente
          // O hook invalidaQueries quando novos atletas são adicionados via PUT
          setIsCreateDialogOpen(false);
        }}
      />

      <Card className="p-2.5">
        <div className="flex flex-col gap-2 md:flex-row">
          <div className="relative flex-1">
            <MagnifyingGlass className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground" size={14} />
            <Input
              placeholder="Pesquisar convocatórias..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="h-7 pl-8 text-xs bg-white"
            />
          </div>
          <Select value={eventFilter} onValueChange={setEventFilter}>
            <SelectTrigger className="h-7 w-full md:w-[180px] text-xs bg-white">
              <SelectValue placeholder="Evento" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="todos">Todos os Eventos</SelectItem>
              {eventsWithConvocations.map((event) => (
                <SelectItem key={event.id} value={event.id}>
                  {event.titulo}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </Card>

      <div className="space-y-2">
        {convocationGroups.length === 0 ? (
          <Card className="p-6 text-center text-sm text-muted-foreground">
            Nenhuma convocatória encontrada
          </Card>
        ) : (
          convocationGroups.map((group) => {
            const stats = getEstadoStats(group);

            return (
            <Card key={group.id || group.evento_id} className="p-2.5">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="truncate text-sm font-semibold">{group.evento_titulo}</h3>
                      <Badge variant="secondary" className="text-xs">
                        <Users size={12} className="mr-1" />
                        {group.convocations.length} atletas
                      </Badge>
                    </div>

                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span>
                        Data: {format(new Date(group.evento_data), "d 'de' MMMM 'de' yyyy", { locale: ptBR })}
                      </span>
                      <span>•</span>
                      <span className="capitalize">{group.evento_tipo}</span>
                    </div>

                    <div className="mt-1 flex flex-wrap items-center gap-1 text-xs">
                      <Badge variant="outline" className="h-5 px-1.5 text-[11px] text-green-700">C {stats.confirmados}</Badge>
                      <Badge variant="outline" className="h-5 px-1.5 text-[11px] text-amber-700">P {stats.pendentes}</Badge>
                      <Badge variant="outline" className="h-5 px-1.5 text-[11px] text-red-700">R {stats.recusados}</Badge>
                    </div>
                  </div>

                  <div className="flex items-center gap-1">
                    <Button
                      variant="outline"
                      className="h-7 px-2 text-xs"
                      onClick={() => handleView(group)}
                    >
                      <PencilSimple size={12} className="mr-1" />
                      Editar
                    </Button>
                    <Button
                      variant="outline"
                      className="h-7 px-2 text-xs"
                      onClick={() => generatePDF(group).catch(() => null)}
                    >
                      <FilePdf size={12} className="mr-1" />
                      PDF
                    </Button>
                    <Button
                      variant="destructive"
                      className="h-7 px-2"
                      onClick={() => handleDelete(group)}
                    >
                      <Trash size={12} />
                    </Button>
                  </div>
                </div>
              </Card>
            );
          })
        )}
      </div>

      {/* Dialog - Editar Convocatória */}
      <EditConvocatoriaDialog
        open={isViewDialogOpen}
        onOpenChange={setIsViewDialogOpen}
        group={selectedGroup}
        events={events}
        users={users}
        costCenters={costCenters}
      />

      {/* Dialog - Confirmação de Eliminação */}
      <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirmar Eliminação</AlertDialogTitle>
            <AlertDialogDescription>
              Tem a certeza que deseja eliminar todas as convocatórias deste evento? Esta
              ação não pode ser desfeita.
            </AlertDialogDescription>
            {deletingGroup && (
              <div className="mt-2 p-2 bg-muted rounded text-sm">
                <strong>Evento:</strong> {deletingGroup.evento_titulo}
                <br />
                <strong>Convocatórias:</strong> {deletingGroup.convocations.length}
              </div>
            )}
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isLoading}>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={confirmDelete}
              disabled={isLoading}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {isLoading ? 'Eliminando...' : 'Eliminar'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
