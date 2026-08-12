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
import { Users } from '@phosphor-icons/react';
import { toast } from 'sonner';
import { useClubSettings } from '@/hooks/useClubSettings';
import { useKV } from '@/hooks/useKV';
import axios from 'axios';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';

interface Prova {
  id: string;
  name?: string;
  nome?: string;
  distancia?: number;
  unidade?: string;
  modalidade?: string;
}

interface ConvocationGroup {
  id?: string;
  evento_id: string;
  evento_titulo: string;
  evento_data: string;
  evento_tipo: string;
  convocations: any[];
}

interface CostCenter {
  id: string;
  nome: string;
  ativo?: boolean;
}

interface EditConvocatoriaDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: ConvocationGroup | null;
  events: any[];
  users: any[];
  costCenters: CostCenter[];
}

export function EditConvocatoriaDialog({
  open,
  onOpenChange,
  group,
  events = [],
  users = [],
  costCenters = [],
}: EditConvocatoriaDialogProps) {
  const { defaultMeetingPoint } = useClubSettings();
  const [step, setStep] = useState(1);
  const [selectedAthletes, setSelectedAthletes] = useState<string[]>([]);
  const [initialAthletes, setInitialAthletes] = useState<string[]>([]);
  const [horaEncontro, setHoraEncontro] = useState('');
  const [localEncontro, setLocalEncontro] = useState('');
  const [observacoes, setObservacoes] = useState('');
  const [centroCustoId, setCentroCustoId] = useState('none');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [apiProvas, setApiProvas] = useState<Prova[]>([]);
  const [athleteProvas, setAthleteProvas] = useState<Record<string, string[]>>({});

  const [convocationGroups, setConvocationGroups] = useKV<any[]>('club-convocatorias-grupo', []);
  const [convocationAthletes, setConvocationAthletes] = useKV<any[]>('club-convocatorias-atleta', []);

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
    const source = apiProvas.length > 0 ? apiProvas : [];

    return source
      .filter((prova) => Boolean(prova?.id))
      .map((prova) => ({ id: prova.id, name: normalizeProvaLabel(prova) }));
  }, [apiProvas]);

  // Carregar provas do evento
  useEffect(() => {
    if (!open || !group) {
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
  }, [open, group]);

  // Carregar dados do grupo quando abre
  useEffect(() => {
    if (group && open) {
      // Primeiro, tenta carregar do KV store
      const groupData = convocationGroups.find((g: any) =>
        group.id ? g.id === group.id : g.evento_id === group.evento_id
      );

      if (groupData) {
        setHoraEncontro(groupData.hora_encontro || '');
        setLocalEncontro(groupData.local_encontro || '');
        setObservacoes(groupData.observacoes || '');
        setCentroCustoId(groupData.centro_custo_id || 'none');
        
        const athleteIds = groupData.atletas_ids || [];
        setSelectedAthletes(athleteIds);
        setInitialAthletes(athleteIds);

        const groupAthletes = (convocationAthletes || []).filter((item: any) => item.convocatoria_grupo_id === groupData.id);
        const provMap: Record<string, string[]> = {};
        groupAthletes.forEach((item: any) => {
          provMap[item.atleta_id] = Array.isArray(item.provas) ? item.provas : [];
        });
        setAthleteProvas(provMap);
      } else {
        // Fallback: usar convocations do group se KV store ainda não tem dados
        const athleteIds = group.convocations?.map((c: any) => c.user_id) || [];
        setSelectedAthletes(athleteIds);
        setInitialAthletes(athleteIds);
        setHoraEncontro('');
        setLocalEncontro('');
        setObservacoes('');
        setCentroCustoId('none');

        const provMap: Record<string, string[]> = {};
        (convocationAthletes || [])
          .filter((item: any) => athleteIds.includes(item.atleta_id))
          .forEach((item: any) => {
            if (!provMap[item.atleta_id]) {
              provMap[item.atleta_id] = Array.isArray(item.provas) ? item.provas : [];
            }
          });
        setAthleteProvas(provMap);
      }
      
      // Reset step quando abre
      setStep(1);
    }
  }, [group, open, convocationGroups, convocationAthletes]);

  const handleSave = async (publishAfterSave = false) => {
    if (!group) return;

    setIsSubmitting(true);
    try {
      // Atualizar grupo
      const updatedGroups = convocationGroups.map((g: any) => {
        if (group.id ? g.id === group.id : g.evento_id === group.evento_id) {
          return {
            ...g,
            atletas_ids: selectedAthletes,
            hora_encontro: horaEncontro || null,
            local_encontro: localEncontro || null,
            observacoes: observacoes || null,
            centro_custo_id: centroCustoId === 'none' ? null : centroCustoId,
          };
        }
        return g;
      });

      await setConvocationGroups(updatedGroups);

      const groupId = group.id || (convocationGroups.find((g: any) => g.evento_id === group.evento_id)?.id) || null;

      if (groupId) {
        const preserved = (convocationAthletes || []).filter((item: any) => item.convocatoria_grupo_id !== groupId);
        const updated = selectedAthletes.map((athleteId) => ({
          id: typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : `tmp-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
          convocatoria_grupo_id: groupId,
          atleta_id: athleteId,
          provas: athleteProvas[athleteId] || [],
          created_at: new Date().toISOString(),
        }));
        await setConvocationAthletes([...preserved, ...updated]);
      }

      if (publishAfterSave) {
        if (!groupId) {
          throw new Error('Não foi possível identificar a convocatória para publicação.');
        }

        const response = await axios.post(`/api/eventos/convocation-groups/${groupId}/publish`);
        const communicationStatus = response.data?.communication?.status;

        if (communicationStatus === 'dispatched') {
          toast.success('Convocatória guardada e publicada com sucesso!');
        } else if (communicationStatus === 'skipped') {
          toast.success('Convocatória publicada. O envio está desativado nas preferências de Comunicação.');
        } else {
          toast.warning('Convocatória publicada, mas a comunicação não foi concluída.');
        }
      } else {
        toast.success('Convocatória guardada como rascunho.');
      }

      onOpenChange(false);
      setStep(1);
    } catch (error: any) {
      console.error('Erro ao atualizar/publicar convocatória:', error);
      const errors = error?.response?.data?.errors;
      const message = errors && typeof errors === 'object'
        ? Object.values(errors).flat().find((value) => typeof value === 'string')
        : error?.response?.data?.message;
      toast.error(typeof message === 'string' ? message : 'Erro ao atualizar/publicar convocatória');
    } finally {
      setIsSubmitting(false);
    }
  };

  const selectedEvent = events.find((e) => e.id === group?.evento_id);
  const eventCost = selectedEvent?.taxa_inscricao || 0;
  const totalCost = selectedAthletes.length * eventCost;

  // Atletas disponíveis para convocatória
  const availableAthletes = useMemo(() => {
    return (users || []).filter((u: any) => {
      const isActive = u.estado === 'ativo' || u.status === 'ativo';

      // A lista já vem filtrada pelo resolvedor canónico de atletas no backend.
      // Não voltar a depender de tipo_membro, que pode estar vazio em registos
      // migrados mesmo quando user_types identifica corretamente o atleta.
      return isActive;
    });
  }, [users]);

  const toggleAthlete = (athleteId: string) => {
    setSelectedAthletes(prev =>
      prev.includes(athleteId)
        ? prev.filter(id => id !== athleteId)
        : [...prev, athleteId]
    );
  };

  const handleSelectAll = () => {
    if (selectedAthletes.length === availableAthletes.length) {
      setSelectedAthletes([]);
    } else {
      setSelectedAthletes(availableAthletes.map(a => a.id));
    }
  };

  const toggleProva = (athleteId: string, provaId: string) => {
    setAthleteProvas(prev => {
      const current = prev[athleteId] || [];
      return {
        ...prev,
        [athleteId]: current.includes(provaId)
          ? current.filter(id => id !== provaId)
          : [...current, provaId]
      };
    });
  };

  const removedCount = initialAthletes.filter(id => !selectedAthletes.includes(id)).length;
  const addedCount = selectedAthletes.filter(id => !initialAthletes.includes(id)).length;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        {group ? (
          <>
            <DialogHeader>
              <DialogTitle>Editar Convocatória - Passo {step} de 5</DialogTitle>
              <DialogDescription>
                {group.evento_titulo || 'Evento sem nome'} - {group.evento_data ? format(new Date(group.evento_data), "dd 'de' MMMM 'de' yyyy", { locale: ptBR }) : 'Data não disponível'}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
          {/* Passo 1: Gerenciar Atletas */}
          {step === 1 && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <Label className="font-semibold">Atletas Convocados ({selectedAthletes.length})</Label>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleSelectAll}
                  className="h-7 text-xs"
                >
                  {selectedAthletes.length === availableAthletes.length ? 'Desselecionar Todos' : 'Selecionar Todos'}
                </Button>
              </div>

              <ScrollArea className="h-96 border rounded-lg p-3">
                <div className="space-y-2">
                  {availableAthletes.length === 0 ? (
                    <p className="text-xs text-muted-foreground text-center py-4">Nenhum atleta disponível</p>
                  ) : (
                    availableAthletes.map((athlete) => (
                      <div
                        key={athlete.id}
                        className="flex items-center gap-2 p-2 hover:bg-slate-50 rounded cursor-pointer"
                        onClick={() => toggleAthlete(athlete.id)}
                      >
                        <Checkbox
                          checked={selectedAthletes.includes(athlete.id)}
                          onCheckedChange={() => toggleAthlete(athlete.id)}
                        />
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium truncate">
                            {athlete.nome_completo || athlete.name}
                          </p>
                          {athlete.numero_socio && (
                            <p className="text-xs text-muted-foreground">Nº {athlete.numero_socio}</p>
                          )}
                        </div>
                        {initialAthletes.includes(athlete.id) && (
                          <Badge variant="outline" className="text-[10px]">Atual</Badge>
                        )}
                      </div>
                    ))
                  )}
                </div>
              </ScrollArea>

              {(removedCount > 0 || addedCount > 0) && (
                <Card className="p-3 bg-blue-50 border-blue-200">
                  <p className="text-xs text-blue-900">
                    <strong>Resumo de mudanças:</strong>{' '}
                    {addedCount > 0 && `${addedCount} atleta(s) será(ão) adicionado(s). `}
                    {removedCount > 0 && `${removedCount} atleta(s) será(ão) removido(s).`}
                  </p>
                </Card>
              )}
            </div>
          )}

          {/* Passo 2: Editar Provas */}
          {step === 2 && (
            <div className="space-y-4">
              <Card className="p-3">
                <p className="text-base font-semibold flex items-center gap-2">
                  <Users size={16} />
                  {selectedAthletes.length} atletas selecionados
                </p>
                <p className="text-sm text-muted-foreground">Selecione as provas que cada atleta irá realizar</p>
              </Card>

              <ScrollArea className="h-96 border rounded-lg p-3">
                <div className="space-y-3">
                  {selectedAthletes.length === 0 ? (
                    <p className="text-sm text-muted-foreground text-center py-4">Nenhum atleta selecionado</p>
                  ) : (
                    selectedAthletes.map((athleteId) => {
                      const athlete = users.find((u: any) => u.id === athleteId);
                      if (!athlete) return null;

                      return (
                        <Card key={athleteId} className="p-3 bg-slate-50">
                          <p className="font-semibold text-sm mb-2">{athlete.nome_completo || athlete.name}</p>
                          <div className="space-y-2">
                            {provaOptions.map((prova) => (
                              <label key={prova.id} className="flex items-center gap-2 cursor-pointer">
                                <Checkbox
                                  checked={(athleteProvas[athleteId] || []).includes(prova.id)}
                                  onCheckedChange={() => toggleProva(athleteId, prova.id)}
                                />
                                <span className="text-sm">{prova.name}</span>
                              </label>
                            ))}
                            {provaOptions.length === 0 && (
                              <p className="text-xs text-muted-foreground">Sem provas configuradas.</p>
                            )}
                          </div>
                        </Card>
                      );
                    })
                  )}
                </div>
              </ScrollArea>
            </div>
          )}

          {/* Passo 3: Informações Logísticas */}
          {step === 3 && (
            <div className="space-y-4">
              <Label htmlFor="hora_encontro">Hora de Encontro</Label>
              <Input
                id="hora_encontro"
                type="time"
                value={horaEncontro}
                onChange={(e) => setHoraEncontro(e.target.value)}
                placeholder="HH:mm"
                className="text-xs bg-white"
              />

              <Label htmlFor="local_encontro">Local de Encontro</Label>
              <Input
                id="local_encontro"
                value={localEncontro}
                onChange={(e) => setLocalEncontro(e.target.value)}
                placeholder={`Ex: ${defaultMeetingPoint}`}
                className="text-xs bg-white"
              />

              <Label htmlFor="centro_custo_id">Centro de Custos</Label>
              <Select value={centroCustoId} onValueChange={setCentroCustoId}>
                <SelectTrigger id="centro_custo_id" className="text-xs bg-white">
                  <SelectValue placeholder="Selecionar..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Sem Centro de Custos</SelectItem>
                  {costCenters.map((cc) => (
                    <SelectItem key={cc.id} value={cc.id}>
                      {cc.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Label htmlFor="observacoes">Observações</Label>
              <Textarea
                id="observacoes"
                value={observacoes}
                onChange={(e) => setObservacoes(e.target.value)}
                placeholder="Notas adicionais..."
                className="text-xs bg-white"
                rows={3}
              />
            </div>
          )}

          {/* Passo 4: Resumo */}
          {step === 4 && (
            <div className="space-y-4">
              <Card className="p-3 bg-slate-50">
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span className="text-muted-foreground">Evento:</span>
                    <p className="font-semibold">{group.evento_titulo}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Data:</span>
                    <p className="font-semibold">{format(new Date(group.evento_data), 'dd/MM/yyyy')}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Atletas:</span>
                    <p className="font-semibold">{selectedAthletes.length}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Custo Total:</span>
                    <p className="font-semibold">€{totalCost.toFixed(2)}</p>
                  </div>
                  {horaEncontro && (
                    <div className="col-span-2">
                      <span className="text-muted-foreground">Hora de Encontro:</span>
                      <p className="font-semibold">{horaEncontro}</p>
                    </div>
                  )}
                  {localEncontro && (
                    <div className="col-span-2">
                      <span className="text-muted-foreground">Local de Encontro:</span>
                      <p className="font-semibold">{localEncontro}</p>
                    </div>
                  )}
                </div>
              </Card>
            </div>
          )}

          {/* Passo 5: Confirmação */}
          {step === 5 && (
            <div className="space-y-4">
              <Card className="p-4 bg-blue-50 border-blue-200">
                <h3 className="font-semibold text-sm mb-3">Resumo das Alterações</h3>
                <div className="space-y-2 text-sm">
                  {addedCount > 0 && (
                    <div className="text-green-700">
                      ✓ {addedCount} atleta(s) será(ão) adicionado(s)
                    </div>
                  )}
                  {removedCount > 0 && (
                    <div className="text-orange-700">
                      ⚠ {removedCount} atleta(s) será(ão) removido(s)
                    </div>
                  )}
                  {addedCount === 0 && removedCount === 0 && (
                    <div className="text-blue-700">
                      Nenhuma mudança nos atletas
                    </div>
                  )}
                </div>
              </Card>

              <p className="text-sm text-muted-foreground">
                Pode guardar como rascunho sem enviar nada ou publicar explicitamente para acionar a Comunicação.
              </p>
            </div>
          )}
            </div>

            <DialogFooter className="flex gap-2 justify-between">
          <div className="flex gap-2">
            {step > 1 && (
              <Button variant="outline" onClick={() => setStep(step - 1)} disabled={isSubmitting}>
                Anterior
              </Button>
            )}
          </div>

          <div className="flex gap-2">
            {step < 5 && (
              <Button onClick={() => setStep(step + 1)} disabled={isSubmitting || (step === 1 && selectedAthletes.length === 0)}>
                Próximo
              </Button>
            )}

            {step === 5 && (
              <>
                <Button variant="outline" onClick={() => handleSave(false)} disabled={isSubmitting}>
                  {isSubmitting ? 'A guardar...' : 'Guardar rascunho'}
                </Button>
                <Button onClick={() => handleSave(true)} disabled={isSubmitting} className="bg-blue-600 hover:bg-blue-700">
                  {isSubmitting ? 'A publicar...' : 'Publicar Convocatória'}
                </Button>
              </>
            )}

            <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              Cancelar
            </Button>
          </div>
        </DialogFooter>
          </>        ) : (
          <div className="flex items-center justify-center py-8">
            <p className="text-muted-foreground">A carregar dados da convocatória...</p>
          </div>        )}
      </DialogContent>
    </Dialog>
  );
}
