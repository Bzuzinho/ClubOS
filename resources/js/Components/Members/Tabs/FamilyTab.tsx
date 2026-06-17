import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';

interface RelatedMember {
  id: string;
  nome_completo: string;
  numero_socio?: string | null;
  estado?: string | null;
  tipo_membro?: string[];
  email?: string | null;
  contacto?: string | null;
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
  familyContext?: FamilyContext;
  onOpenMember: (memberId: string) => void;
}

const formatMemberType = (memberTypes: string[] | undefined): string => {
  if (!Array.isArray(memberTypes) || memberTypes.length === 0) {
    return 'Sem tipo definido';
  }

  return memberTypes.join(', ');
};

const formatFamilyRole = (role?: string | null): string => {
  if (!role) {
    return 'Sem papel definido';
  }

  return role.replaceAll('_', ' ');
};

export function FamilyTab({ familyContext, onOpenMember }: FamilyTabProps) {
  const context = familyContext ?? {
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

  const hasAnyFamilyRelation =
    context.guardians.length > 0 ||
    context.dependents.length > 0 ||
    context.families.length > 0;

  return (
    <div className="space-y-2">
      <Card className="p-3">
        <h3 className="text-sm font-semibold">Contexto familiar</h3>
        <p className="mt-1 text-xs text-muted-foreground">
          Visão administrativa do contexto familiar deste membro.
        </p>
        <div className="mt-2 grid gap-2 sm:grid-cols-4">
          <div className="rounded border p-2">
            <p className="text-[11px] text-muted-foreground">Encarregados associados</p>
            <p className="text-sm font-semibold">{context.summary?.guardians_count ?? context.guardians.length}</p>
          </div>
          <div className="rounded border p-2">
            <p className="text-[11px] text-muted-foreground">Educandos associados</p>
            <p className="text-sm font-semibold">{context.summary?.dependents_count ?? context.dependents.length}</p>
          </div>
          <div className="rounded border p-2">
            <p className="text-[11px] text-muted-foreground">Famílias agregadas</p>
            <p className="text-sm font-semibold">{context.summary?.families_count ?? context.families.length}</p>
          </div>
          <div className="rounded border p-2">
            <p className="text-[11px] text-muted-foreground">Membros de família</p>
            <p className="text-sm font-semibold">{context.summary?.family_members_count ?? 0}</p>
          </div>
        </div>
        {!context.can_manage_family_relations ? (
          <p className="mt-2 text-xs text-amber-700">
            Gestão avançada de relações não está disponível neste perfil.
          </p>
        ) : (
          <p className="mt-2 text-xs text-muted-foreground">
            Nesta sprint, a tab Família está em modo de consulta para evitar conflitos entre fontes de relação.
          </p>
        )}
      </Card>

      {!hasAnyFamilyRelation ? (
        <Card className="p-3">
          <p className="text-sm text-muted-foreground">Este membro ainda não tem relações familiares registadas.</p>
        </Card>
      ) : null}

      {context.is_guardian_profile ? (
        <Card className="p-3">
          <h4 className="text-sm font-semibold">Educandos associados</h4>
          {context.dependents.length === 0 ? (
            <p className="mt-2 text-sm text-muted-foreground">Este encarregado ainda não tem educandos associados.</p>
          ) : (
            <div className="mt-2 space-y-2">
              {context.dependents.map((dependent) => (
                <div key={dependent.id} className="rounded border p-2">
                  <p className="text-sm font-semibold">{dependent.nome_completo}</p>
                  <p className="text-xs text-muted-foreground">
                    {dependent.numero_socio ? `Socio #${dependent.numero_socio}` : 'Sem numero de socio'}
                    {' · '}
                    {dependent.estado || 'Sem estado'}
                  </p>
                  <p className="text-xs text-muted-foreground">Tipo de utilizador: {formatMemberType(dependent.tipo_membro)}</p>
                  <div className="mt-2">
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(dependent.id)}>
                      Abrir ficha
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      ) : null}

      {context.is_dependent_profile ? (
        <Card className="p-3">
          <h4 className="text-sm font-semibold">Encarregados associados</h4>
          {context.guardians.length === 0 ? (
            <p className="mt-2 text-sm text-muted-foreground">Este atleta ainda não tem encarregado associado.</p>
          ) : (
            <div className="mt-2 space-y-2">
              {context.guardians.map((guardian) => (
                <div key={guardian.id} className="rounded border p-2">
                  <p className="text-sm font-semibold">{guardian.nome_completo}</p>
                  <p className="text-xs text-muted-foreground">
                    {guardian.numero_socio ? `Socio #${guardian.numero_socio}` : 'Sem numero de socio'}
                    {' · '}
                    {guardian.estado || 'Sem estado'}
                  </p>
                  <p className="text-xs text-muted-foreground">Contacto: {guardian.contacto || 'Sem contacto'}</p>
                  <p className="text-xs text-muted-foreground">Email: {guardian.email || 'Sem email'}</p>
                  <p className="text-xs text-muted-foreground">Relação: encarregado de educacao</p>
                  <div className="mt-2">
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(guardian.id)}>
                      Abrir ficha
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      ) : null}

      <Card className="p-3">
        <h4 className="text-sm font-semibold">Membros da família</h4>
        {context.families.length === 0 ? (
          <p className="mt-2 text-sm text-muted-foreground">Sem família agregada para este membro.</p>
        ) : (
          <div className="mt-2 space-y-3">
            {context.families.map((family) => (
              <div key={family.id} className="rounded border p-2">
                <p className="text-sm font-semibold">{family.nome}</p>
                <p className="text-xs text-muted-foreground">
                  {family.ativo ? 'Família ativa' : 'Família inativa'}
                  {' · '}
                  Papel do membro: {formatFamilyRole(family.papel_do_membro)}
                </p>
                <div className="mt-2 space-y-2">
                  {family.members.map((familyMember) => (
                    <div key={familyMember.id} className="rounded border bg-muted/20 p-2">
                      <p className="text-sm font-medium">{familyMember.nome_completo}</p>
                      <p className="text-xs text-muted-foreground">
                        {familyMember.numero_socio ? `Socio #${familyMember.numero_socio}` : 'Sem numero de socio'}
                        {' · '}
                        {familyMember.estado || 'Sem estado'}
                      </p>
                      <p className="text-xs text-muted-foreground">Relação: {formatFamilyRole(familyMember.papel_na_familia)}</p>
                      <div className="mt-2">
                        <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenMember(familyMember.id)}>
                          Abrir ficha
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
