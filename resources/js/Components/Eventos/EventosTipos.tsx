import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { Plus, Pencil, Trash } from '@phosphor-icons/react';
import { toast } from 'sonner';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table';

interface EventType {
  id: string;
  nome: string;
  cor: string;
  icon: string;
  ativo: boolean;
  requer_convocatoria: boolean;
  requer_transporte?: boolean;
}

interface EventosTiposProps {
  types?: EventType[];
  onTypeCreate?: (type: EventType) => void;
  onTypeUpdate?: (type: EventType) => void;
  onTypeDelete?: (id: string) => void;
}

export function EventosTipos({ types = [], onTypeCreate, onTypeUpdate, onTypeDelete }: EventosTiposProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingType, setEditingType] = useState<EventType | null>(null);
  const [formData, setFormData] = useState({
    nome: '',
    cor: '#3b82f6',
    icon: 'flag',
    requer_convocatoria: true,
  });

  const activeTipos = types.filter((t) => t.ativo);

  const handleSave = () => {
    if (!formData.nome.trim()) {
      toast.error('Preencha o nome do tipo');
      return;
    }

    if (editingType) {
      const updated: EventType = {
        ...editingType,
        ...formData,
      };
      onTypeUpdate?.(updated);
      toast.success('Tipo atualizado!');
    } else {
      const newType: EventType = {
        id: crypto.randomUUID(),
        ...formData,
        ativo: true,
      };
      onTypeCreate?.(newType);
      toast.success('Tipo criado!');
    }

    setDialogOpen(false);
    resetForm();
  };

  const resetForm = () => {
    setFormData({
      nome: '',
      cor: '#3b82f6',
      icon: 'flag',
      requer_convocatoria: true,
    });
    setEditingType(null);
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="text-sm text-muted-foreground">
          {activeTipos.length} tipo(s) de evento ativo(s)
        </div>

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button onClick={() => resetForm()} size="sm">
              <Plus size={16} className="mr-2" />
              Novo Tipo
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>
                {editingType ? 'Editar Tipo' : 'Novo Tipo de Evento'}
              </DialogTitle>
            </DialogHeader>

            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="nome">Nome *</Label>
                <Input
                  id="nome"
                  value={formData.nome}
                  onChange={(e) =>
                    setFormData({ ...formData, nome: e.target.value })
                  }
                  placeholder="Ex: Prova, Treino"
                  className="bg-white"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="cor">Cor</Label>
                  <input
                    id="cor"
                    type="color"
                    value={formData.cor}
                    onChange={(e) =>
                      setFormData({ ...formData, cor: e.target.value })
                    }
                    className="w-full h-10 rounded"
                  />
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={() => setDialogOpen(false)}>
                Cancelar
              </Button>
              <Button onClick={handleSave}>Guardar</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nome</TableHead>
              <TableHead>Cor</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead className="text-right">Ações</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {types.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="text-center py-8 text-muted-foreground">
                  Nenhum tipo criado
                </TableCell>
              </TableRow>
            ) : (
              types.map((type) => (
                <TableRow key={type.id}>
                  <TableCell>{type.nome}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <div
                        className="w-6 h-6 rounded"
                        style={{ backgroundColor: type.cor }}
                      />
                      <span className="text-xs text-muted-foreground">
                        {type.cor}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge variant={type.ativo ? 'default' : 'outline'}>
                      {type.ativo ? 'Ativo' : 'Inativo'}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right space-x-2">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        setEditingType(type);
                        setFormData({
                          nome: type.nome,
                          cor: type.cor,
                          icon: type.icon,
                          requer_convocatoria: type.requer_convocatoria,
                        });
                        setDialogOpen(true);
                      }}
                    >
                      <Pencil size={16} />
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => {
                        onTypeDelete?.(type.id);
                        toast.success('Tipo eliminado');
                      }}
                    >
                      <Trash size={16} className="text-red-500" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </Card>
    </div>
  );
}
