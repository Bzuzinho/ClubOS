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
interface Season { id:string; nome:string; data_inicio:string; data_fim:string; status?:string; modality?:Modality; programs?:Array<{id:string;active:boolean;program?:Program}> }
interface AgeGroup { id:string; code?:string; nome:string; idade_minima?:number|null; idade_maxima?:number|null; ativo:boolean }
interface Group { id:string; code:string; name:string; active:boolean; modality_definition?:Modality; season_configurations?:unknown[] }
interface CoachRole { id:string; code:string; name:string; active:boolean }
interface Lane { id:string; lane_number:number; name?:string|null; capacity?:number|null; active:boolean }
interface Pool { id:string; code:string; name:string; length_m?:string|null; active:boolean; lanes?:Lane[] }
interface Location { id:string; code:string; name:string; venue_type?:string; address?:string|null; active:boolean; pools?:Pool[] }
interface Rule { id:string; gender?:string|null; birth_year_min?:number|null; birth_year_max?:number|null; age_min?:number|null; age_max?:number|null; active:boolean; season?:Season; age_group?:AgeGroup }

interface Props { modalities:Modality[]; programs:Program[]; seasons:Season[]; ageGroups:AgeGroup[]; ageGroupRules:Rule[]; groups:Group[]; coachRoles:CoachRole[]; locations:Location[] }

function StateBadge({ active }: { active:boolean }) { return <Badge variant={active ? 'default' : 'secondary'}>{active ? 'Ativo' : 'Inativo'}</Badge>; }

export default function SportsStructureIndex({ modalities, programs, seasons, ageGroups, ageGroupRules, groups, coachRoles, locations }:Props) {
  const [tab,setTab] = useState('modalidades');
  const modalityForm = useForm({ code:'', name:'', description:'', active:true });
  const programForm = useForm({ sports_modality_id:modalities[0]?.id ?? '', code:'', name:'', description:'', active:true });
  const roleForm = useForm({ code:'', name:'', description:'' });
  const counts = useMemo(() => ({ modalities:modalities.filter(x=>x.active).length, programs:programs.filter(x=>x.active).length, groups:groups.filter(x=>x.active).length, locations:locations.filter(x=>x.active).length }), [modalities,programs,groups,locations]);

  const submitModality = (e:FormEvent) => { e.preventDefault(); modalityForm.post(route('desportivo.estrutura.modalidades.store'), { preserveScroll:true, onSuccess:()=>modalityForm.reset('code','name','description') }); };
  const submitProgram = (e:FormEvent) => { e.preventDefault(); programForm.post(route('desportivo.estrutura.programas.store'), { preserveScroll:true, onSuccess:()=>programForm.reset('code','name','description') }); };
  const submitRole = (e:FormEvent) => { e.preventDefault(); roleForm.post(route('desportivo.estrutura.treinadores.funcoes.store'), { preserveScroll:true, onSuccess:()=>roleForm.reset() }); };

  return <AuthenticatedLayout fullWidth header={<div className="flex w-full items-center justify-between gap-3"><div><h1 className="text-lg font-semibold">Estrutura Desportiva</h1><p className="mt-0.5 text-xs text-muted-foreground">Fundação organizacional do Desportivo</p></div><Button variant="outline" size="sm" onClick={()=>router.get(route('desportivo.index'))}><ArrowLeft size={15} className="mr-1.5"/>Desportivo</Button></div>}>
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
              <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Novo programa</CardTitle></CardHeader><CardContent><form onSubmit={submitProgram} className="space-y-2"><div><Label>Modalidade</Label><select className="h-9 w-full rounded-md border bg-background px-3 text-sm" value={programForm.data.sports_modality_id} onChange={e=>programForm.setData('sports_modality_id',e.target.value)}>{modalities.map(m=><option key={m.id} value={m.id}>{m.name}</option>)}</select></div><div><Label>Nome</Label><Input value={programForm.data.name} onChange={e=>programForm.setData('name',e.target.value)} /></div><div><Label>Código</Label><Input value={programForm.data.code} onChange={e=>programForm.setData('code',e.target.value)} /></div><Button size="sm" disabled={programForm.processing}><Plus size={14} className="mr-1"/>Criar programa</Button></form></CardContent></Card>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="epocas" className="space-y-3">
          <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Épocas</CardTitle></CardHeader><CardContent className="grid gap-2 lg:grid-cols-2">{seasons.map(s=><div key={s.id} className="rounded-lg border p-3"><div className="flex items-center justify-between"><div className="font-medium">{s.nome}</div><Badge variant="outline">{s.status ?? 'planned'}</Badge></div><div className="mt-1 text-xs text-muted-foreground">{s.modality?.name ?? 'Natação'} · {s.data_inicio} → {s.data_fim}</div><div className="mt-2 flex flex-wrap gap-1">{s.programs?.filter(x=>x.active).map(x=><Badge key={x.id} variant="secondary">{x.program?.name}</Badge>)}</div></div>)}</CardContent></Card>
          <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Escalões e regras sazonais</CardTitle></CardHeader><CardContent><div className="grid gap-2 lg:grid-cols-2">{ageGroups.map(a=><div key={a.id} className="rounded-lg border p-3"><div className="flex justify-between"><span className="font-medium">{a.nome}</span><StateBadge active={a.ativo}/></div><div className="text-xs text-muted-foreground">{a.code ?? '—'} · idade base {a.idade_minima ?? '—'}–{a.idade_maxima ?? '—'}</div><div className="mt-2 text-xs">{ageGroupRules.filter(r=>r.age_group?.id===a.id && r.active).length} regra(s) de época</div></div>)}</div></CardContent></Card>
        </TabsContent>

        <TabsContent value="grupos" className="space-y-3">
          <div className="grid gap-3 xl:grid-cols-[1.4fr_.6fr]"><Card><CardHeader className="pb-2"><CardTitle className="text-sm">Grupos/equipas</CardTitle></CardHeader><CardContent className="space-y-2">{groups.map(g=><div key={g.id} className="flex items-center justify-between rounded-lg border p-3"><div><div className="font-medium">{g.name}</div><div className="text-xs text-muted-foreground">{g.code} · {g.modality_definition?.name ?? 'Natação'}</div></div><StateBadge active={g.active}/></div>)}</CardContent></Card><Card><CardHeader className="pb-2"><CardTitle className="text-sm">Funções técnicas</CardTitle></CardHeader><CardContent className="space-y-3"><div className="space-y-1">{coachRoles.map(r=><div key={r.id} className="flex items-center justify-between rounded border px-2 py-1.5 text-sm"><span>{r.name}</span><StateBadge active={r.active}/></div>)}</div><form onSubmit={submitRole} className="space-y-2 border-t pt-3"><div><Label>Nova função</Label><Input value={roleForm.data.name} onChange={e=>roleForm.setData('name',e.target.value)} placeholder="Treinador Principal" /></div><div><Label>Código</Label><Input value={roleForm.data.code} onChange={e=>roleForm.setData('code',e.target.value)} /></div><Button size="sm" disabled={roleForm.processing}><UserCircleGear size={14} className="mr-1"/>Adicionar</Button></form></CardContent></Card></div>
        </TabsContent>

        <TabsContent value="locais" className="space-y-3">
          {locations.map(location=><Card key={location.id}><CardHeader className="pb-2"><CardTitle className="flex items-center justify-between text-sm"><span>{location.name}</span><StateBadge active={location.active}/></CardTitle></CardHeader><CardContent><div className="mb-2 text-xs text-muted-foreground">{location.address ?? location.venue_type ?? 'Local desportivo'}</div>{(location.pools ?? []).length===0 ? <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">Sem piscinas/áreas configuradas.</div> : <div className="grid gap-2 lg:grid-cols-2">{location.pools?.map(pool=><div key={pool.id} className="rounded-lg border p-3"><div className="flex justify-between"><div className="font-medium">{pool.name}</div><StateBadge active={pool.active}/></div><div className="mt-1 text-xs text-muted-foreground">{pool.length_m ? `${pool.length_m} m` : 'Comprimento não definido'} · {(pool.lanes ?? []).length} pista(s)</div><div className="mt-2 flex flex-wrap gap-1">{pool.lanes?.map(l=><Badge key={l.id} variant="outline">P{l.lane_number}{l.capacity ? ` · ${l.capacity}` : ''}</Badge>)}</div></div>)}</div>}</CardContent></Card>)}
        </TabsContent>
      </Tabs>
    </div>
  </AuthenticatedLayout>;
}
