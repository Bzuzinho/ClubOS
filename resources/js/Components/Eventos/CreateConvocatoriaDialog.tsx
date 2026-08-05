import { useEffect, useMemo, useState } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Checkbox } from '@/Components/ui/checkbox';
import { Badge } from '@/Components/ui/badge';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Textarea } from '@/Components/ui/textarea';
import { Separator } from '@/Components/ui/separator';
import { Users, FilePdf } from '@phosphor-icons/react';
import { toast } from 'sonner';
import { useClubSettings } from '@/hooks/useClubSettings';
import { useKV } from '@/hooks/useKV';
import axios from 'axios';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';

interface EventItem {
  id: string;
  titulo: string;
  data_inicio: string;
  hora_inicio?: string;
  local?: string;
  tipo?: string;
  estado?: string;
  taxa_inscricao?: number;
  custo_inscricao_por_prova?: number;
  custo_inscricao_por_salto?: number;
  custo_inscricao_estafeta?: number;
}

interface UserItem {
  id: string;
  nome_completo?: string;
  name?: string;
  numero_socio?: string;
  estado?: string;
  tipo_membro?: string[];
  escalao?: string[];
}

interface Prova {
  id: string;
  name?: string;
  nome?: string;
  distancia?: number;
  unidade?: string;
  modalidade?: string;
}

interface AgeGroup {
  id: string;
  nome?: string;
  name?: string;
}

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

interface CreateConvocatoriaDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  events: EventItem[];
  users: UserItem[];
  ageGroups?: AgeGroup[];
  costCenters?: Array<{ id: string; nome: string }>;
  onCreated: (newConvocations: Convocation[]) => void;
}

const uuid = () => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `tmp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
};

export function CreateConvocatoriaDialog({
  open,
  onOpenChange,
  events,
  users,
  ageGroups: providedAgeGroups = [],
  costCenters: _costCenters = [],
  onCreated,
}: CreateConvocatoriaDialogProps) {
  const { defaultMeetingPoint } = useClubSettings();
  const [step, setStep] = useState(1);
  const [selectedEventId, setSelectedEventId] = useState('');
  const [selectedEscalao, setSelectedEscalao] = useState('todos');
  const [selectedAthletes, setSelectedAthletes] = useState<string[]>([]);
  const [athleteProvas, setAthleteProvas] = useState<Record<string, string[]>>({});
  const [horaEncontro, setHoraEncontro] = useState('');
  const [localEncontro, setLocalEncontro] = useState('');
  const [observacoes, setObservacoes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [apiProvas, setApiProvas] = useState<Prova[]>([]);

  const [convocatoriaGroups, setConvocatoriaGroups] = useKV<any[]>('club-convocatorias-grupo', []);
  const [convocatoriaAthletes, setConvocatoriaAthletes] = useKV<any[]>('club-convocatorias-atleta', []);

  const normalizeProvaLabel = (prova: Prova): string => {
    if (prova.name && prova.name.trim() !== '') {
      return prova.name.trim();
    }

    if (prova.nome && prova.nome.trim() !== '') {
      return prova.nome.trim();
    }

    return 'Prova';
  };

  const provaOptions = useMemo(() => {
    const source = apiProvas || [];

    return source
      .filter((prova) => Boolean(prova?.id))
      .map((prova) => ({ id: prova.id, name: normalizeProvaLabel(prova) }));
  }, [apiProvas]);

  const ageGroupSource = useMemo(() => {
    return providedAgeGroups || [];
  }, [providedAgeGroups]);

  const eventOptions = useMemo(() => {
    return (events || [])
      .filter((event) => event.estado !== 'cancelado')
      .sort((a, b) => new Date(a.data_inicio).getTime() - new Date(b.data_inicio).getTime());
  }, [events]);

  const userSource = useMemo(() => {
    return users || [];
  }, [users]);

  const athleteOptions = useMemo(() => {
    return userSource.filter((user) => {
      const isAtivo = !user.estado || user.estado === 'ativo';
      const isAtleta = !user.tipo_membro || user.tipo_membro.length === 0 || user.tipo_membro.includes('atleta');
      return isAtivo && isAtleta;
    });
  }, [userSource]);

  const ageGroupById = useMemo(() => {
    return new Map(ageGroupSource.map((group) => [group.id, group]));
  }, [ageGroupSource]);

  const ageGroupIdByName = useMemo(() => {
    return new Map(
      ageGroupSource
        .map((group) => [((group.nome || group.name || '').toLowerCase().trim()), group.id] as const)
        .filter(([name, id]) => Boolean(name) && Boolean(id))
    );
  }, [ageGroupSource]);

  const getEscalaoLabel = (escalaoValue: string) => {
    if (!escalaoValue) {
      return escalaoValue;
    }

    const byId = ageGroupById.get(escalaoValue);
    if (byId) {
      return byId.nome || byId.name || escalaoValue;
    }

    return escalaoValue;
  };

  const normalizeEscaloes = (escaloes: string[] = []) => {
    return escaloes
      .map((value) => {
        if (!value) {
          return value;
        }

        const trimmedValue = value.trim();
        return ageGroupById.get(trimmedValue)?.id || ageGroupIdByName.get(trimmedValue.toLowerCase()) || trimmedValue;
      })
      .filter(Boolean);
  };

  const escalaoOptions = useMemo(() => {
    if (ageGroupSource.length > 0) {
      return ageGroupSource
        .map((group) => ({
          id: group.id,
          label: group.nome || group.name || group.id,
        }))
        .filter((group) => Boolean(group.id) && Boolean(group.label));
    }

    const values = new Set<string>();
    athleteOptions.forEach((athlete) => {
      normalizeEscaloes(athlete.escalao || []).forEach((escalaoId) => values.add(escalaoId));
    });

    return Array.from(values)
      .sort((a, b) => getEscalaoLabel(a).localeCompare(getEscalaoLabel(b)))
      .map((value) => ({ id: value, label: getEscalaoLabel(value) }));
  }, [ageGroupSource, athleteOptions]);

  const filteredAthletes = useMemo(() => {
    if (selectedEscalao === 'todos') {
      return athleteOptions;
    }

    return athleteOptions.filter((athlete) => normalizeEscaloes(athlete.escalao || []).includes(selectedEscalao));
  }, [athleteOptions, selectedEscalao, ageGroupById, ageGroupIdByName]);

  const selectedEvent = useMemo(() => {
    return eventOptions.find((event) => event.id === selectedEventId);
  }, [eventOptions, selectedEventId]);

  const costSummary = useMemo(() => {
    if (!selectedEvent) {
      return { totalAtletas: selectedAthletes.length, totalProvas: 0, totalEstafetas: 0, valorTotal: 0 };
    }

    let totalProvas = 0;
    let totalEstafetas = 0;

    selectedAthletes.forEach((athleteId) => {
      const athleteTests = athleteProvas[athleteId] || [];
      athleteTests.forEach((provaId) => {
        const prova = provaOptions.find((item) => item.id === provaId);
        if (prova && prova.name.toLowerCase().includes('estafeta')) {
          totalEstafetas += 1;
        } else {
          totalProvas += 1;
        }
      });
    });

    const taxaBase = Number(selectedEvent.taxa_inscricao || 0) * selectedAthletes.length;
    const custoProvas = Number(selectedEvent.custo_inscricao_por_prova || 0) * totalProvas;
    const custoSaltos = Number(selectedEvent.custo_inscricao_por_salto || 0) * totalProvas;
    const custoEstafetas = Number(selectedEvent.custo_inscricao_estafeta || 0) * totalEstafetas;

    return {
      totalAtletas: selectedAthletes.length,
      totalProvas,
      totalEstafetas,
      valorTotal: taxaBase + custoProvas + custoSaltos + custoEstafetas,
    };
  }, [athleteProvas, provaOptions, selectedAthletes, selectedEvent]);

  const resetForm = () => {
    setStep(1);
    setSelectedEventId('');
    setSelectedEscalao('todos');
    setSelectedAthletes([]);
    setAthleteProvas({});
    setHoraEncontro('');
    setLocalEncontro('');
    setObservacoes('');
    setIsSubmitting(false);
  };

  useEffect(() => {
    if (!open) {
      resetForm();
    }
  }, [open]);

  useEffect(() => {
    if (!open) {
      return;
    }

    const loadProvas = async () => {
      try {
        const response = await axios.get('/api/prova-tipos');
        setApiProvas(Array.isArray(response.data) ? response.data : []);
      } catch {
        setApiProvas([]);
      }
    };

    loadProvas();
  }, [open]);

  const toggleAthlete = (athleteId: string) => {
    setSelectedAthletes((current) =>
      current.includes(athleteId) ? current.filter((id) => id !== athleteId) : [...current, athleteId]
    );
  };

  const toggleProva = (athleteId: string, provaId: string) => {
    setAthleteProvas((current) => {
      const currentList = current[athleteId] || [];
      const updated = currentList.includes(provaId)
        ? currentList.filter((id) => id !== provaId)
        : [...currentList, provaId];

      return { ...current, [athleteId]: updated };
    });
  };

  const selectAllAthletes = () => {
    setSelectedAthletes(filteredAthletes.map((athlete) => athlete.id));
  };

  const clearAthletes = () => {
    setSelectedAthletes([]);
  };

  const handleNext = () => {
    if (step === 1) {
      if (!selectedEventId) {
        toast.error('Selecione um evento');
        return;
      }
      if (selectedAthletes.length === 0) {
        toast.error('Selecione pelo menos um atleta');
        return;
      }
    }

    if (step === 2) {
      const hasAtLeastOneProva = selectedAthletes.some((athleteId) => (athleteProvas[athleteId] || []).length > 0);
      if (!hasAtLeastOneProva) {
        toast.error('Atribua pelo menos uma prova');
        return;
      }
    }

    setStep((value) => Math.min(4, value + 1));
  };

  const handleCreate = async () => {
    if (!selectedEvent) {
      return;
    }

    setIsSubmitting(true);

    try {
      const createdDate = new Date().toISOString();
      const groupId = uuid();

      const newGroup = {
        id: groupId,
        evento_id: selectedEventId,
        data_criacao: createdDate,
        atletas_ids: selectedAthletes,
        hora_encontro: horaEncontro || null,
        local_encontro: localEncontro || null,
        observacoes: observacoes || null,
        valor_inscricao_calculado: Number(costSummary.valorTotal.toFixed(2)),
      };

      const newAthletes = selectedAthletes.map((athleteId) => ({
        id: uuid(),
        convocatoria_grupo_id: groupId,
        atleta_id: athleteId,
        provas: athleteProvas[athleteId] || [],
        created_at: createdDate,
      }));

      await setConvocatoriaGroups([...(convocatoriaGroups || []), newGroup]);
      await setConvocatoriaAthletes([...(convocatoriaAthletes || []), ...newAthletes]);

      const athleteMap = new Map(userSource.map((user) => [user.id, user]));
      const newConvocations: Convocation[] = selectedAthletes.map((athleteId) => {
        const athlete = athleteMap.get(athleteId);
        return {
          id: uuid(),
          evento_id: selectedEventId,
          user_id: athleteId,
          data_convocatoria: createdDate,
          estado_confirmacao: 'pendente',
          event: {
            id: selectedEvent.id,
            titulo: selectedEvent.titulo,
            data_inicio: selectedEvent.data_inicio,
            tipo: selectedEvent.tipo || 'prova',
          },
          user: {
            id: athleteId,
            nome_completo: athlete?.nome_completo || athlete?.name || 'Atleta',
          },
        };
      });

      onCreated(newConvocations);
      toast.success('Convocatória criada com sucesso!');
      onOpenChange(false);
    } catch (error: any) {
      const errors = error?.response?.data?.errors;
      const message = errors && typeof errors === 'object'
        ? Object.values(errors).flat().find((value) => typeof value === 'string')
        : error?.response?.data?.message;
      toast.error(typeof message === 'string' ? message : 'Erro ao criar convocatória');
    } finally {
      setIsSubmitting(false);
    }
  };

  const exportSummaryPdf = () => {
    const eventTitle = selectedEvent?.titulo || 'Convocatória';
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      return;
    }

    printWindow.document.write(`
      <html>
      <head><title>${eventTitle}</title></head>
      <body style="font-family: Arial; padding: 24px;">
        <h2>Resumo da Convocatória</h2>
        <p><strong>Evento:</strong> ${eventTitle}</p>
        <p><strong>Atletas:</strong> ${selectedAthletes.length}</p>
        <p><strong>Total Provas:</strong> ${costSummary.totalProvas}</p>
        <p><strong>Total Estafetas:</strong> ${costSummary.totalEstafetas}</p>
        <p><strong>Valor Total:</strong> €${costSummary.valorTotal.toFixed(2)}</p>
      </body>
      </html>
    `);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 250);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Nova Convocatória - Passo {step} de 4</DialogTitle>
          <DialogDescription>
            {step === 1 && 'Selecione o evento e os atletas a convocar'}
            {step === 2 && 'Atribua as provas a cada atleta'}
            {step === 3 && 'Defina os custos e informações logísticas'}
            {step === 4 && 'Reveja e confirme a convocatória'}
          </DialogDescription>
        </DialogHeader>

        <ScrollArea className="max-h-[65vh] pr-2">
          <div className="space-y-4">
            {step === 1 && (
              <>
                <div className="space-y-2">
                  <Label>Evento *</Label>
                  <Select value={selectedEventId} onValueChange={setSelectedEventId}>
                    <SelectTrigger>
                      <SelectValue placeholder="Selecionar evento agendado..." />
                    </SelectTrigger>
                    <SelectContent>
                      {eventOptions.map((event) => (
                        <SelectItem key={event.id} value={event.id}>
                          {event.titulo}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <Separator />

                <div className="space-y-2">
                  <Label>Filtrar por Escalão</Label>
                  <Select value={selectedEscalao} onValueChange={setSelectedEscalao}>
                    <SelectTrigger className="w-[220px]">
                      <SelectValue placeholder="Todos os escalões" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="todos">Todos os escalões</SelectItem>
                      {escalaoOptions.map((escalao) => (
                        <SelectItem key={escalao.id} value={escalao.id}>
                          {escalao.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label>Selecionar Atletas ({selectedAthletes.length} selecionados)</Label>
                  <div className="flex gap-2">
                    <Button variant="outline" className="h-8 text-xs" onClick={selectAllAthletes}>
                      Selecionar Todos
                    </Button>
                    <Button variant="outline" className="h-8 text-xs" onClick={clearAthletes}>
                      Desselecionar Todos
                    </Button>
                  </div>
                </div>

                <Card className="p-2.5">
                  <div className="space-y-1.5">
                    {filteredAthletes.map((athlete) => (
                      <div key={athlete.id} className="flex items-center justify-between rounded-md border p-2">
                        <div className="flex items-start gap-2">
                          <Checkbox
                            checked={selectedAthletes.includes(athlete.id)}
                            onCheckedChange={() => toggleAthlete(athlete.id)}
                          />
                          <div>
                            <p className="text-sm font-medium">{athlete.nome_completo || athlete.name}</p>
                            <p className="text-xs text-muted-foreground">{athlete.numero_socio || '-'}</p>
                          </div>
                        </div>
                        {normalizeEscaloes(athlete.escalao || []).slice(0, 1).map((escalao) => (
                          <Badge key={escalao} variant="outline" className="text-xs">{getEscalaoLabel(escalao)}</Badge>
                        ))}
                      </div>
                    ))}
                  </div>
                </Card>
              </>
            )}

            {step === 2 && (
              <>
                <Card className="p-3">
                  <p className="text-base font-semibold flex items-center gap-2">
                    <Users size={16} />
                    {selectedAthletes.length} atletas selecionados
                  </p>
                  <p className="text-sm text-muted-foreground">Selecione as provas que cada atleta irá realizar</p>
                </Card>

                {selectedAthletes.map((athleteId) => {
                  const athlete = athleteOptions.find((item) => item.id === athleteId);
                  if (!athlete) {
                    return null;
                  }

                  return (
                    <Card key={athleteId} className="p-3">
                      <p className="mb-2 text-base font-semibold">{athlete.nome_completo || athlete.name}</p>
                      <div className="space-y-1.5">
                        {provaOptions.map((prova) => (
                          <label key={prova.id} className="flex items-center gap-2">
                            <Checkbox
                              checked={(athleteProvas[athleteId] || []).includes(prova.id)}
                              onCheckedChange={() => toggleProva(athleteId, prova.id)}
                            />
                            <span className="text-sm">{prova.name}</span>
                          </label>
                        ))}
                        {provaOptions.length === 0 && (
                          <p className="text-sm text-muted-foreground">Sem provas configuradas na Configuração Geral.</p>
                        )}
                      </div>
                    </Card>
                  );
                })}
              </>
            )}

            {step === 3 && (
              <>
                <Card className="p-3">
                  <p className="mb-2 text-xl font-semibold">Custos do Evento</p>
                  <p className="text-base">
                    Custo por Prova: €{Number(selectedEvent?.custo_inscricao_por_prova || 0).toFixed(2)}
                  </p>
                </Card>

                <Card className="p-3">
                  <p className="mb-2 text-xl font-semibold">Cálculo das Taxas de Inscrição</p>
                  <div className="space-y-1 text-base">
                    <div className="flex justify-between"><span>Total de Atletas:</span><span>{costSummary.totalAtletas}</span></div>
                    <div className="flex justify-between"><span>Total de Provas:</span><span>{costSummary.totalProvas}</span></div>
                    <div className="flex justify-between"><span>Nº de Estafetas:</span><span>{costSummary.totalEstafetas}</span></div>
                  </div>
                  <Separator className="my-2" />
                  <div className="flex justify-between text-2xl font-bold text-primary">
                    <span>Valor Total:</span>
                    <span>€{costSummary.valorTotal.toFixed(2)}</span>
                  </div>
                </Card>

                <div className="space-y-2">
                  <Label>Hora de Encontro</Label>
                  <Input type="time" value={horaEncontro} onChange={(e) => setHoraEncontro(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <Label>Local de Encontro</Label>
                  <Input value={localEncontro} onChange={(e) => setLocalEncontro(e.target.value)} placeholder={`Ex: ${defaultMeetingPoint}`} />
                </div>
                <div className="space-y-2">
                  <Label>Observações</Label>
                  <Textarea value={observacoes} onChange={(e) => setObservacoes(e.target.value)} placeholder="Informações adicionais..." rows={3} />
                </div>
              </>
            )}

            {step === 4 && (
              <>
                <Card className="p-3">
                  <p className="mb-2 text-base font-semibold">Resumo da Convocatória</p>
                  <p className="text-sm text-muted-foreground">Evento:</p>
                  <p className="text-sm font-medium">{selectedEvent?.titulo || '-'}</p>
                  {selectedEvent && (
                    <p className="text-xs text-muted-foreground">
                      {format(new Date(selectedEvent.data_inicio), "dd/MM/yyyy 'às' HH:mm", { locale: ptBR })}
                    </p>
                  )}
                  <Separator className="my-2" />
                  <p className="text-sm">Atletas Convocados: <strong>{selectedAthletes.length}</strong></p>
                  <p className="text-sm">Total de Provas: <strong>{costSummary.totalProvas}</strong></p>
                  <p className="text-sm">Taxa de Inscrição Total: <strong>€{costSummary.valorTotal.toFixed(2)}</strong></p>
                </Card>

                <Card className="p-3">
                  <p className="mb-2 text-sm font-semibold">Lista de Atletas e Provas</p>
                  <div className="space-y-1.5">
                    {selectedAthletes.map((athleteId) => {
                      const athlete = athleteOptions.find((item) => item.id === athleteId);
                      const provaNames = (athleteProvas[athleteId] || [])
                        .map((provaId) => provaOptions.find((item) => item.id === provaId)?.name)
                        .filter(Boolean);

                      return (
                        <div key={athleteId} className="flex items-start justify-between gap-3 border-b pb-1.5 text-sm">
                          <span>{athlete?.nome_completo || athlete?.name || '-'}</span>
                          <div className="flex flex-wrap gap-1 justify-end">
                            {provaNames.length === 0 ? (
                              <Badge variant="outline" className="text-xs">Sem provas</Badge>
                            ) : (
                              provaNames.map((name) => (
                                <Badge key={`${athleteId}-${name}`} variant="secondary" className="text-xs">{name}</Badge>
                              ))
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </Card>
              </>
            )}
          </div>
        </ScrollArea>

        <DialogFooter className="gap-2">
          {step > 1 && (
            <Button variant="outline" className="h-8 text-xs" onClick={() => setStep(step - 1)}>
              Anterior
            </Button>
          )}

          {step === 4 && (
            <Button variant="outline" className="h-8 text-xs" onClick={exportSummaryPdf}>
              <FilePdf size={12} className="mr-1.5" />
              Exportar PDF
            </Button>
          )}

          {step < 4 ? (
            <Button className="h-8 text-xs" onClick={handleNext}>
              Próximo
            </Button>
          ) : (
            <Button className="h-8 text-xs" onClick={handleCreate} disabled={isSubmitting}>
              {isSubmitting ? 'A criar...' : 'Criar Convocatória'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
