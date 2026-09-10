import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Card } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/Components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
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
import { Textarea } from '@/Components/ui/textarea';
import { Plus, PencilSimple, Trash, MagnifyingGlass, X } from '@phosphor-icons/react';
import axios from 'axios';
import { toast } from 'sonner';

interface EventResult {
  id: string;
  evento_id: string;
  user_id: string;
  prova: string;
  tempo?: string;
  classificacao?: number;
  piscina?: string;
  age_group_id?: string;
  escalao?: string;
  observacoes?: string;
  epoca?: string;
  registado_por: string;
  registado_em: string;
  event?: {
    id: string;
    titulo: string;
    tipo: string;
  };
  athlete?: {
    id: string;
    nome_completo: string;
  };
  ageGroup?: {
    id: string;
    nome: string;
  };
}

interface Event {
  id: string;
  titulo: string;
  tipo: string;
  data_inicio: string;
}

interface User {
  id: string;
  nome_completo: string;
  email: string;
}

interface AgeGroup {
  id: string;
  nome: string;
  ativo?: boolean;
}

interface EventosResultadosProps {
  events: Event[];
  results?: EventResult[];
  users?: User[];
  ageGroups?: AgeGroup[];
}

interface ResultFormData {
  evento_id: string;
  user_id: string;
  prova: string;
  tempo: string;
  classificacao: string;
  piscina: string;
  age_group_id: string;
  observacoes: string;
  epoca: string;
}

const emptyForm: ResultFormData = {
  evento_id: '',
  user_id: '',
  prova: '',
  tempo: '',
  classificacao: '',
  piscina: '',
  age_group_id: '',
  observacoes: '',
  epoca: new Date().getFullYear().toString(),
};

export function EventosResultados({
  events = [],
  results: initialResults = [],
  users = [],
  ageGroups = [],
}: EventosResultadosProps) {
  const [results, setResults] = useState<EventResult[]>(initialResults);
  const [filteredResults, setFilteredResults] = useState<EventResult[]>(initialResults);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [editingResult, setEditingResult] = useState<EventResult | null>(null);
  const [deletingResult, setDeletingResult] = useState<EventResult | null>(null);
  const [formData, setFormData] = useState<ResultFormData>(emptyForm);
  const [isLoading, setIsLoading] = useState(false);

  // Filtros
  const [filterEvento, setFilterEvento] = useState('');
  const [filterProva, setFilterProva] = useState('');
  const [filterEscalao, setFilterEscalao] = useState('');
  const [filterPiscina, setFilterPiscina] = useState('');
  const [filterEpoca, setFilterEpoca] = useState('');
  const [filterClassificacao, setFilterClassificacao] = useState('');

  // Carregar resultados
  const loadResults = async () => {
    try {
      const params = new URLSearchParams();
      if (filterEvento) params.append('evento_id', filterEvento);
      if (filterProva) params.append('prova', filterProva);
      if (filterEscalao) params.append('age_group_id', filterEscalao);
      if (filterPiscina) params.append('piscina', filterPiscina);
      if (filterEpoca) params.append('epoca', filterEpoca);
      if (filterClassificacao) params.append('classificacao', filterClassificacao);

      const response = await axios.get(`/api/event-results?${params.toString()}`);
      setResults(response.data);
      setFilteredResults(response.data);
    } catch (error) {
      console.error('Erro ao carregar resultados:', error);
    }
  };

  useEffect(() => {
    loadResults();
  }, [filterEvento, filterProva, filterEscalao, filterPiscina, filterEpoca, filterClassificacao]);

  useEffect(() => {
    setFilteredResults(results);
  }, [results]);

  const handleCreate = () => {
    setEditingResult(null);
    setFormData(emptyForm);
    setIsDialogOpen(true);
  };

  const handleEdit = (result: EventResult) => {
    setEditingResult(result);
    setFormData({
      evento_id: result.evento_id,
      user_id: result.user_id,
      prova: result.prova,
      tempo: result.tempo || '',
      classificacao: result.classificacao?.toString() || '',
      piscina: result.piscina || '',
      age_group_id: result.age_group_id || '',
      observacoes: result.observacoes || '',
      epoca: result.epoca || new Date().getFullYear().toString(),
    });
    setIsDialogOpen(true);
  };

  const handleDelete = (result: EventResult) => {
    setDeletingResult(result);
    setIsDeleteDialogOpen(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);

    try {
      const payload = {
        ...formData,
        classificacao: formData.classificacao ? parseInt(formData.classificacao) : null,
      };

      if (editingResult) {
        await axios.put(`/api/event-results/${editingResult.id}`, payload);
        toast.success('Resultado atualizado com sucesso!');
      } else {
        await axios.post('/api/event-results', payload);
        toast.success('Resultado criado com sucesso!');
      }

      setIsDialogOpen(false);
      setFormData(emptyForm);
      loadResults();
    } catch (error: any) {
      console.error('Erro ao salvar resultado:', error);
      toast.error(error.response?.data?.message || 'Erro ao salvar resultado');
    } finally {
      setIsLoading(false);
    }
  };

  const confirmDelete = async () => {
    if (!deletingResult) return;

    setIsLoading(true);
    try {
      await axios.delete(`/api/event-results/${deletingResult.id}`);
      toast.success('Resultado eliminado com sucesso!');
      setIsDeleteDialogOpen(false);
      setDeletingResult(null);
      loadResults();
    } catch (error: any) {
      console.error('Erro ao eliminar resultado:', error);
      toast.error(error.response?.data?.message || 'Erro ao eliminar resultado');
    } finally {
      setIsLoading(false);
    }
  };

  const clearFilters = () => {
    setFilterEvento('');
    setFilterProva('');
    setFilterEscalao('');
    setFilterPiscina('');
    setFilterEpoca('');
    setFilterClassificacao('');
  };

  const hasActiveFilters =
    filterEvento || filterProva || filterEscalao || filterPiscina || filterEpoca || filterClassificacao;

  const getClassificacaoBadge = (classificacao?: number) => {
    if (!classificacao) return null;

    if (classificacao === 1) {
      return <Badge className="bg-yellow-500">🥇 {classificacao}º</Badge>;
    } else if (classificacao === 2) {
      return <Badge className="bg-gray-400">🥈 {classificacao}º</Badge>;
    } else if (classificacao === 3) {
      return <Badge className="bg-amber-600">🥉 {classificacao}º</Badge>;
    } else {
      return <Badge variant="outline">{classificacao}º</Badge>;
    }
  };

  // Obter lista única de valores para filtros
  const uniqueProvas = Array.from(new Set(results.map((r) => r.prova).filter(Boolean)));
  const getEscalaoLabel = (ageGroupId?: string) => {
    if (!ageGroupId) return '-';
    const group = ageGroups.find((item) => item.id === ageGroupId);
    return group?.nome || ageGroupId;
  };

  const uniqueEscaloes = Array.from(
    new Set(
      results
        .map((r) => r.age_group_id)
        .filter((value): value is string => Boolean(value && String(value).trim()))
    )
  );
  const uniqueEpocas = Array.from(
    new Set(
      results
        .map((r) => (r.epoca ? String(r.epoca).trim() : ''))
        .filter((value) => value.length > 0)
    )
  );

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-4">
          <div>
            <h3 className="text-lg font-semibold">Resultados de Provas</h3>
            <p className="text-sm text-muted-foreground">
              {filteredResults.length} resultado(s) encontrado(s)
            </p>
          </div>
          <Button onClick={handleCreate} size="sm">
            <Plus size={16} className="mr-2" />
            Novo Resultado
          </Button>
        </div>

        {/* Filtros */}
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
          <div className="space-y-1">
            <Label className="text-xs">Evento</Label>
            <Select value={filterEvento || 'all'} onValueChange={(value) => setFilterEvento(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos</SelectItem>
                {events
                  .filter((e) => e.tipo === 'prova' || e.tipo === 'competicao')
                  .filter((event) => Boolean(event.id && String(event.id).trim()))
                  .map((event) => (
                    <SelectItem key={String(event.id)} value={String(event.id)}>
                      {event.titulo}
                    </SelectItem>
                  ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Prova</Label>
            <Select value={filterProva || 'all'} onValueChange={(value) => setFilterProva(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todas</SelectItem>
                {uniqueProvas.map((prova) => (
                  <SelectItem key={String(prova)} value={String(prova)}>
                    {prova}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Escalão</Label>
            <Select value={filterEscalao || 'all'} onValueChange={(value) => setFilterEscalao(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todos</SelectItem>
                {uniqueEscaloes.map((escalaoId) => (
                  <SelectItem key={String(escalaoId)} value={String(escalaoId)}>
                    {getEscalaoLabel(escalaoId)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Piscina</Label>
            <Select value={filterPiscina || 'all'} onValueChange={(value) => setFilterPiscina(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todas</SelectItem>
                <SelectItem value="25m">25m</SelectItem>
                <SelectItem value="50m">50m</SelectItem>
                <SelectItem value="aguas_abertas">Águas Abertas</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Época</Label>
            <Select value={filterEpoca || 'all'} onValueChange={(value) => setFilterEpoca(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todas</SelectItem>
                {uniqueEpocas.map((epoca) => (
                  <SelectItem key={epoca} value={epoca}>
                    {epoca}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Classificação</Label>
            <Select value={filterClassificacao || 'all'} onValueChange={(value) => setFilterClassificacao(value === 'all' ? '' : value)}>
              <SelectTrigger className="h-9 bg-white">
                <SelectValue placeholder="Todas" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Todas</SelectItem>
                <SelectItem value="1">🥇 1º Lugar</SelectItem>
                <SelectItem value="2">🥈 2º Lugar</SelectItem>
                <SelectItem value="3">🥉 3º Lugar</SelectItem>
                <SelectItem value="podio">Pódio (1º-3º)</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        {hasActiveFilters && (
          <Button variant="outline" size="sm" onClick={clearFilters} className="mb-4">
            <X size={14} className="mr-1" />
            Limpar Filtros
          </Button>
        )}

        {/* Tabela de Resultados */}
        <div className="min-w-0">
          {filteredResults.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              <MagnifyingGlass size={48} className="mx-auto mb-2 opacity-50" />
              <p>Nenhum resultado encontrado</p>
            </div>
          ) : (
            <Table responsive>
              <TableHeader>
                <TableRow>
                  <TableHead>Evento</TableHead>
                  <TableHead>Atleta</TableHead>
                  <TableHead>Prova</TableHead>
                  <TableHead>Tempo</TableHead>
                  <TableHead>Classificação</TableHead>
                  <TableHead>Piscina</TableHead>
                  <TableHead>Escalão</TableHead>
                  <TableHead>Época</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredResults.map((result) => (
                  <TableRow key={result.id}>
                    <TableCell label="Evento" className="font-medium">
                      {result.event?.titulo || 'N/A'}
                      <div className="text-xs text-muted-foreground">{result.event?.tipo}</div>
                    </TableCell>
                    <TableCell label="Atleta">{result.athlete?.nome_completo || 'N/A'}</TableCell>
                    <TableCell label="Prova">{result.prova}</TableCell>
                    <TableCell label="Tempo" className="font-mono">{result.tempo || '-'}</TableCell>
                    <TableCell label="Classificação">{getClassificacaoBadge(result.classificacao)}</TableCell>
                    <TableCell label="Piscina">
                      {result.piscina ? (
                        <Badge variant="outline">{result.piscina}</Badge>
                      ) : (
                        '-'
                      )}
                    </TableCell>
                    <TableCell label="Escalão">{getEscalaoLabel(result.age_group_id)}</TableCell>
                    <TableCell label="Época">{result.epoca || '-'}</TableCell>
                    <TableCell label="Ações" className="text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleEdit(result)}
                        >
                          <PencilSimple size={16} />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleDelete(result)}
                          className="text-destructive hover:text-destructive"
                        >
                          <Trash size={16} />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>
      </Card>

      {/* Dialog de Criação/Edição */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              {editingResult ? 'Editar Resultado' : 'Novo Resultado'}
            </DialogTitle>
            <DialogDescription>
              Preencha os dados do resultado da prova
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2 col-span-2">
                <Label htmlFor="evento_id">
                  Evento <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={formData.evento_id}
                  onValueChange={(value) =>
                    setFormData({ ...formData, evento_id: value })
                  }
                  required
                >
                  <SelectTrigger className="bg-white">
                    <SelectValue placeholder="Selecione o evento" />
                  </SelectTrigger>
                  <SelectContent>
                    {events
                      .filter((e) => e.tipo === 'prova' || e.tipo === 'competicao')
                      .filter((event) => Boolean(event.id && String(event.id).trim()))
                      .map((event) => (
                        <SelectItem key={String(event.id)} value={String(event.id)}>
                          {event.titulo} - {event.data_inicio}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2 col-span-2">
                <Label htmlFor="user_id">
                  Atleta <span className="text-destructive">*</span>
                </Label>
                <Select
                  value={formData.user_id}
                  onValueChange={(value) =>
                    setFormData({ ...formData, user_id: value })
                  }
                  required
                >
                  <SelectTrigger className="bg-white">
                    <SelectValue placeholder="Selecione o atleta" />
                  </SelectTrigger>
                  <SelectContent>
                    {users
                      .filter((user) => Boolean(user.id && String(user.id).trim()))
                      .map((user) => (
                      <SelectItem key={String(user.id)} value={String(user.id)}>
                        {user.nome_completo}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2 col-span-2">
                <Label htmlFor="prova">
                  Prova <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="prova"
                  value={formData.prova}
                  onChange={(e) =>
                    setFormData({ ...formData, prova: e.target.value })
                  }
                  placeholder="Ex: 100m Livres"
                  required
                  className="bg-white"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="tempo">Tempo</Label>
                <Input
                  id="tempo"
                  value={formData.tempo}
                  onChange={(e) =>
                    setFormData({ ...formData, tempo: e.target.value })
                  }
                  placeholder="Ex: 01:15.45"
                  className="bg-white"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="classificacao">Classificação</Label>
                <Input
                  id="classificacao"
                  type="number"
                  min="1"
                  value={formData.classificacao}
                  onChange={(e) =>
                    setFormData({ ...formData, classificacao: e.target.value })
                  }
                  placeholder="Ex: 1, 2, 3..."
                  className="bg-white"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="piscina">Piscina</Label>
                <Select
                  value={formData.piscina}
                  onValueChange={(value) =>
                    setFormData({ ...formData, piscina: value })
                  }
                >
                  <SelectTrigger className="bg-white">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="25m">25m</SelectItem>
                    <SelectItem value="50m">50m</SelectItem>
                    <SelectItem value="aguas_abertas">Águas Abertas</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="age_group_id">Escalão</Label>
                <Select
                  value={formData.age_group_id || 'none'}
                  onValueChange={(value) =>
                    setFormData({ ...formData, age_group_id: value === 'none' ? '' : value })
                  }
                >
                  <SelectTrigger id="age_group_id" className="bg-white">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Sem escalão</SelectItem>
                    {ageGroups
                      .filter((group) => group.ativo !== false)
                      .filter((group) => Boolean(group.id && String(group.id).trim()))
                      .map((group) => (
                        <SelectItem key={String(group.id)} value={String(group.id)}>
                          {group.nome}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2 col-span-2">
                <Label htmlFor="epoca">Época</Label>
                <Input
                  id="epoca"
                  value={formData.epoca}
                  onChange={(e) =>
                    setFormData({ ...formData, epoca: e.target.value })
                  }
                  placeholder={`Ex: ${new Date().getFullYear()}`}
                  className="bg-white"
                />
              </div>

              <div className="space-y-2 col-span-2">
                <Label htmlFor="observacoes">Observações</Label>
                <Textarea
                  id="observacoes"
                  value={formData.observacoes}
                  onChange={(e) =>
                    setFormData({ ...formData, observacoes: e.target.value })
                  }
                  placeholder="Observações adicionais..."
                  rows={3}
                  className="bg-white"
                />
              </div>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsDialogOpen(false)}
                disabled={isLoading}
              >
                Cancelar
              </Button>
              <Button type="submit" disabled={isLoading}>
                {isLoading ? 'Guardando...' : editingResult ? 'Atualizar' : 'Criar'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Dialog de Confirmação de Eliminação */}
      <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirmar Eliminação</AlertDialogTitle>
            <AlertDialogDescription>
              Tem a certeza que deseja eliminar este resultado? Esta ação não pode ser
              desfeita.
              {deletingResult && (
                <div className="mt-2 p-2 bg-muted rounded text-sm">
                  <strong>Atleta:</strong> {deletingResult.athlete?.nome_completo}
                  <br />
                  <strong>Prova:</strong> {deletingResult.prova}
                </div>
              )}
            </AlertDialogDescription>
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

