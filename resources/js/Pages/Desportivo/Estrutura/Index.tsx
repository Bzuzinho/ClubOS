import { FormEvent, useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Badge } from '@/Components/ui/badge';
import { ArrowLeft, Buildings, Plus, SwimmingPool, UsersThree, CalendarBlank, UserCircleGear } from '@phosphor-icons/react';

interface Modality { id:string; code:string; name:string; description?:string|null; active:boolean; programs?:Program[] }
interface Program { id:string; sports_modality_id:string; code:string; name:string; active:boolean; modality?:Modality }
interface SeasonProgram { id:string; active:boolean; program?:Program }
interface Season { id:string; nome:string; data_inicio:string; data_fim:string; status?:string; sports_modality_id?:string|null; modality?:Modality; programs?:SeasonProgram[] }
interface AgeGroup { id:string; code?:string; nome:string; idade_minima?:number|null; idade_maxima?:number|null; ativo:boolean }
interface Group { id:string; code:string; name:string; active:boolean; sports_modality_id?:string|null; modality_definition?:Modality; season_configurations?:unknown[] }
interface GroupSeason { id:string; active:boolean; group?:Group; season?:Season; program?:Program|null }
interface CoachRole { id:string; code:string; name:string; active:boolean }
interface Lane { id:string; lane_number:number; name?:string|null; capacity?:number|null; active:boolean }
interface Pool { id:string; code:string; name:string; length_m?:string|null; active:boolean; lanes?:Lane[] }
interface Location { id:string; code:string; name:string; venue_type?:string; address?:string|null; active:boolean; pools?:Pool[] }
interface Rule { id:string; gender?:string|null; birth_year_min?:number|null; birth_year_max?:number|null; age_min?:number|null; age_max?:number|null; reference_date?:string|null; priority?:number; active:boolean; season?:Season; age_group?:AgeGroup }

interface Props {
  modalities:Modality[];
  programs:Program[];
  seasons:Season[];
  ageGroups:AgeGroup[];
  ageGroupRules:Rule[];
  groups:Group[];
  groupSeasons:GroupSeason[];
  coachRoles:CoachRole[];
  locations:Location[];
}

const selectClass = 'h-9 w-full rounded-md border bg-background px-3 text-sm';
function StateBadge({ active }: { active:boolean }) { return <Badge variant={active ? 'default' : 'secondary'}>{active ? 'Ativo' : 'Inativo'}</Badge>; }

export default function SportsStructureIndex({ modalities, programs, seasons, ageGroups, ageGroupRules, groups, groupSeasons, coachRoles, locations }:Props) {
  const [tab,setTab] = useState('modalidades');
  const pools = useMemo(() => locations.flatMap(location => (location.pools ?? []).map(pool => ({ ...pool, locationName: location.name }))), [locations]);
  const counts = useMemo(() => ({ modalities:modalities.filter(x=>x.active).length, programs:programs.filter(x=>x.active).length, groups:groups.filter(x=>x.active).length, locations:locations.filter(x=>x.active).length }), [modalities,programs,groups,locations]);

  const modalityForm = useForm({ code:'', name:'', description:'', active:true });
  const programForm = useForm({ sports_modality_id:modalities[0]?.id ?? '', code:'', name:'', description:'', active:true });
  const seasonProgramForm = useForm({ season_id:seasons[0]?.id ?? '', sports_program_id:'', active:true, notes:'' });
  const ageRuleForm = useForm({ season_id:seasons[0]?.id ?? '', sports_modality_id:seasons[0]?.sports_modality_id ?? modalities[0]?.id ?? '', age_group_id:ageGroups[0]?.id ?? '', gender:'', birth_year_min:'', birth_year_max:'', age_min:'', age_max:'', reference_date:'', priority:'0', active:true });
  const groupSeasonForm = useForm({ training_group_id:groups[0]?.id ?? '', season_id:seasons[0]?.id ?? '', sports_program_id:'', active:true, notes:'' });
  const roleForm = useForm({ code:'', name:'', description:'' });
  const poolForm = useForm({ sports_venue_id:locations[0]?.id ?? '', code:'', name:'', length_m:'', capacity:'', active:true });
  const laneForm = useForm({ sports_pool_id:pools[0]?.id ?? '', lane_number:'', name:'', capacity:'', active:true });

  const submitModality = (e:FormEvent) => { e.preventDefault(); modalityForm.post(route('desportivo.estrutura.modalidades.store'), { preserveScroll:true, onSuccess:()=>modalityForm.reset('code','name','description') }); };
  const submitProgram = (e:FormEvent) => { e.preventDefault(); programForm.post(route('desportivo.estrutura.programas.store'), { preserveScroll:true, onSuccess:()=>programForm.reset('code','name','description') }); };
  const submitSeasonProgram = (e:FormEvent) => { e.preventDefault(); seasonProgramForm.post(route('desportivo.estrutura.epocas.programas.store'), { preserveScroll:true, onSuccess:()=>seasonProgramForm.reset('sports_program_id','notes') }); };
  const submitAgeRule = (e:FormEvent) => { e.preventDefault(); ageRuleForm.post(route('desportivo.estrutura.escaloes.regras.store'), { preserveScroll:true, onSuccess:()=>ageRuleForm.reset('birth_year_min','birth_year_max','age_min','age_max','reference_date') }); };
  const submitGroupSeason = (e:FormEvent) => { e.preventDefault(); groupSeasonForm.post(route('desportivo.estrutura.grupos.epocas.store'), { preserveScroll:true, onSuccess:()=>groupSeasonForm.reset('sports_program_id','notes') }); };
  const submitRole = (e:FormEvent) => { e.preventDefault(); roleForm.post(route('desportivo.estrutura.treinadores.funcoes.store'), { preserveScroll:true, onSuccess:()=>roleForm.reset() }); };
  const submitPool = (e:FormEvent) => { e.preventDefault(); poolForm.post(route('desportivo.estrutura.piscinas.store'), { preserveScroll:true, onSuccess:()=>poolForm.reset('code','name','length_m','capacity') }); };
  const submitLane = (e:FormEvent) => { e.preventDefault(); if (!laneForm.data.sports_pool_id) return; laneForm.post(route('desportivo.estrutura.pistas.store', laneForm.data.sports_pool_id), { preserveScroll:true, onSuccess:()=>laneForm.reset('lane_number','name','capacity') }); };

  const selectSeasonForRule = (seasonId:string) => {
    const season = seasons.find(item => item.id === seasonId);
    ageRuleForm.setData(data => ({ ...data, season_id:seasonId, sports_modality_id:season?.sports_modality_id ?? season?.modality?.id ?? '' }));
  };

  const closeSeason = (season:Season) => router.post(route('desportivo.estrutura.epocas.close', season.id), {}, { preserveScroll:true });
  const reopenSeason = (season:Season) => {
    const reason = window.prompt('Motivo obrigatório para reabrir a época:');
    if (!reason?.trim()) return;
    router.post(route('desportivo.estrutura.epocas.reopen', season.id), { reason }, { preserveScroll:true });
  };

  return <AuthenticatedLayout fullWidth header={<div className="flex w-full items-center justify-between gap-3"><div><h1 className="text-lg font-semibold">Estrutura Desportiva</h1><p className="mt-0.5 text-xs text-muted-foreground">Fundação organizacional canónica do Desportivo</p></div><Button variant="outline" size="sm" onClick={()=>router.get(route('desportivo.index'))}><ArrowLeft size={15} className="mr-1.5"/>Desportivo</Button></div>}>
    <Head title="Estrutura Desportiva" />
    <div className="space-y-4 p-1 sm:p-2">
      <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
        {[['Modalidades',counts.modalities],['Programas',counts.programs],['Grupos',counts.groups],['Locais',counts.locations]].map(([label,value])=><Card key={String(label)}><CardContent className="p-3"><div className="text-xs text-muted-foreground">{label}</div><div className="mt-1 text-xl font-semibold">{value}</div></CardContent></Card>)}
      </div>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="grid h-auto w-full grid-cols-2 sm:grid-cols-4">
          <TabsTrigger value="modalidades" className="gap-1.5 text-xs"><Buildings size={14}/>Modalidades</TabsTrigger>
          <TabsTrigger value="epocas" className="gap-1.5 text-xs"><CalendarBlank size={14}/>Épocas e escalões</TabsTrigger>
          <TabsTrigger value="grupos" className="gap-1.5 text-xs"><UsersThree size={14}/>Grupos e técnicos</TabsTrigger>
          <TabsTrigger value="locais" className="gap-1.5 text-xs"><SwimmingPool size={14}/>Locais e piscinas</TabsTrigger>
        </TabsList>

        <TabsContent value="modalidades" className="space-y-3">
          <div className="grid gap-3 xl:grid-cols-[1.25fr_.75fr]">
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Modalidades e programas permanentes</CardTitle></CardHeader><CardContent className="space-y-2">
              {modalities.map(m=><div key={m.id} className="rounded-lg border bg-background p-3"><div className="flex items-center justify-between"><div><div className="font-medium">{m.name}</div><div className="text-xs text-muted-foreground">{m.code}</div></div><StateBadge active={m.active}/></div><div className="mt-2 flex flex-wrap gap-1">{programs.filter(p=>p.sports_modality_id===m.id).map(p=><Badge key={p.id} variant="outline">{p.name}</Badge>)}{programs.filter(p=>p.sports_modality_id===m.id).length===0 && <span className="text-xs text-muted-foreground">Sem programas configurados</span>}</div></div>)}
            </CardContent></Card>
            <div className="space-y-3">
              <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Nova modalidade</CardTitle></CardHeader><CardContent><form onSubmit={submitModality} className="space-y-2"><div><Label>Nome</Label><Input value={modalityForm.data.name} onChange={e=>modalityForm.setData('name',e.target.value)} /></div><div><Label>Código técnico</Label><Input value={modalityForm.data.code} onChange={e=>modalityForm.setData('code',e.target.value)} placeholder="swimming" /></div><Button size="sm" disabled={modalityForm.processing}><Plus size={14} className="mr-1"/>Criar modalidade</Button></form></CardContent></Card>
              <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Novo programa</CardTitle></CardHeader><CardContent><form onSubmit={submitProgram} className="space-y-2"><div><Label>Modalidade</Label><select className={selectClass} value={programForm.data.sports_modality_id} onChange={e=>programForm.setData('sports_modality_id',e.target.value)}>{modalities.map(m=><option key={m.id} value={m.id}>{m.name}</option>)}</select></div><div><Label>Nome</Label><Input value={programForm.data.name} onChange={e=>programForm.setData('name',e.target.value)} /></div><div><Label>Código</Label><Input value={programForm.data.code} onChange={e=>programForm.setData('code',e.target.value)} /></div><Button size="sm" disabled={programForm.processing}><Plus size={14} className="mr-1"/>Criar programa</Button></form></CardContent></Card>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="epocas" className="space-y-3">
          <div className="grid gap-3 xl:grid-cols-[1.3fr_.7fr]">
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Épocas</CardTitle></CardHeader><CardContent className="space-y-2">{seasons.map(s=><div key={s.id} className="rounded-lg border p-3"><div className="flex flex-wrap items-start justify-between gap-2"><div><div className="font-medium">{s.nome}</div><div className="mt-1 text-xs text-muted-foreground">{s.modality?.name ?? 'Modalidade'} · {s.data_inicio} → {s.data_fim}</div></div><div className="flex items-center gap-2"><Badge variant="outline">{s.status ?? 'planned'}</Badge>{s.status==='closed' ? <Button size="sm" variant="outline" onClick={()=>reopenSeason(s)}>Reabrir</Button> : <Button size="sm" variant="outline" onClick={()=>closeSeason(s)}>Encerrar</Button>}</div></div><div className="mt-2 flex flex-wrap gap-1">{s.programs?.filter(x=>x.active).map(x=><Badge key={x.id} variant="secondary">{x.program?.name}</Badge>)}{!s.programs?.some(x=>x.active) && <span className="text-xs text-muted-foreground">Sem programas ativos</span>}</div></div>)}</CardContent></Card>
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Ativar programa numa época</CardTitle></CardHeader><CardContent><form onSubmit={submitSeasonProgram} className="space-y-2"><div><Label>Época</Label><select className={selectClass} value={seasonProgramForm.data.season_id} onChange={e=>seasonProgramForm.setData('season_id',e.target.value)}>{seasons.map(s=><option key={s.id} value={s.id}>{s.nome}</option>)}</select></div><div><Label>Programa</Label><select className={selectClass} value={seasonProgramForm.data.sports_program_id} onChange={e=>seasonProgramForm.setData('sports_program_id',e.target.value)}><option value="">Selecionar…</option>{programs.filter(p=>p.active).map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div><div><Label>Notas</Label><Input value={seasonProgramForm.data.notes} onChange={e=>seasonProgramForm.setData('notes',e.target.value)} /></div><Button size="sm" disabled={seasonProgramForm.processing || !seasonProgramForm.data.sports_program_id}>Associar</Button></form></CardContent></Card>
          </div>

          <div className="grid gap-3 xl:grid-cols-[1.25fr_.75fr]">
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Escalões e regras sazonais</CardTitle></CardHeader><CardContent className="space-y-2">{ageGroups.map(a=><div key={a.id} className="rounded-lg border p-3"><div className="flex justify-between"><span className="font-medium">{a.nome}</span><StateBadge active={a.ativo}/></div><div className="text-xs text-muted-foreground">{a.code ?? '—'} · idade base {a.idade_minima ?? '—'}–{a.idade_maxima ?? '—'}</div><div className="mt-2 space-y-1">{ageGroupRules.filter(r=>r.age_group?.id===a.id && r.active).map(r=><div key={r.id} className="text-xs text-muted-foreground">{r.season?.nome ?? 'Época'} · anos {r.birth_year_min ?? '—'}–{r.birth_year_max ?? '—'} · idades {r.age_min ?? '—'}–{r.age_max ?? '—'}</div>)}</div></div>)}</CardContent></Card>
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Nova regra de escalão</CardTitle></CardHeader><CardContent><form onSubmit={submitAgeRule} className="space-y-2"><div><Label>Época</Label><select className={selectClass} value={ageRuleForm.data.season_id} onChange={e=>selectSeasonForRule(e.target.value)}>{seasons.map(s=><option key={s.id} value={s.id}>{s.nome}</option>)}</select></div><div><Label>Escalão</Label><select className={selectClass} value={ageRuleForm.data.age_group_id} onChange={e=>ageRuleForm.setData('age_group_id',e.target.value)}>{ageGroups.map(a=><option key={a.id} value={a.id}>{a.nome}</option>)}</select></div><div className="grid grid-cols-2 gap-2"><div><Label>Ano mín.</Label><Input type="number" value={ageRuleForm.data.birth_year_min} onChange={e=>ageRuleForm.setData('birth_year_min',e.target.value)} /></div><div><Label>Ano máx.</Label><Input type="number" value={ageRuleForm.data.birth_year_max} onChange={e=>ageRuleForm.setData('birth_year_max',e.target.value)} /></div><div><Label>Idade mín.</Label><Input type="number" value={ageRuleForm.data.age_min} onChange={e=>ageRuleForm.setData('age_min',e.target.value)} /></div><div><Label>Idade máx.</Label><Input type="number" value={ageRuleForm.data.age_max} onChange={e=>ageRuleForm.setData('age_max',e.target.value)} /></div></div><div><Label>Data de referência</Label><Input type="date" value={ageRuleForm.data.reference_date} onChange={e=>ageRuleForm.setData('reference_date',e.target.value)} /></div><Button size="sm" disabled={ageRuleForm.processing}>Criar regra</Button></form></CardContent></Card>
          </div>
        </TabsContent>

        <TabsContent value="grupos" className="space-y-3">
          <div className="grid gap-3 xl:grid-cols-[1.25fr_.75fr]">
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Grupos/equipas por época</CardTitle></CardHeader><CardContent className="space-y-2">{groups.map(g=><div key={g.id} className="rounded-lg border p-3"><div className="flex items-center justify-between"><div><div className="font-medium">{g.name}</div><div className="text-xs text-muted-foreground">{g.code} · {g.modality_definition?.name ?? 'Modalidade'}</div></div><StateBadge active={g.active}/></div><div className="mt-2 flex flex-wrap gap-1">{groupSeasons.filter(gs=>gs.group?.id===g.id && gs.active).map(gs=><Badge key={gs.id} variant="outline">{gs.season?.nome}{gs.program?.name ? ` · ${gs.program.name}` : ''}</Badge>)}</div></div>)}</CardContent></Card>
            <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Configurar grupo na época</CardTitle></CardHeader><CardContent><form onSubmit={submitGroupSeason} className="space-y-2"><div><Label>Grupo</Label><select className={selectClass} value={groupSeasonForm.data.training_group_id} onChange={e=>groupSeasonForm.setData('training_group_id',e.target.value)}>{groups.map(g=><option key={g.id} value={g.id}>{g.name}</option>)}</select></div><div><Label>Época</Label><select className={selectClass} value={groupSeasonForm.data.season_id} onChange={e=>groupSeasonForm.setData('season_id',e.target.value)}>{seasons.map(s=><option key={s.id} value={s.id}>{s.nome}</option>)}</select></div><div><Label>Programa (opcional)</Label><select className={selectClass} value={groupSeasonForm.data.sports_program_id} onChange={e=>groupSeasonForm.setData('sports_program_id',e.target.value)}><option value="">Sem programa específico</option>{programs.filter(p=>p.active).map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div><Button size="sm" disabled={groupSeasonForm.processing}>Guardar contexto</Button></form></CardContent></Card>
          </div>
          <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Funções técnicas</CardTitle></CardHeader><CardContent className="grid gap-3 lg:grid-cols-[1fr_360px]"><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{coachRoles.map(r=><div key={r.id} className="flex items-center justify-between rounded border px-2 py-1.5 text-sm"><span>{r.name}</span><StateBadge active={r.active}/></div>)}</div><form onSubmit={submitRole} className="space-y-2"><div><Label>Nova função</Label><Input value={roleForm.data.name} onChange={e=>roleForm.setData('name',e.target.value)} placeholder="Treinador Principal" /></div><div><Label>Código</Label><Input value={roleForm.data.code} onChange={e=>roleForm.setData('code',e.target.value)} /></div><Button size="sm" disabled={roleForm.processing}><UserCircleGear size={14} className="mr-1"/>Adicionar</Button></form></CardContent></Card>
        </TabsContent>

        <TabsContent value="locais" className="space-y-3">
          <div className="grid gap-3 xl:grid-cols-[1.2fr_.8fr]">
            <div className="space-y-3">{locations.map(location=><Card key={location.id}><CardHeader className="pb-2"><CardTitle className="flex items-center justify-between text-sm"><span>{location.name}</span><StateBadge active={location.active}/></CardTitle></CardHeader><CardContent><div className="mb-2 text-xs text-muted-foreground">{location.address ?? location.venue_type ?? 'Local desportivo'}</div>{(location.pools ?? []).length===0 ? <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">Sem piscinas/áreas configuradas.</div> : <div className="grid gap-2 lg:grid-cols-2">{location.pools?.map(pool=><div key={pool.id} className="rounded-lg border p-3"><div className="flex justify-between"><div className="font-medium">{pool.name}</div><StateBadge active={pool.active}/></div><div className="mt-1 text-xs text-muted-foreground">{pool.length_m ? `${pool.length_m} m` : 'Comprimento não definido'} · {(pool.lanes ?? []).length} pista(s)</div><div className="mt-2 flex flex-wrap gap-1">{pool.lanes?.map(l=><Badge key={l.id} variant="outline">P{l.lane_number}{l.capacity ? ` · ${l.capacity}` : ''}</Badge>)}</div></div>)}</div>}</CardContent></Card>)}</div>
            <div className="space-y-3"><Card><CardHeader className="pb-2"><CardTitle className="text-sm">Nova piscina/área</CardTitle></CardHeader><CardContent><form onSubmit={submitPool} className="space-y-2"><div><Label>Local</Label><select className={selectClass} value={poolForm.data.sports_venue_id} onChange={e=>poolForm.setData('sports_venue_id',e.target.value)}>{locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></div><div><Label>Nome</Label><Input value={poolForm.data.name} onChange={e=>poolForm.setData('name',e.target.value)} /></div><div><Label>Código</Label><Input value={poolForm.data.code} onChange={e=>poolForm.setData('code',e.target.value)} /></div><div className="grid grid-cols-2 gap-2"><div><Label>Comprimento (m)</Label><Input type="number" step="0.01" value={poolForm.data.length_m} onChange={e=>poolForm.setData('length_m',e.target.value)} /></div><div><Label>Capacidade</Label><Input type="number" value={poolForm.data.capacity} onChange={e=>poolForm.setData('capacity',e.target.value)} /></div></div><Button size="sm" disabled={poolForm.processing}>Criar piscina</Button></form></CardContent></Card><Card><CardHeader className="pb-2"><CardTitle className="text-sm">Nova pista</CardTitle></CardHeader><CardContent><form onSubmit={submitLane} className="space-y-2"><div><Label>Piscina</Label><select className={selectClass} value={laneForm.data.sports_pool_id} onChange={e=>laneForm.setData('sports_pool_id',e.target.value)}><option value="">Selecionar…</option>{pools.map(p=><option key={p.id} value={p.id}>{p.locationName} · {p.name}</option>)}</select></div><div className="grid grid-cols-2 gap-2"><div><Label>N.º pista</Label><Input type="number" min="1" value={laneForm.data.lane_number} onChange={e=>laneForm.setData('lane_number',e.target.value)} /></div><div><Label>Capacidade</Label><Input type="number" min="1" value={laneForm.data.capacity} onChange={e=>laneForm.setData('capacity',e.target.value)} /></div></div><div><Label>Nome (opcional)</Label><Input value={laneForm.data.name} onChange={e=>laneForm.setData('name',e.target.value)} /></div><Button size="sm" disabled={laneForm.processing || !laneForm.data.sports_pool_id}>Criar pista</Button></form></CardContent></Card></div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  </AuthenticatedLayout>;
}
