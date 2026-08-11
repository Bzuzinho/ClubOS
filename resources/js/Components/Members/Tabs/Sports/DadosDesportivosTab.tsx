import { useCallback, useEffect, useMemo, useState } from 'react';
import { User } from '@/types';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { AlertCircle, CheckCircle2, Loader2, RefreshCw, ShieldAlert } from 'lucide-react';

interface DadosDesportivosTabProps {
  user: User;
  onChange: (field: keyof User, value: any) => void;
  isAdmin: boolean;
}

type Placement = {
  persisted: boolean;
  source: 'rule' | 'override' | null;
  calculated_age_group_id: string | null;
  calculated_age_group_name: string | null;
  official_age_group_id: string | null;
  official_age_group_name: string | null;
  override_id: string | null;
  reference_date: string | null;
};

type SeasonRow = {
  id: string;
  name: string;
  status: string | null;
  starts_at: string | null;
  ends_at: string | null;
  is_current: boolean;
  placement: Placement;
};

type ModalityRow = {
  id: string;
  code: string;
  name: string;
  available: boolean;
  active: boolean;
  active_participation_id: string | null;
  starts_at: string | null;
  history: Array<{
    id: string;
    active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    source: string;
  }>;
  seasons: SeasonRow[];
  groups: Array<{
    id: string;
    group_name: string | null;
    season_name: string | null;
    program_name: string | null;
    is_primary: boolean;
    starts_at: string | null;
    ends_at: string | null;
  }>;
  federation_affiliations: Array<{
    id: string;
    federation_name: string | null;
    membership_number: string | null;
    license_number: string | null;
    active: boolean;
  }>;
};

type SportsContext = {
  version: number;
  canonical: boolean;
  member: {
    id: string;
    is_athlete: boolean;
    member_state: string | null;
    birth_date: string | null;
    sex: string | null;
  };
  activity_active: boolean;
  modalities: ModalityRow[];
  age_groups: Array<{ id: string; name: string; code: string | null }>;
  federations: Array<{ id: string; name: string; code: string }>;
  limitation_types: Array<{
    id: string;
    name: string;
    default_instruction: string | null;
    allows_training: boolean;
    allows_competition: boolean;
    requires_end_date: boolean;
  }>;
  limitations: Array<{
    id: string;
    type_id: string;
    type_name: string | null;
    modality_id: string | null;
    modality_name: string | null;
    starts_at: string | null;
    ends_at: string | null;
    operational_instruction: string | null;
    allows_training: boolean;
    allows_competition: boolean;
    active: boolean;
  }>;
  legacy_compatibility: {
    numero_pmb: string | null;
    num_federacao_unscoped: string | null;
    has_unscoped_federation_data: boolean;
    medical_json_preserved_not_operational: boolean;
  };
};

const today = () => new Date().toISOString().slice(0, 10);

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function DadosDesportivosTab({ user, isAdmin }: DadosDesportivosTabProps) {
  const [context, setContext] = useState<SportsContext | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [activityDraft, setActivityDraft] = useState<Record<string, boolean>>({});
  const [pmb, setPmb] = useState('');
  const [overrideDraft, setOverrideDraft] = useState<Record<string, { ageGroupId: string; reason: string }>>({});
  const [limitationTypeId, setLimitationTypeId] = useState('');
  const [limitationModalityId, setLimitationModalityId] = useState('__all__');
  const [limitationEndsAt, setLimitationEndsAt] = useState('');
  const [limitationInstruction, setLimitationInstruction] = useState('');

  const endpoint = useMemo(() => route('desportivo.membros.perfil.show', { member: user.id }), [user.id]);
  const updateEndpoint = useMemo(() => route('desportivo.membros.perfil.update', { member: user.id }), [user.id]);

  const applyContext = useCallback((next: SportsContext) => {
    setContext(next);
    setActivityDraft(Object.fromEntries(next.modalities.map((modality) => [modality.id, modality.active])));
    setPmb(next.legacy_compatibility.numero_pmb ?? '');
  }, []);

  const loadProfile = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(endpoint, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error(`Não foi possível carregar o perfil desportivo (${response.status}).`);
      }
      const payload = await response.json();
      applyContext(payload.data as SportsContext);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível carregar o perfil desportivo.');
    } finally {
      setLoading(false);
    }
  }, [applyContext, endpoint]);

  useEffect(() => {
    void loadProfile();
  }, [loadProfile]);

  const mutate = async (payload: Record<string, unknown>, message: string) => {
    setSaving(true);
    setError(null);
    setSuccess(null);

    try {
      const response = await fetch(updateEndpoint, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        const validation = body?.errors ? Object.values(body.errors).flat().join(' ') : null;
        throw new Error(validation || body?.message || `Erro ao guardar (${response.status}).`);
      }
      applyContext(body.data as SportsContext);
      setSuccess(message);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível guardar as alterações desportivas.');
    } finally {
      setSaving(false);
    }
  };

  const saveParticipations = async () => {
    if (!context) return;

    await mutate({
      participations: context.modalities.map((modality) => ({
        sports_modality_id: modality.id,
        active: Boolean(activityDraft[modality.id]),
        starts_at: activityDraft[modality.id] ? (modality.starts_at ?? today()) : null,
        ends_at: !activityDraft[modality.id] && modality.active ? today() : null,
        reason: activityDraft[modality.id] === modality.active
          ? 'Confirmação de estado pela ficha de Membros.'
          : activityDraft[modality.id]
            ? 'Ativação pela ficha de Membros.'
            : 'Desativação pela ficha de Membros.',
      })),
      legacy_identifiers: {
        numero_pmb: pmb || null,
      },
    }, 'Participações desportivas atualizadas.');
  };

  const saveOverride = async (modality: ModalityRow, season: SeasonRow) => {
    const draft = overrideDraft[season.id] ?? { ageGroupId: '', reason: '' };
    if (!draft.ageGroupId || draft.reason.trim().length < 3) {
      setError('Seleciona o escalão e indica o motivo do override.');
      return;
    }

    await mutate({
      age_group_overrides: [{
        season_id: season.id,
        sports_modality_id: modality.id,
        age_group_id: draft.ageGroupId,
        reason: draft.reason.trim(),
        effective_at: today(),
      }],
    }, 'Override de escalão registado com histórico.');
  };

  const endOverride = async (modality: ModalityRow, season: SeasonRow) => {
    await mutate({
      age_group_overrides: [{
        season_id: season.id,
        sports_modality_id: modality.id,
        end_override: true,
      }],
    }, 'Override terminado; voltou a aplicar-se a regra da época.');
  };

  const addLimitation = async () => {
    if (!limitationTypeId) {
      setError('Seleciona o tipo de limitação operacional.');
      return;
    }

    await mutate({
      limitations: [{
        action: 'create',
        sports_limitation_type_id: limitationTypeId,
        sports_modality_id: limitationModalityId === '__all__' ? null : limitationModalityId,
        starts_at: today(),
        ends_at: limitationEndsAt || null,
        operational_instruction: limitationInstruction || null,
      }],
    }, 'Limitação operacional registada.');

    setLimitationTypeId('');
    setLimitationModalityId('__all__');
    setLimitationEndsAt('');
    setLimitationInstruction('');
  };

  const endLimitation = async (id: string) => {
    await mutate({
      limitations: [{ action: 'end', id, ends_at: today() }],
    }, 'Limitação operacional terminada.');
  };

  if (loading) {
    return (
      <div className="flex min-h-40 items-center justify-center text-sm text-muted-foreground">
        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
        A carregar perfil desportivo canónico…
      </div>
    );
  }

  if (!context) {
    return (
      <Card className="p-4 text-sm text-red-700">
        {error ?? 'Não foi possível carregar o perfil desportivo.'}
      </Card>
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 rounded-lg border bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold">Perfil técnico do atleta</h3>
            <Badge variant={context.activity_active ? 'default' : 'outline'}>
              {context.activity_active ? 'Atividade ativa' : 'Sem atividade ativa'}
            </Badge>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Modalidades, épocas, escalões e grupos são propriedade do Desportivo e são guardados fora da ficha pessoal.
          </p>
        </div>
        <div className="flex gap-2">
          <Button type="button" variant="outline" size="sm" onClick={() => void loadProfile()} disabled={saving}>
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Atualizar
          </Button>
          {isAdmin && (
            <Button type="button" size="sm" onClick={() => void saveParticipations()} disabled={saving}>
              {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}
              Guardar atividade
            </Button>
          )}
        </div>
      </div>

      {error && (
        <div className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-800">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" /> {error}
        </div>
      )}
      {success && (
        <div className="flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs text-emerald-800">
          <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> {success}
        </div>
      )}

      {!context.member.is_athlete && (
        <Card className="p-3 text-xs text-amber-800">
          Este membro não tem o tipo Atleta. O histórico desportivo é preservado, mas não pode ser iniciada uma nova participação enquanto o tipo não for reposto em Membros.
        </Card>
      )}

      <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
        {context.modalities.map((modality) => {
          const currentSeasons = modality.seasons.filter((season) => season.is_current || season.status === 'active');
          const seasonsToShow = currentSeasons.length > 0 ? currentSeasons : modality.seasons.slice(0, 2);

          return (
            <Card key={modality.id} className="p-3">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <h4 className="text-sm font-semibold">{modality.name}</h4>
                    {!modality.available && <Badge variant="outline">Arquivada</Badge>}
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    {modality.starts_at ? `Participação atual desde ${modality.starts_at}` : 'Sem período ativo'}
                  </p>
                </div>
                <Switch
                  checked={Boolean(activityDraft[modality.id])}
                  onCheckedChange={(checked) => setActivityDraft((current) => ({ ...current, [modality.id]: checked }))}
                  disabled={!isAdmin || !modality.available || !context.member.is_athlete}
                />
              </div>

              <div className="mt-3 space-y-2">
                {seasonsToShow.length === 0 ? (
                  <div className="rounded-md border border-dashed p-2 text-xs text-muted-foreground">
                    Ainda não existe uma época configurada para esta modalidade.
                  </div>
                ) : seasonsToShow.map((season) => {
                  const placement = season.placement;
                  const draft = overrideDraft[season.id] ?? { ageGroupId: '', reason: '' };

                  return (
                    <div key={season.id} className="rounded-md border p-2">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                          <p className="text-xs font-medium">{season.name}</p>
                          <p className="text-[10px] text-muted-foreground">
                            {season.starts_at ?? '—'} → {season.ends_at ?? '—'}
                            {placement.reference_date ? ` · referência ${placement.reference_date}` : ''}
                          </p>
                        </div>
                        <Badge variant={placement.source === 'override' ? 'secondary' : 'outline'}>
                          {placement.source === 'override' ? 'Override' : placement.source === 'rule' ? 'Regra da época' : 'Por classificar'}
                        </Badge>
                      </div>

                      <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                        <div className="rounded bg-slate-50 p-2">
                          <span className="block text-[10px] text-muted-foreground">Calculado</span>
                          <strong>{placement.calculated_age_group_name ?? '—'}</strong>
                        </div>
                        <div className="rounded bg-slate-50 p-2">
                          <span className="block text-[10px] text-muted-foreground">Oficial</span>
                          <strong>{placement.official_age_group_name ?? '—'}</strong>
                        </div>
                      </div>

                      {isAdmin && modality.active && (
                        <div className="mt-2 space-y-2 border-t pt-2">
                          {placement.source === 'override' ? (
                            <Button type="button" size="sm" variant="outline" className="h-7 text-xs" onClick={() => void endOverride(modality, season)} disabled={saving}>
                              Voltar à regra automática
                            </Button>
                          ) : (
                            <>
                              <div className="grid gap-2 sm:grid-cols-2">
                                <Select
                                  value={draft.ageGroupId}
                                  onValueChange={(value) => setOverrideDraft((current) => ({
                                    ...current,
                                    [season.id]: { ...draft, ageGroupId: value },
                                  }))}
                                >
                                  <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Escalão para override" /></SelectTrigger>
                                  <SelectContent>
                                    {context.age_groups.map((group) => (
                                      <SelectItem key={group.id} value={group.id}>{group.name}</SelectItem>
                                    ))}
                                  </SelectContent>
                                </Select>
                                <Input
                                  className="h-8 text-xs"
                                  value={draft.reason}
                                  onChange={(event) => setOverrideDraft((current) => ({
                                    ...current,
                                    [season.id]: { ...draft, reason: event.target.value },
                                  }))}
                                  placeholder="Motivo obrigatório"
                                />
                              </div>
                              <Button type="button" size="sm" className="h-7 text-xs" onClick={() => void saveOverride(modality, season)} disabled={saving}>
                                Aplicar override
                              </Button>
                            </>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>

              <div className="mt-3 border-t pt-2">
                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Grupos</p>
                {modality.groups.length === 0 ? (
                  <p className="text-xs text-muted-foreground">Sem memberships de grupo.</p>
                ) : (
                  <div className="space-y-1">
                    {modality.groups.slice(0, 5).map((group) => (
                      <div key={group.id} className="flex items-center justify-between gap-2 text-xs">
                        <span>{group.group_name ?? 'Grupo'} {group.season_name ? `· ${group.season_name}` : ''}</span>
                        <Badge variant="outline">{group.is_primary ? 'Principal' : 'Complementar'}</Badge>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </Card>
          );
        })}
      </div>

      <Card className="p-3">
        <div className="grid gap-3 md:grid-cols-2">
          <div>
            <Label className="text-xs">Número PMB (compatibilidade)</Label>
            <Input className="mt-1 h-8 text-xs" value={pmb} onChange={(event) => setPmb(event.target.value)} disabled={!isAdmin} />
          </div>
          <div>
            <Label className="text-xs">Número de federação legacy sem modalidade</Label>
            <Input className="mt-1 h-8 text-xs" value={context.legacy_compatibility.num_federacao_unscoped ?? ''} disabled />
            {context.legacy_compatibility.has_unscoped_federation_data && (
              <p className="mt-1 text-[10px] text-amber-700">
                Preservado para reconciliação. Não é convertido automaticamente numa afiliação federativa sem federação/modalidade inequívocas.
              </p>
            )}
          </div>
        </div>
      </Card>

      <Card className="p-3">
        <div className="flex items-center gap-2">
          <ShieldAlert className="h-4 w-4" />
          <h4 className="text-sm font-semibold">Limitações operacionais</h4>
        </div>
        <p className="mt-1 text-[11px] text-muted-foreground">
          Aqui ficam apenas instruções necessárias à prática desportiva. Diagnósticos e documentos clínicos pertencem a Membros/Documentos.
        </p>

        <div className="mt-2 space-y-1">
          {context.limitations.filter((row) => row.active).length === 0 ? (
            <p className="text-xs text-muted-foreground">Sem limitações operacionais ativas.</p>
          ) : context.limitations.filter((row) => row.active).map((row) => (
            <div key={row.id} className="flex flex-col gap-2 rounded-md border p-2 text-xs sm:flex-row sm:items-center sm:justify-between">
              <div>
                <strong>{row.type_name ?? 'Limitação'}</strong>
                {row.modality_name ? ` · ${row.modality_name}` : ' · todas as modalidades'}
                <p className="text-muted-foreground">{row.operational_instruction || 'Sem instrução adicional.'}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={row.allows_training ? 'outline' : 'destructive'}>{row.allows_training ? 'Treino permitido' : 'Sem treino'}</Badge>
                {isAdmin && (
                  <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => void endLimitation(row.id)} disabled={saving}>
                    Terminar
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>

        {isAdmin && context.limitation_types.length > 0 && (
          <div className="mt-3 grid gap-2 border-t pt-3 md:grid-cols-4">
            <Select value={limitationTypeId} onValueChange={setLimitationTypeId}>
              <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Tipo de limitação" /></SelectTrigger>
              <SelectContent>
                {context.limitation_types.map((type) => <SelectItem key={type.id} value={type.id}>{type.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <Select value={limitationModalityId} onValueChange={setLimitationModalityId}>
              <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">Todas as modalidades</SelectItem>
                {context.modalities.map((modality) => <SelectItem key={modality.id} value={modality.id}>{modality.name}</SelectItem>)}
              </SelectContent>
            </Select>
            <Input type="date" className="h-8 text-xs" value={limitationEndsAt} onChange={(event) => setLimitationEndsAt(event.target.value)} />
            <Input className="h-8 text-xs" value={limitationInstruction} onChange={(event) => setLimitationInstruction(event.target.value)} placeholder="Instrução operacional" />
            <div className="md:col-span-4">
              <Button type="button" size="sm" className="h-7 text-xs" onClick={() => void addLimitation()} disabled={saving}>
                Adicionar limitação operacional
              </Button>
            </div>
          </div>
        )}
      </Card>

      {context.legacy_compatibility.medical_json_preserved_not_operational && (
        <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
          Existe informação clínica legacy preservada. A F3 não a converte automaticamente em limitação operacional para evitar expor ou reinterpretar diagnóstico clínico.
        </div>
      )}
    </div>
  );
}
