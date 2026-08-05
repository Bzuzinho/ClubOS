import { useMemo } from 'react';
import { Card } from '@/Components/ui/card';
import {
  CalendarBlank,
  Trophy,
  Users,
  TrendUp,
  ClockCounterClockwise,
  CheckCircle,
} from '@phosphor-icons/react';
import { format } from 'date-fns';
import { ptBR } from 'date-fns/locale';

interface Event {
  id: string;
  titulo: string;
  data_inicio: string;
  hora_inicio?: string;
  tipo: string;
  estado: string;
  local?: string;
  escaloes_elegiveis?: string[];
}

interface DashboardStats {
  totalEvents: number;
  upcomingEvents: number;
  completedEvents: number;
  activeConvocatorias: number;
  treinos: number;
  provas: number;
  taxaPresencaMedia: number;
}

interface EventosDashboardProps {
  events: Event[];
  stats: DashboardStats;
}

export function EventosDashboard({
  events = [],
  stats,
}: EventosDashboardProps) {
  const computedStats = useMemo(() => {
    const eventosEmCurso = events.filter((event) => event.estado === 'em_curso').length;

    return {
      totalEventos: stats.totalEvents,
      eventosAgendados: stats.upcomingEvents,
      eventosEmCurso,
      eventosConcluidos: stats.completedEvents,
      treinos: stats.treinos,
      provas: stats.provas,
      convocatoriasAtivas: stats.activeConvocatorias,
      taxaPresencaMedia: stats.taxaPresencaMedia,
    };
  }, [events, stats]);

  const eventosPorTipo = useMemo(() => {
    const tipoMap = new Map<string, number>();
    events.forEach((event) => {
      const tipo = event.tipo || 'Outro';
      tipoMap.set(tipo, (tipoMap.get(tipo) || 0) + 1);
    });

    return Array.from(tipoMap.entries())
      .map(([tipo, count]) => ({ tipo, count }))
      .sort((a, b) => b.count - a.count);
  }, [events]);

  const proximosEventos = useMemo(() => {
    const now = new Date();
    const seteDiasFrente = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
    const todayKey = format(now, 'yyyy-MM-dd');
    const limitKey = format(seteDiasFrente, 'yyyy-MM-dd');

    return events
      .filter((e) => {
        const dataInicio = e.data_inicio.slice(0, 10);
        return dataInicio >= todayKey && dataInicio <= limitKey && e.estado !== 'cancelado';
      })
      .sort(
        (a, b) =>
          new Date(a.data_inicio).getTime() - new Date(b.data_inicio).getTime()
      )
      .slice(0, 5);
  }, [events]);

  const mainStats = [
    {
      title: 'Total de Eventos',
      value: computedStats.totalEventos,
      icon: CalendarBlank,
      color: 'text-blue-600',
      bgColor: 'bg-blue-50',
    },
    {
      title: 'Eventos Agendados',
      value: computedStats.eventosAgendados,
      icon: ClockCounterClockwise,
      color: 'text-orange-600',
      bgColor: 'bg-orange-50',
    },
    {
      title: 'A decorrer',
      value: computedStats.eventosEmCurso,
      icon: TrendUp,
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50',
    },
    {
      title: 'Concluídos',
      value: computedStats.eventosConcluidos,
      icon: CheckCircle,
      color: 'text-green-600',
      bgColor: 'bg-green-50',
    },
    {
      title: 'Treinos',
      value: computedStats.treinos,
      icon: Users,
      color: 'text-purple-600',
      bgColor: 'bg-purple-50',
    },
    {
      title: 'Provas',
      value: computedStats.provas,
      icon: Trophy,
      color: 'text-yellow-600',
      bgColor: 'bg-yellow-50',
    },
    {
      title: 'Convocatórias Ativas',
      value: computedStats.convocatoriasAtivas,
      icon: Users,
      color: 'text-cyan-600',
      bgColor: 'bg-cyan-50',
    },
    {
      title: 'Taxa de Presença Média',
      value: `${computedStats.taxaPresencaMedia.toFixed(1)}%`,
      icon: CheckCircle,
      color: 'text-indigo-600',
      bgColor: 'bg-indigo-50',
    },
  ];

  return (
    <div className="space-y-4">
      <div className="grid gap-3 grid-cols-2 lg:grid-cols-4">
        {mainStats.map((stat) => {
          const Icon = stat.icon;
          return (
            <Card
              key={stat.title}
              className="p-3 border hover:border-primary/50 transition-colors"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="flex-1 min-w-0">
                  <p className="text-xs text-muted-foreground font-medium leading-tight truncate">
                    {stat.title}
                  </p>
                  <p className="text-2xl font-bold mt-1">{stat.value}</p>
                </div>
                <div className={`${stat.bgColor} ${stat.color} p-2 rounded-lg shrink-0`}>
                  <Icon size={20} weight="duotone" />
                </div>
              </div>
            </Card>
          );
        })}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="p-4">
          <h3 className="text-sm font-semibold mb-3">Eventos por Tipo</h3>
          <div className="space-y-2">
            {eventosPorTipo.length === 0 ? (
              <p className="text-xs text-muted-foreground">Sem dados disponíveis</p>
            ) : (
              eventosPorTipo.map((item) => (
                <div
                  key={item.tipo}
                  className="flex items-center justify-between py-2 border-b last:border-0"
                >
                  <span className="text-sm capitalize">{item.tipo}</span>
                  <span className="text-sm font-semibold">{item.count}</span>
                </div>
              ))
            )}
          </div>
        </Card>

        <Card className="p-4">
          <h3 className="text-sm font-semibold mb-3">Próximos Eventos (7 dias)</h3>
          <div className="space-y-2">
            {proximosEventos.length === 0 ? (
              <p className="text-xs text-muted-foreground">Sem eventos próximos</p>
            ) : (
              proximosEventos.map((evento) => (
                <div key={evento.id} className="py-2 border-b last:border-0">
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium truncate">{evento.titulo}</p>
                      <p className="text-xs text-muted-foreground">
                        {format(new Date(evento.data_inicio), "d 'de' MMM", { locale: ptBR })}
                        {evento.hora_inicio ? ` às ${evento.hora_inicio.slice(0, 5)}` : ''}
                      </p>
                    </div>
                    <span
                      className={`text-xs px-2 py-0.5 rounded-full ${
                        evento.tipo === 'prova'
                          ? 'bg-yellow-100 text-yellow-700'
                          : evento.tipo === 'treino'
                            ? 'bg-blue-100 text-blue-700'
                            : 'bg-gray-100 text-gray-700'
                      }`}
                    >
                      {evento.tipo}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}
