import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { PencilSimple, Plus, Trash } from '@phosphor-icons/react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';

export type SponsorDirectoryEntry = {
  id: string;
  nome: string;
  descricao?: string | null;
  logo?: string | null;
  website?: string | null;
  contacto?: string | null;
  email?: string | null;
  tipo: 'principal' | 'secundario' | 'apoio';
  valor_anual?: number | string | null;
  data_inicio: string;
  data_fim?: string | null;
  estado: 'ativo' | 'inativo' | 'expirado';
};

type Props = {
  sponsors: SponsorDirectoryEntry[];
};

const emptySponsor = () => ({
  nome: '',
  descricao: '',
  logo: null as File | null,
  website: '',
  contacto: '',
  email: '',
  tipo: 'secundario' as SponsorDirectoryEntry['tipo'],
  valor_anual: '' as number | '',
  data_inicio: new Date().toISOString().slice(0, 10),
  data_fim: '',
  estado: 'ativo' as SponsorDirectoryEntry['estado'],
});

export function SponsorDirectoryTab({ sponsors }: Props) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<SponsorDirectoryEntry | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const form = useForm(emptySponsor());

  const openCreate = () => {
    setEditing(null);
    setLogoPreview(null);
    form.clearErrors();
    form.setData(emptySponsor());
    setDialogOpen(true);
  };

  const openEdit = (sponsor: SponsorDirectoryEntry) => {
    setEditing(sponsor);
    setLogoPreview(sponsor.logo || null);
    form.clearErrors();
    form.setData({
      nome: sponsor.nome,
      descricao: sponsor.descricao || '',
      logo: null,
      website: sponsor.website || '',
      contacto: sponsor.contacto || '',
      email: sponsor.email || '',
      tipo: sponsor.tipo,
      valor_anual: sponsor.valor_anual === null || sponsor.valor_anual === undefined ? '' : Number(sponsor.valor_anual),
      data_inicio: String(sponsor.data_inicio).slice(0, 10),
      data_fim: sponsor.data_fim ? String(sponsor.data_fim).slice(0, 10) : '',
      estado: sponsor.estado,
    });
    setDialogOpen(true);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();

    const options = {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        setDialogOpen(false);
        setEditing(null);
        setLogoPreview(null);
        form.reset();
        toast.success(editing ? 'Patrocinador atualizado.' : 'Patrocinador criado.');
      },
      onError: () => toast.error('Não foi possível guardar o patrocinador.'),
    };

    if (editing) {
      form.put(route('patrocinios.patrocinadores.update', editing.id), options);
      return;
    }

    form.post(route('patrocinios.patrocinadores.store'), options);
  };

  const remove = (sponsor: SponsorDirectoryEntry) => {
    if (!confirm(`Eliminar o patrocinador "${sponsor.nome}"? Esta ação só é permitida sem patrocínios associados.`)) {
      return;
    }

    router.delete(route('patrocinios.patrocinadores.destroy', sponsor.id), {
      preserveScroll: true,
      onSuccess: () => toast.success('Patrocinador eliminado.'),
      onError: () => toast.error('Não é possível eliminar este patrocinador enquanto tiver patrocínios associados.'),
    });
  };

  return (
    <>
      <Card className="gap-0 py-0">
        <CardHeader className="flex flex-row items-start justify-between gap-3 p-3">
          <div>
            <CardTitle className="text-sm">Entidades patrocinadoras</CardTitle>
            <CardDescription className="mt-1 text-xs">
              Base canónica usada pelos contratos e apoios do módulo de Patrocínios.
            </CardDescription>
          </div>
          <Button type="button" size="sm" className="h-8 gap-1.5 text-xs" onClick={openCreate}>
            <Plus size={14} />
            Adicionar
          </Button>
        </CardHeader>
        <CardContent className="p-3 pt-0">
          <Table responsive>
            <TableHeader>
              <TableRow>
                <TableHead>Logo</TableHead>
                <TableHead>Nome</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Contacto</TableHead>
                <TableHead>Valor anual</TableHead>
                <TableHead>Período</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sponsors.map((sponsor) => (
                <TableRow key={sponsor.id}>
                  <TableCell label="Logo">
                    {sponsor.logo ? (
                      <img src={sponsor.logo} alt={sponsor.nome} className="h-9 w-9 rounded object-cover" />
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell label="Nome">
                    <div className="font-medium">{sponsor.nome}</div>
                    <div className="max-w-72 truncate text-xs text-muted-foreground">{sponsor.email || sponsor.website || 'Sem contacto digital'}</div>
                  </TableCell>
                  <TableCell label="Tipo" className="capitalize">{sponsor.tipo}</TableCell>
                  <TableCell label="Contacto">{sponsor.contacto || '-'}</TableCell>
                  <TableCell label="Valor anual">
                    {sponsor.valor_anual !== null && sponsor.valor_anual !== undefined && sponsor.valor_anual !== ''
                      ? new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(Number(sponsor.valor_anual))
                      : '-'}
                  </TableCell>
                  <TableCell label="Período">
                    <div>{String(sponsor.data_inicio).slice(0, 10)}</div>
                    <div className="text-xs text-muted-foreground">até {sponsor.data_fim ? String(sponsor.data_fim).slice(0, 10) : 'sem fim'}</div>
                  </TableCell>
                  <TableCell label="Estado" className="capitalize">{sponsor.estado}</TableCell>
                  <TableCell label="Ações" className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => openEdit(sponsor)} aria-label={`Editar ${sponsor.nome}`}>
                        <PencilSimple size={15} />
                      </Button>
                      <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0 text-rose-700" onClick={() => remove(sponsor)} aria-label={`Eliminar ${sponsor.nome}`}>
                        <Trash size={15} />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
              {sponsors.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8} className="py-8 text-center text-sm text-muted-foreground">
                    Ainda não existem entidades patrocinadoras.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editing ? 'Editar patrocinador' : 'Adicionar patrocinador'}</DialogTitle>
          </DialogHeader>
          <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="sponsor-name">Nome *</Label>
                <Input id="sponsor-name" value={form.data.nome} onChange={(event) => form.setData('nome', event.target.value)} required />
                {form.errors.nome && <p className="text-xs text-rose-600">{form.errors.nome}</p>}
              </div>
              <div className="space-y-2">
                <Label>Tipo *</Label>
                <Select value={form.data.tipo} onValueChange={(value) => form.setData('tipo', value as SponsorDirectoryEntry['tipo'])}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="principal">Principal</SelectItem>
                    <SelectItem value="secundario">Secundário</SelectItem>
                    <SelectItem value="apoio">Apoio</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Estado *</Label>
                <Select value={form.data.estado} onValueChange={(value) => form.setData('estado', value as SponsorDirectoryEntry['estado'])}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ativo">Ativo</SelectItem>
                    <SelectItem value="inativo">Inativo</SelectItem>
                    <SelectItem value="expirado">Expirado</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-email">Email</Label>
                <Input id="sponsor-email" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-contact">Contacto</Label>
                <Input id="sponsor-contact" value={form.data.contacto} onChange={(event) => form.setData('contacto', event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-website">Website</Label>
                <Input id="sponsor-website" type="url" value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-value">Valor anual (€)</Label>
                <Input
                  id="sponsor-value"
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.data.valor_anual}
                  onChange={(event) => form.setData('valor_anual', event.target.value === '' ? '' : Number(event.target.value))}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-start">Data de início *</Label>
                <Input id="sponsor-start" type="date" value={form.data.data_inicio} onChange={(event) => form.setData('data_inicio', event.target.value)} required />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sponsor-end">Data de fim</Label>
                <Input id="sponsor-end" type="date" value={form.data.data_fim} onChange={(event) => form.setData('data_fim', event.target.value)} />
              </div>
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="sponsor-description">Descrição</Label>
                <Textarea id="sponsor-description" value={form.data.descricao} onChange={(event) => form.setData('descricao', event.target.value)} />
              </div>
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="sponsor-logo">Logotipo</Label>
                <Input
                  id="sponsor-logo"
                  type="file"
                  accept="image/*"
                  onChange={(event) => {
                    const file = event.target.files?.[0] || null;
                    form.setData('logo', file);
                    if (file) setLogoPreview(URL.createObjectURL(file));
                  }}
                />
                {form.errors.logo && <p className="text-xs text-rose-600">{form.errors.logo}</p>}
                {logoPreview && <img src={logoPreview} alt="Pré-visualização do logotipo" className="h-20 w-20 rounded border object-cover" />}
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setDialogOpen(false)} disabled={form.processing}>Cancelar</Button>
              <Button type="submit" disabled={form.processing}>{form.processing ? 'A guardar...' : 'Guardar'}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
