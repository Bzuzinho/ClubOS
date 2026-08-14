import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { CalendarBlank, CheckCircle, Gauge, TrendUp, UsersThree, WarningCircle } from '@phosphor-icons/react';

type Stats = {
  active_athletes: number;
  trainings_7d: number;
  trainings_30d: number;
  attendance_30d: number | null;
  executed_volume_30d_m: number;
  today_trainings: number;
  review_required: number;
};
type Training = { id:string; number?:string|null; date?:string|null; start?:string|null; end?:string|null; location?:string|null; type?:string|null; status?:string|null; review_required:boolean };
type Competition = { id:string; name:string; date?:string|null; location?:string|null; status:string; athlete_count:number };
type Alert = { type:string; title:string; count:number; message:string; route:string };
type Athlete = { id:string; name:string; volume_30d_m:number; attendance_30d:number|null };
type Link = { label:string; route:string };
type Props = { stats:Stats; today:Training[]; upcoming_trainings:Training[]; upcoming_competitions:Competition[]; alerts:Alert[]; top_athletes:Athlete[]; quick_links:Link[]; principles:Record<string,boolean> };

const km=(m:number)=>`${(m/1000).toFixed(1)} km`;
const pct=(v:number|null)=>v==null?'—':`${v.toFixed(1)}%`;

export default function DashboardWorkspace({stats,today,upcoming_trainings,upcoming_competitions,alerts,top_athletes,quick_links}:Props){
  return <AuthenticatedLayout fullWidth header={<div><h1 className="text-lg font-semibold">Desportivo</h1><p className="text-xs text-muted-foreground">Visão operacional do módulo</p></div>}>
    <Head title="Dashboard · Desportivo"/>
    <div className="mx-auto max-w-[1500px] space-y-3 p-3">
      <div className="flex gap-1 overflow-auto pb-1">{quick_links.map(link=><Button key={link.route} size="sm" variant="outline" onClick={()=>router.get(route(link.route))}>{link.label}</Button>)}</div>

      <div className="grid grid-cols-2 gap-2 lg:grid-cols-6">
        <Metric icon={<UsersThree size={17}/>} label="Atletas ativos" value={stats.active_athletes}/>
        <Metric icon={<CalendarBlank size={17}/>} label="Treinos · 7 dias" value={stats.trainings_7d}/>
        <Metric icon={<CheckCircle size={17}/>} label="Assiduidade · 30 dias" value={pct(stats.attendance_30d)}/>
        <Metric icon={<TrendUp size={17}/>} label="Volume executado" value={km(stats.executed_volume_30d_m)}/>
        <Metric icon={<Gauge size={17}/>} label="Treinos hoje" value={stats.today_trainings}/>
        <Metric icon={<WarningCircle size={17}/>} label="Sessões a rever" value={stats.review_required}/>
      </div>

      <div className="grid gap-3 lg:grid-cols-[1.2fr_1fr]">
        <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Hoje</CardTitle></CardHeader><CardContent className="space-y-2">{today.length===0?<Empty text="Sem treinos hoje."/>:today.map(t=><TrainingRow key={t.id} t={t}/>)}</CardContent></Card>
        <Card><CardHeader className="pb-2"><CardTitle className="flex items-center gap-1.5 text-sm"><WarningCircle size={14}/>Alertas operacionais</CardTitle></CardHeader><CardContent className="space-y-2">{alerts.length===0?<Empty text="Sem alertas ativos."/>:alerts.map(a=><button key={a.title} onClick={()=>router.get(route(a.route))} className="flex w-full items-center justify-between rounded-md border p-2 text-left hover:bg-muted/40"><div><p className="text-xs font-medium">{a.title}</p><p className="text-[11px] text-muted-foreground">{a.message}</p></div><Badge variant="outline">{a.count}</Badge></button>)}</CardContent></Card>
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Próximos treinos</CardTitle></CardHeader><CardContent className="space-y-2">{upcoming_trainings.length===0?<Empty text="Sem sessões na próxima janela."/>:upcoming_trainings.slice(0,6).map(t=><TrainingRow key={t.id} t={t}/>)}</CardContent></Card>
        <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Próximas competições</CardTitle></CardHeader><CardContent className="space-y-2">{upcoming_competitions.length===0?<Empty text="Sem competições na próxima janela."/>:upcoming_competitions.map(c=><button key={c.id} onClick={()=>router.get(route('desportivo.competicoes'))} className="flex w-full items-center justify-between rounded-md border p-2 text-left hover:bg-muted/40"><div><p className="text-xs font-medium">{c.name}</p><p className="text-[11px] text-muted-foreground">{c.date??'—'}{c.location?` · ${c.location}`:''}</p></div><Badge variant="outline">{c.athlete_count} atletas</Badge></button>)}</CardContent></Card>
      </div>

      <Card><CardHeader className="pb-2"><CardTitle className="text-sm">Carga executada · top atletas · 30 dias</CardTitle></CardHeader><CardContent><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">{top_athletes.length===0?<Empty text="Sem execução registada."/>:top_athletes.map(a=><button key={a.id} onClick={()=>router.get(route('desportivo.atletas.index'),{athlete:a.id})} className="rounded-md border p-3 text-left hover:bg-muted/40"><p className="truncate text-xs font-medium">{a.name}</p><p className="mt-1 text-sm font-semibold">{km(a.volume_30d_m)}</p><p className="text-[10px] text-muted-foreground">Assiduidade {pct(a.attendance_30d)}</p></button>)}</div></CardContent></Card>
    </div>
  </AuthenticatedLayout>;
}

function Metric({icon,label,value}:{icon:React.ReactNode;label:string;value:string|number}){return <Card><CardContent className="flex items-center gap-2 p-3"><span className="text-muted-foreground">{icon}</span><div><p className="text-base font-semibold leading-none">{value}</p><p className="mt-1 text-[10px] text-muted-foreground">{label}</p></div></CardContent></Card>}
function Empty({text}:{text:string}){return <p className="text-xs text-muted-foreground">{text}</p>}
function TrainingRow({t}:{t:Training}){return <button onClick={()=>router.get(route('desportivo.treinos'))} className="flex w-full items-center justify-between rounded-md border p-2 text-left hover:bg-muted/40"><div><p className="text-xs font-medium">{t.number?`Treino ${t.number}`:t.type||'Treino'}</p><p className="text-[11px] text-muted-foreground">{t.date??'—'}{t.start?` · ${t.start}`:''}{t.location?` · ${t.location}`:''}</p></div>{t.review_required?<Badge variant="outline">Rever</Badge>:<Badge variant="secondary">{t.status||'agendado'}</Badge>}</button>}
