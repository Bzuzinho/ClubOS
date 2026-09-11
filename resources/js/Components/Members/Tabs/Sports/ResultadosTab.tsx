import { CompetitionHistory } from './CompetitionHistory';
import { User, ResultadoProva, Event } from '@/types';
import { useKV } from '@/hooks/useKV';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { Plus, Pencil, Trash, Trophy } from 'lucide-react';
import { useState, useMemo } from 'react';
import { toast } from 'sonner';

interface Prova {
  id: string;
  name: string;
}

interface ResultadosTabProps {
  user: User;
  isAdmin: boolean;
}

export function ResultadosTab({ user, isAdmin }: ResultadosTabProps) {
  const [resultadosProvas, setResultadosProvas] = useKV<ResultadoProva[]>('club-resultados-provas', []);
  const [events] = useKV<Event[]>('club-events', []);
  // Disabled: settings-provas returns 500
  const [provas] = useState<Prova[]>([]);
  
  const [resultDialogOpen, setResultDialogOpen] = useState(false);
  const [editingResult, setEditingResult] = useState<ResultadoProva | null>(null);
  const [resultForm, setResultForm] = useState({
    evento_id: '',
    evento_nome: '',
    prova: '',
    local: '',
    data: '',
    piscina: 'piscina_25m' as 'piscina_25m' | 'piscina_50m' | 'aguas_abertas',
    tempo_final: '',
  });

  const atletaResultados = useMemo(() => {
    return (resultadosProvas || [])
      .filter(r => r.atleta_id === user.id)
      .sort((a, b) => new Date(b.data).getTime() - new Date(a.data).getTime());
  }, [resultadosProvas, user.id]);

  const handleSaveResult = async () => {
    if (!resultForm.prova || !resultForm.local || !resultForm.data || !resultForm.tempo_final) {
      toast.error('Preencha todos os campos obrigatórios');
      return;
    }

    if (!resultForm.evento_nome && !resultForm.evento_id) {
      toast.error('Digite o nome do evento ou selecione um da lista');
      return;
    }

    if (editingResult) {
      setResultadosProvas(current =>
        (current || []).map(r =>
          r.id === editingResult.id
            ? {
                ...r,
                evento_id: resultForm.evento_id,
                evento_nome: resultForm.evento_nome,
                prova: resultForm.prova,
                local: resultForm.local,
                data: resultForm.data,
                piscina: resultForm.piscina,
                tempo_final: resultForm.tempo_final,
                updated_at: new Date().toISOString(),
              }
            : r
        )
      );
      toast.success('Resultado atualizado');
    } else {
      const newResult: ResultadoProva = {
        id: crypto.randomUUID(),
        atleta_id: user.id,
        evento_id: resultForm.evento_id,
        evento_nome: resultForm.evento_nome,
        prova: resultForm.prova,
        local: resultForm.local,
        data: resultForm.data,
        piscina: resultForm.piscina,
        tempo_final: resultForm.tempo_final,
        created_at: new Date().toISOString(),
      };
      setResultadosProvas(current => [...(current || []), newResult]);
      toast.success('Resultado adicionado');
    }

    setResultDialogOpen(false);
  };

  return (
    <div className="space-y-4">
      <CompetitionHistory key={user.id} athleteId={user.id} />
      <h3 className="text-sm font-semibold">Resultados de Eventos</h3>
      <div className="flex items-center justify-between">
        <p className="text-xs text-muted-foreground">Registos de provas associados a Eventos</p>
        {isAdmin && (
          <Button
            size="sm"
            onClick={() => {
              setEditingResult(null);
              setResultForm({
                evento_id: '',
                evento_nome: '',
                prova: '',
                local: '',
                data: '',
                piscina: 'piscina_25m',
                tempo_final: '',
              });
              setResultDialogOpen(true);
            }}
            className="h-7 text-xs"
          >
            <Plus className="mr-1" size={12} />
            Adicionar
          </Button>
        )}
      </div>

      {atletaResultados.length === 0 ? (
        <div className="p-8 border rounded-lg text-center">
          <Trophy className="mx-auto text-muted-foreground mb-2" size={32} />
          <p className="text-sm text-muted-foreground">Nenhum resultado registado</p>
        </div>
      ) : (
        <ScrollArea className="h-[400px] border rounded-lg">
          <Table responsive>
            <TableHeader>
              <TableRow>
                <TableHead className="text-xs">Evento</TableHead>
                <TableHead className="text-xs">Local</TableHead>
                <TableHead className="text-xs">Data</TableHead>
                <TableHead className="text-xs">Prova</TableHead>
                <TableHead className="text-xs">Piscina</TableHead>
                <TableHead className="text-xs">Tempo</TableHead>
                {isAdmin && <TableHead className="text-xs text-right">Ações</TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {atletaResultados.map(resultado => {
                const evento = events?.find(e => e.id === resultado.evento_id);
                const eventoNome = resultado.evento_nome || evento?.titulo || '-';
                const piscinaLabel = resultado.piscina === 'piscina_25m' ? '25m' 
                  : resultado.piscina === 'piscina_50m' ? '50m' 
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
                    {isAdmin && (
                      <TableCell label="Ações" className="text-right">
                        <div className="flex gap-1 justify-end">
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-6 w-6 p-0"
                            onClick={() => {
                              setEditingResult(resultado);
                              const evento = events?.find(e => e.id === resultado.evento_id);
                              setResultForm({
                                evento_id: resultado.evento_id,
                                evento_nome: resultado.evento_nome || evento?.titulo || '',
                                prova: resultado.prova,
                                local: resultado.local,
                                data: resultado.data,
                                piscina: resultado.piscina,
                                tempo_final: resultado.tempo_final,
                              });
                              setResultDialogOpen(true);
                            }}
                          >
                            <Pencil size={12} />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-6 w-6 p-0 text-destructive"
                            onClick={async () => {
                              if (confirm('Tem certeza que deseja eliminar este resultado?')) {
                                setResultadosProvas(current => 
                                  (current || []).filter(r => r.id !== resultado.id)
                                );
                                toast.success('Resultado eliminado');
                              }
                            }}
                          >
                            <Trash size={12} />
                          </Button>
                        </div>
                      </TableCell>
                    )}
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </ScrollArea>
      )}

      <Dialog open={resultDialogOpen} onOpenChange={setResultDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="text-sm">
              {editingResult ? 'Editar Resultado' : 'Adicionar Resultado'}
            </DialogTitle>
            <DialogDescription>
              {editingResult ? 'Altere o resultado da prova' : 'Registe um novo resultado de prova'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label htmlFor="result-evento" className="text-xs">Nome do Evento</Label>
                <Input
                  id="result-evento"
                  value={resultForm.evento_nome}
                  onChange={(e) => {
                    setResultForm(prev => ({ 
                      ...prev, 
                      evento_nome: e.target.value,
                      evento_id: ''
                    }));
                  }}
                  placeholder="Digite o nome..."
                  className="h-7 text-xs"
                  list="eventos-list"
                />
                <datalist id="eventos-list">
                  {(events || [])
                    .filter(e => e.tipo === 'prova')
                    .map(evento => (
                      <option key={evento.id} value={evento.titulo} />
                    ))}
                </datalist>
              </div>

              <div className="space-y-1">
                <Label htmlFor="result-evento-select" className="text-xs">Ou selecione da lista</Label>
                <Select
                  value={resultForm.evento_id || 'manual'}
                  onValueChange={(value) => {
                    if (value === 'manual') {
                      setResultForm(prev => ({ ...prev, evento_id: '', evento_nome: '' }));
                    } else {
                      const evento = events?.find(e => e.id === value);
                      if (evento) {
                        setResultForm(prev => ({
                          ...prev,
                          evento_id: value,
                          evento_nome: evento.titulo,
                          local: evento.local || prev.local,
                          data: evento.data_inicio || prev.data,
                          piscina: evento.tipo_piscina || prev.piscina,
                        }));
                      }
                    }
                  }}
                >
                  <SelectTrigger id="result-evento-select" className="h-7 text-xs">
                    <SelectValue placeholder="Escolher..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="manual">Entrada manual</SelectItem>
                    {(events || [])
                      .filter(e => e.tipo === 'prova')
                      .map(evento => (
                        <SelectItem key={evento.id} value={evento.id}>
                          {evento.titulo} - {format(new Date(evento.data_inicio), 'dd/MM/yyyy')}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1">
              <Label htmlFor="result-prova" className="text-xs">Prova *</Label>
              <Select
                value={resultForm.prova}
                onValueChange={(value) => setResultForm(prev => ({ ...prev, prova: value }))}
              >
                <SelectTrigger id="result-prova" className="h-7 text-xs">
                  <SelectValue placeholder="Selecionar..." />
                </SelectTrigger>
                <SelectContent>
                  {(provas || []).map(prova => (
                    <SelectItem key={prova.id} value={prova.name}>
                      {prova.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label htmlFor="result-local" className="text-xs">Local *</Label>
                <Input
                  id="result-local"
                  value={resultForm.local}
                  onChange={(e) => setResultForm(prev => ({ ...prev, local: e.target.value }))}
                  placeholder="Ex: Piscina Municipal"
                  className="h-7 text-xs"
                />
              </div>

              <div className="space-y-1">
                <Label htmlFor="result-data" className="text-xs">Data *</Label>
                <Input
                  id="result-data"
                  type="date"
                  value={resultForm.data}
                  onChange={(e) => setResultForm(prev => ({ ...prev, data: e.target.value }))}
                  className="h-7 text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label htmlFor="result-piscina" className="text-xs">Tipo de Piscina *</Label>
                <Select
                  value={resultForm.piscina}
                  onValueChange={(value) => setResultForm(prev => ({ 
                    ...prev, 
                    piscina: value as 'piscina_25m' | 'piscina_50m' | 'aguas_abertas' 
                  }))}
                >
                  <SelectTrigger id="result-piscina" className="h-7 text-xs">
                    <SelectValue placeholder="Selecionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="piscina_25m">25 Metros</SelectItem>
                    <SelectItem value="piscina_50m">50 Metros</SelectItem>
                    <SelectItem value="aguas_abertas">Águas Abertas</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <Label htmlFor="result-tempo" className="text-xs">Tempo Final *</Label>
                <Input
                  id="result-tempo"
                  value={resultForm.tempo_final}
                  onChange={(e) => setResultForm(prev => ({ ...prev, tempo_final: e.target.value }))}
                  placeholder="Ex: 01:23.45"
                  className="h-7 text-xs"
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setResultDialogOpen(false)} className="h-7 text-xs">
              Cancelar
            </Button>
            <Button size="sm" className="h-7 text-xs" onClick={handleSaveResult}>
              {editingResult ? 'Atualizar' : 'Adicionar'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
