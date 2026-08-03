import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

interface RelatedMember {
  id: string;
  nome_completo: string;
  numero_socio?: string | null;
  estado?: string | null;
  tipo_membro?: string[];
  email?: string | null;
  contacto?: string | null;
  menor?: boolean;
  data_nascimento?: string | null;
}

interface FamilyEntry {
  id: string;
  nome: string;
  ativo: boolean;
  papel_do_membro?: string | null;
  members: RelatedMemberWithRole[];
}

interface RelatedMemberWithRole extends RelatedMember {
  papel_na_familia?: string | null;
}

interface FamilyContext {
  is_guardian_profile: boolean;
  is_dependent_profile: boolean;
  guardians: RelatedMember[];
  dependents: RelatedMember[];
  families: FamilyEntry[];
  summary?: {
    guardians_count?: number;
    dependents_count?: number;
    families_count?: number;
    family_members_count?: number;
  };
  can_manage_family_relations: boolean;
}

interface FamilyTabProps {
  memberId: string;
  familyContext?: FamilyContext;
  allUsers: RelatedMember[];
  onOpenMember: (memberId: string) => void;
}

type RelationMode = 'guardian' | 'dependent';
type FamilyRole = 'responsavel' | 'encarregado_educacao' | 'educando' | 'familiar';

const FAMILY_ROLE_LABELS: Record<FamilyRole, string> = {
  responsavel: 'Responsável pela família',
  encarregado_educacao: 'Encarregado de educação',
  educando: 'Educando',
  familiar: 'Familiar',
};

const defaultContext: FamilyContext = {
  is_guardian_profile: false,
  is_dependent_profile: false,
  guardians: [],
  dependents: [],
  families: [],
  summary: {
    guardians_count: 0,
    dependents_count: 0,
    families_count: 0,
    family_members_count: 0,
  },
  can_manage_family_relations: false,
};

const normalizeToken = (value: string): string => value
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '_')
  .replace(/^_+|_+$/g, '');

const isGuardianCandidate = (member: RelatedMember): boolean => (
  Array.isArray(member.tipo_membro)
  && member.tipo_membro.some((type) => normalizeToken(type) === 'encarregado_educacao')
);

const isMinorCandidate = (member: RelatedMember): boolean => {
  if (member.menor) return true;
  if (!member.data_nascimento) return false;

  const birthDate = new Date(member.data_nascimento);
  if (Number.isNaN(birthDate.getTime())) return false;

  const today = new Date();
  let age = today.getFullYear() - birthDate.getFullYear();
  const monthDifference = today.getMonth() - birthDate.getMonth();
  if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) age -= 1;

  return age < 18;
};

const formatMemberType = (memberTypes: string[] | undefined): string => {
  if (!Array.isArray(memberTypes) || memberTypes.length === 0) return 'Sem tipo definido';
  return memberTypes.join(', ');
};

const normalizeFamilyRole = (role?: string | null): FamilyRole => {
  const normalized = normalizeToken(role || 'familiar');
  if (normalized === 'responsavel' || normalized === 'encarregado_educacao' || normalized === 'educando') {
    return normalized;
  }
  return 'familiar';
};

const firstError = (errors: Record<string, string>): string => Object.values(errors)[0] || 'Não foi possível guardar a relação familiar.';

export function FamilyTab({ memberId, familyContext, allUsers, onOpenMember }: FamilyTabProps) {
  const context = familyContext ?? defaultContext;
  const [processing, setProcessing] = useState(false);
  const [relationMode, setRelationMode] = useState<RelationMode | null>(null);
  const [relationSearch, setRelationSearch] = useState('');
  const [selectedRelationMemberId, setSelectedRelationMemberId] = useState('');
  const [familyDialogOpen, setFamilyDialogOpen] = useState(false);
  const [selectedFamilyId, setSelectedFamilyId] = useState<string | null>(null);
  const [selectedFamilyMemberId, setSelectedFamilyMemberId] = useState('');
  const [selectedFamilyRole, setSelectedFamilyRole] = useState<FamilyRole>('familiar');
  const [editingMembership, setEditingMembership] = useState<{ family: FamilyEntry; member: RelatedMemberWithRole } | null>(null);
  const [editingRole, setEditingRole] = useState<FamilyRole>('familiar');

  const linkedGuardianIds = useMemo(() => new Set(context.guardians.map((guardian) => guardian.id)), [context.guardians]);
  const linkedDependentIds = useMemo(() => new Set(context.dependents.map((dependent) => dependent.id)), [context.dependents]);
  const familyMemberIds = useMemo(
    () => new Set(context.families.flatMap((family) => family.members.map((familyMember) => familyMember.id))),
    [context.families],
  );
  const ungroupedDependents = useMemo(
    () => context.dependents.filter((dependent) => !familyMemberIds.has(dependent.id)),
    [context.dependents, familyMemberIds],
  );
  const canManageDependents = context.can_manage_family_relations
    && (context.is_guardian_profile || context.dependents.length > 0);

  const relationCandidates = useMemo(() => {
    if (!relationMode) return [];
    const search = normalizeToken(relationSearch);

    return allUsers
      .filter((candidate) => candidate.id !== memberId)
      .filter((candidate) => relationMode === 'guardian' ? isGuardianCandidate(candidate) : isMinorCandidate(candidate))
      .filter((candidate) => relationMode === 'guardian' ? !linkedGuardianIds.has(candidate.id) : !linkedDependentIds.has(candidate.id))
      .filter((candidate) => search === '' || normalizeToken(`${candidate.nome_completo} ${candidate.numero_socio || ''}`).includes(search))
      .sort((left, right) => left.nome_completo.localeCompare(right.nome_completo, 'pt'));
  }, [allUsers, linkedDependentIds, linkedGuardianIds, memberId, relationMode, relationSearch]);

  const selectedFamily = selectedFamilyId
    ? context.families.find((family) => family.id === selectedFamilyId)
    : null;
  const selectedFamilyMemberIds = new Set(
    selectedFamily?.members.map((member) => member.id)
      ?? [memberId, ...linkedGuardianIds, ...linkedDependentIds],
  );
  const familyCandidates = allUsers
    .filter((candidate) => candidate.id !== memberId && !selectedFamilyMemberIds.has(candidate.id))
    .sort((left, right) => left.nome_completo.localeCompare(right.nome_completo, 'pt'));

  const resetRelationDialog = () => {
    setRelationMode(null);
    setRelationSearch('');
    setSelectedRelationMemberId('');
  };

  const openRelationDialog = (mode: RelationMode) => {
    setRelationMode(mode);
    setRelationSearch('');
    setSelectedRelationMemberId('');
  };

  const addRelation = () => {
    if (!relationMode || !selectedRelationMemberId) return;
    const targetMemberId = relationMode === 'guardian' ? memberId : selectedRelationMemberId;
    const guardianId = relationMode === 'guardian' ? selectedRelationMemberId : memberId;

    setProcessing(true);
    router.post(route('membros.familia.encarregados.store', targetMemberId), {
      guardian_id: guardianId,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success(relationMode === 'guardian' ? 'Encarregado de educação adicionado.' : 'Educando adicionado.');
        resetRelationDialog();
      },
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  const removeGuardian = (guardian: RelatedMember) => {
    if (!window.confirm(`Remover ${guardian.nome_completo} como encarregado de educação?`)) return;

    setProcessing(true);
    router.delete(route('membros.familia.encarregados.destroy', [memberId, guardian.id]), {
      preserveScroll: true,
      onSuccess: () => toast.success('Encarregado de educação removido.'),
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  const removeDependent = (dependent: RelatedMember) => {
    if (!window.confirm(`Remover a relação com ${dependent.nome_completo}?`)) return;

    setProcessing(true);
    router.delete(route('membros.familia.encarregados.destroy', [dependent.id, memberId]), {
      preserveScroll: true,
      onSuccess: () => toast.success('Educando removido.'),
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  const openFamilyDialog = (familyId: string | null) => {
    setSelectedFamilyId(familyId);
    setSelectedFamilyMemberId('');
    setSelectedFamilyRole('familiar');
    setFamilyDialogOpen(true);
  };

  const addFamilyMember = () => {
    if (!selectedFamilyMemberId) return;

    setProcessing(true);
    router.post(route('membros.familia.membros.store', memberId), {
      family_id: selectedFamilyId,
      member_id: selectedFamilyMemberId,
      papel_na_familia: selectedFamilyRole,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Membro adicionado à família.');
        setFamilyDialogOpen(false);
      },
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  const openEditMembership = (family: FamilyEntry, familyMember: RelatedMemberWithRole) => {
    setEditingMembership({ family, member: familyMember });
    setEditingRole(normalizeFamilyRole(familyMember.papel_na_familia));
  };

  const updateFamilyMembership = () => {
    if (!editingMembership) return;

    setProcessing(true);
    router.patch(route('membros.familia.membros.update', [memberId, editingMembership.family.id, editingMembership.member.id]), {
      papel_na_familia: editingRole,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Relação familiar atualizada.');
        setEditingMembership(null);
      },
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  const removeFamilyMember = (family: FamilyEntry, familyMember: RelatedMemberWithRole) => {
    if (!window.confirm(`Remover ${familyMember.nome_completo} da família ${family.nome}?`)) return;

    setProcessing(true);
    router.delete(route('membros.familia.membros.destroy', [memberId, family.id, familyMember.id]), {
      preserveScroll: true,
      onSuccess: () => toast.success('Membro removido da família.'),
      onError: (errors) => toast.error(firstError(errors)),
      onFinish: () => setProcessing(false),
    });
  };

  return (
    <div className="space-y-2">
      <Card className="p-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 className="text-sm font-semibold">Encarregados de educação</h3>
            <p className="mt-1 text-xs text-muted-foreground">Responsáveis associados diretamente a este membro.</p>
          </div>
          {context.can_manage_family_relations && (
            <Button type="button" size="sm" className="h-7 text-xs" onClick={() => openRelationDialog('guardian')} disabled={processing}>
              <Plus className="mr-1 h-3.5 w-3.5" /> Adicionar
            </Button>
          )}
        </div>

        {context.guardians.length === 0 ? (
          <p className="mt-3 rounded border border-dashed p-3 text-sm text-muted-foreground">Sem encarregado de educação associado.</p>
        ) : (
          <div className="mt-3 grid gap-2 lg:grid-cols-2">
            {context.guardians.map((guardian) => (
              <div key={guardian.id} className="rounded border p-3">
                <p className="text-sm font-semibold">{guardian.nome_completo}</p>
                <p className="text-xs text-muted-foreground">
                  {guardian.numero_socio ? `Sócio #${guardian.numero_socio}` : 'Sem número de sócio'} · {guardian.estado || 'Sem estado'}
                </p>
                <p className="mt-1 text-xs text-muted-foreground">Contacto: {guardian.contacto || 'Sem contacto'}</p>
                <p className="text-xs text-muted-foreground">Email: {guardian.email || 'Sem email'}</p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(guardian.id)}>
                    <Pencil className="mr-1 h-3.5 w-3.5" /> Editar ficha
                  </Button>
                  {context.can_manage_family_relations && (
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs text-destructive hover:text-destructive" onClick={() => removeGuardian(guardian)} disabled={processing}>
                      <Trash2 className="mr-1 h-3.5 w-3.5" /> Remover
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="p-3">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 className="text-sm font-semibold">Família</h3>
            <p className="mt-1 text-xs text-muted-foreground">Agregado familiar, educandos e papel de cada membro.</p>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {canManageDependents && (
              <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => openRelationDialog('dependent')} disabled={processing}>
                <Plus className="mr-1 h-3.5 w-3.5" /> Adicionar educando
              </Button>
            )}
            {context.can_manage_family_relations && context.families.length === 0 && (
              <Button type="button" size="sm" className="h-7 text-xs" onClick={() => openFamilyDialog(null)} disabled={processing}>
                <Plus className="mr-1 h-3.5 w-3.5" /> Adicionar membro
              </Button>
            )}
          </div>
        </div>

        {context.families.length === 0 && ungroupedDependents.length === 0 ? (
          <p className="mt-3 rounded border border-dashed p-3 text-sm text-muted-foreground">Sem família agregada para este membro.</p>
        ) : (
          <div className="mt-3 space-y-3">
            {context.families.map((family) => (
              <div key={family.id} className="rounded border p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold">{family.nome}</p>
                    <p className="text-xs text-muted-foreground">{family.ativo ? 'Família ativa' : 'Família inativa'}</p>
                  </div>
                  {context.can_manage_family_relations && (
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => openFamilyDialog(family.id)} disabled={processing}>
                      <Plus className="mr-1 h-3.5 w-3.5" /> Adicionar membro
                    </Button>
                  )}
                </div>
                <div className="mt-3 grid gap-2 lg:grid-cols-2">
                  {family.members.map((familyMember) => (
                    <div key={familyMember.id} className="rounded border bg-muted/20 p-3">
                      <p className="text-sm font-medium">{familyMember.nome_completo}</p>
                      <p className="text-xs text-muted-foreground">
                        {familyMember.numero_socio ? `Sócio #${familyMember.numero_socio}` : 'Sem número de sócio'} · {familyMember.estado || 'Sem estado'}
                      </p>
                      <p className="text-xs text-muted-foreground">Relação: {FAMILY_ROLE_LABELS[normalizeFamilyRole(familyMember.papel_na_familia)]}</p>
                      {linkedDependentIds.has(familyMember.id) && (
                        <p className="text-xs text-muted-foreground">Responsabilidade direta: educando associado</p>
                      )}
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(familyMember.id)}>
                          <Pencil className="mr-1 h-3.5 w-3.5" /> Editar ficha
                        </Button>
                        {context.can_manage_family_relations && (
                          <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => openEditMembership(family, familyMember)} disabled={processing}>
                            Editar relação
                          </Button>
                        )}
                        {context.can_manage_family_relations && linkedDependentIds.has(familyMember.id) && (
                          <Button type="button" variant="outline" size="sm" className="h-7 text-xs text-destructive hover:text-destructive" onClick={() => removeDependent(familyMember)} disabled={processing}>
                            <Trash2 className="mr-1 h-3.5 w-3.5" /> Retirar responsabilidade
                          </Button>
                        )}
                        {context.can_manage_family_relations && familyMember.id !== memberId && (
                          <Button type="button" variant="outline" size="sm" className="h-7 text-xs text-destructive hover:text-destructive" onClick={() => removeFamilyMember(family, familyMember)} disabled={processing}>
                            <Trash2 className="mr-1 h-3.5 w-3.5" /> Remover do agregado
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}

            {ungroupedDependents.length > 0 && (
              <div className="rounded border p-3">
                <div>
                  <p className="text-sm font-semibold">Educandos associados</p>
                  <p className="text-xs text-muted-foreground">Relações diretas que ainda não constam de um agregado familiar.</p>
                </div>
                <div className="mt-3 grid gap-2 lg:grid-cols-2">
                  {ungroupedDependents.map((dependent) => (
                    <div key={dependent.id} className="rounded border bg-muted/20 p-3">
                      <p className="text-sm font-medium">{dependent.nome_completo}</p>
                      <p className="text-xs text-muted-foreground">
                        {dependent.numero_socio ? `Sócio #${dependent.numero_socio}` : 'Sem número de sócio'} · {dependent.estado || 'Sem estado'}
                      </p>
                      <p className="text-xs text-muted-foreground">Tipo: {formatMemberType(dependent.tipo_membro)}</p>
                      <p className="text-xs text-muted-foreground">Relação: Educando associado diretamente</p>
                      <div className="mt-2 flex flex-wrap gap-1.5">
                        <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(dependent.id)}>
                          <Pencil className="mr-1 h-3.5 w-3.5" /> Editar ficha
                        </Button>
                        {context.can_manage_family_relations && (
                          <Button type="button" variant="outline" size="sm" className="h-7 text-xs text-destructive hover:text-destructive" onClick={() => removeDependent(dependent)} disabled={processing}>
                            <Trash2 className="mr-1 h-3.5 w-3.5" /> Retirar responsabilidade
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </Card>

      {!context.can_manage_family_relations && (
        <p className="text-xs text-amber-700">Sem permissão para alterar relações familiares.</p>
      )}

      <Dialog open={relationMode !== null} onOpenChange={(open) => { if (!open) resetRelationDialog(); }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{relationMode === 'guardian' ? 'Adicionar encarregado de educação' : 'Adicionar educando'}</DialogTitle>
          </DialogHeader>
          <Input
            value={relationSearch}
            onChange={(event) => setRelationSearch(event.target.value)}
            placeholder="Pesquisar por nome ou número de sócio"
          />
          <ScrollArea className="h-64 rounded border">
            <div className="space-y-1 p-2">
              {relationCandidates.map((candidate) => (
                <button
                  type="button"
                  key={candidate.id}
                  onClick={() => setSelectedRelationMemberId(candidate.id)}
                  className={`w-full rounded border px-3 py-2 text-left text-sm transition-colors ${selectedRelationMemberId === candidate.id ? 'border-primary bg-primary/10' : 'hover:bg-muted'}`}
                >
                  <span className="font-medium">{candidate.nome_completo}</span>
                  <span className="block text-xs text-muted-foreground">{candidate.numero_socio ? `Sócio #${candidate.numero_socio}` : 'Sem número de sócio'}</span>
                </button>
              ))}
              {relationCandidates.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">Nenhum membro disponível.</p>}
            </div>
          </ScrollArea>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={resetRelationDialog}>Cancelar</Button>
            <Button type="button" onClick={addRelation} disabled={!selectedRelationMemberId || processing}>{processing ? 'A guardar...' : 'Adicionar'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={familyDialogOpen} onOpenChange={setFamilyDialogOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader><DialogTitle>Adicionar membro à família</DialogTitle></DialogHeader>
          <Select value={selectedFamilyMemberId} onValueChange={setSelectedFamilyMemberId}>
            <SelectTrigger><SelectValue placeholder="Selecionar membro" /></SelectTrigger>
            <SelectContent>
              {familyCandidates.map((candidate) => <SelectItem key={candidate.id} value={candidate.id}>{candidate.nome_completo}</SelectItem>)}
            </SelectContent>
          </Select>
          <Select value={selectedFamilyRole} onValueChange={(value) => setSelectedFamilyRole(value as FamilyRole)}>
            <SelectTrigger><SelectValue placeholder="Papel na família" /></SelectTrigger>
            <SelectContent>
              {Object.entries(FAMILY_ROLE_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
            </SelectContent>
          </Select>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setFamilyDialogOpen(false)}>Cancelar</Button>
            <Button type="button" onClick={addFamilyMember} disabled={!selectedFamilyMemberId || processing}>{processing ? 'A guardar...' : 'Adicionar'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={editingMembership !== null} onOpenChange={(open) => { if (!open) setEditingMembership(null); }}>
        <DialogContent className="max-w-md">
          <DialogHeader><DialogTitle>Editar relação familiar</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">{editingMembership?.member.nome_completo}</p>
          <Select value={editingRole} onValueChange={(value) => setEditingRole(value as FamilyRole)}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              {Object.entries(FAMILY_ROLE_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}
            </SelectContent>
          </Select>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setEditingMembership(null)}>Cancelar</Button>
            <Button type="button" onClick={updateFamilyMembership} disabled={processing}>{processing ? 'A guardar...' : 'Guardar relação'}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
