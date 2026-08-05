import { Suspense, lazy, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ListChecks, CalendarBlank, ChartBar } from '@phosphor-icons/react';

const EventosDashboard = lazy(() =>
  import('@/Components/Eventos/EventosDashboard').then((module) => ({
    default: module.EventosDashboard,
  }))
);
const EventosList = lazy(() =>
  import('@/Components/Eventos/EventosList').then((module) => ({
    default: module.EventosList,
  }))
);
const EventosCalendar = lazy(() =>
  import('@/Components/Eventos/EventosCalendar').then((module) => ({
    default: module.EventosCalendar,
  }))
);
const EventosRelatorios = lazy(() =>
  import('@/Components/Eventos/EventosRelatorios').then((module) => ({
    default: module.EventosRelatorios,
  }))
);

function TabFallback() {
  return <div className="py-8 text-sm text-muted-foreground">A carregar...</div>;
}

interface Event {
    id: string;
    titulo: string;
    data_inicio: string;
    data_fim?: string;
    tipo: string;
    estado: string;
    local: string;
    descricao?: string;
    criado_por?: string;
    hora_inicio?: string;
    escaloes_elegiveis?: string[];
}

interface EventStats {
  totalEvents: number;
  upcomingEvents: number;
  completedEvents: number;
  activeConvocatorias: number;
  treinos: number;
  provas: number;
  taxaPresencaMedia: number;
}

interface User {
    id: string;
    nome_completo: string;
    email: string;
}

interface CostCenter {
  id: string;
  nome: string;
  codigo?: string;
  ativo?: boolean;
}

interface AgeGroup {
  id: string;
  nome: string;
  idade_minima?: number;
  idade_maxima?: number;
  ativo?: boolean;
}

interface EventType {
  id: string;
  nome: string;
  categoria?: string;
  visibilidade_default?: string;
  requer_transporte?: boolean;
  ativo?: boolean;
}

interface Props {
  eventos: Event[];
  stats: EventStats;
  users?: User[];
  costCenters: CostCenter[];
  eventTypes: EventType[];
  ageGroups: AgeGroup[];
  convocations?: any[];
  attendances?: any[];
  results?: any[];
  permissions: {
    calendario: boolean;
    calendario_editar: boolean;
    calendario_eliminar: boolean;
    convocatorias: boolean;
    resultados: boolean;
  };
}

type EventosPageProps = Props & Record<string, unknown>;

export default function EventosIndex({
  eventos = [],
  stats,
  users = [],
  costCenters = [],
  eventTypes = [],
  ageGroups = [],
  convocations = [],
  attendances: initialAttendances = [],
  results = [],
  permissions,
}: Props) {
  const page = usePage<EventosPageProps>();
  const [activeTab, setActiveTab] = useState('dashboard');
  const [loadingTab, setLoadingTab] = useState<string | null>(null);
  const [attendances, setAttendances] = useState(initialAttendances);
  const hasUsers = Object.prototype.hasOwnProperty.call(page.props, 'users');
  const hasConvocations = Object.prototype.hasOwnProperty.call(page.props, 'convocations');
  const hasAttendances = Object.prototype.hasOwnProperty.call(page.props, 'attendances');
  const hasResults = Object.prototype.hasOwnProperty.call(page.props, 'results');

  useEffect(() => {
    setAttendances(initialAttendances);
  }, [initialAttendances]);

  useEffect(() => {
    const pendingByTab: Record<string, { ready: boolean; props: string[] }> = {
      relatorios: {
        ready: hasUsers
          && (!permissions.convocatorias || hasConvocations)
          && hasAttendances
          && hasResults,
        props: [
          'users',
          ...(permissions.convocatorias ? ['convocations'] : []),
          'attendances',
          'results',
        ],
      },
    };

    const pending = pendingByTab[activeTab];

    if (!pending || pending.ready) {
      setLoadingTab((current) => (current === activeTab ? null : current));
      return;
    }

    setLoadingTab(activeTab);
    router.reload({
      only: pending.props,
      onFinish: () => setLoadingTab((current) => (current === activeTab ? null : current)),
    });
  }, [activeTab, hasAttendances, hasConvocations, hasResults, hasUsers, permissions.convocatorias]);

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div>
          <h1 className="text-lg sm:text-xl font-semibold tracking-tight">
            Gestão de Eventos
          </h1>
          <p className="text-muted-foreground text-xs mt-0.5">
            Sistema completo de gestão de eventos, convocatórias e presenças
          </p>
        </div>
      }
    >
      <Head title="Gestão de Eventos" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
          <TabsList className={`grid w-full shrink-0 h-9 ${permissions.resultados ? 'grid-cols-4' : 'grid-cols-3'} p-1`}>
            <TabsTrigger
              value="dashboard"
              className="flex items-center gap-1.5 px-1 py-1 text-xs"
            >
              <ChartBar size={16} className="flex-shrink-0" />
              <span>Dashboard</span>
            </TabsTrigger>
            <TabsTrigger
              value="calendario"
              className="flex items-center gap-1.5 px-1 py-1 text-xs"
            >
              <CalendarBlank size={16} className="flex-shrink-0" />
              <span>Calendário</span>
            </TabsTrigger>
            <TabsTrigger
              value="eventos"
              className="flex items-center gap-1.5 px-1 py-1 text-xs"
            >
              <ListChecks size={16} className="flex-shrink-0" />
              <span>Eventos</span>
            </TabsTrigger>
            {permissions.resultados ? (
              <TabsTrigger
                value="relatorios"
                className="flex items-center gap-1.5 px-1 py-1 text-xs"
              >
                <ChartBar size={16} className="flex-shrink-0" />
                <span>Relatórios</span>
              </TabsTrigger>
            ) : null}
          </TabsList>

          <TabsContent value="dashboard" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'dashboard' ? (
              <Suspense fallback={<TabFallback />}>
                <EventosDashboard
                  events={eventos}
                  stats={stats}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="calendario" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'calendario' ? (
              <Suspense fallback={<TabFallback />}>
                <EventosCalendar
                  events={eventos}
                  ageGroups={ageGroups}
                  isActive={activeTab === 'calendario'}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          <TabsContent value="eventos" className={`${moduleTabbedContentClass} space-y-3`}>
            {activeTab === 'eventos' ? (
              <Suspense fallback={<TabFallback />}>
                <EventosList
                  events={eventos}
                  costCenters={costCenters}
                  eventTypes={eventTypes}
                  ageGroups={ageGroups}
                  canEdit={permissions.calendario_editar}
                  canDelete={permissions.calendario_eliminar}
                />
              </Suspense>
            ) : null}
          </TabsContent>

          {permissions.resultados ? (
            <TabsContent value="relatorios" className={`${moduleTabbedContentClass} space-y-3`}>
              {activeTab === 'relatorios' ? (
                !hasUsers
                  || (permissions.convocatorias && !hasConvocations)
                  || !hasAttendances
                  || !hasResults
                  || loadingTab === 'relatorios' ? (
                  <TabFallback />
                ) : (
                  <Suspense fallback={<TabFallback />}>
                    <EventosRelatorios
                      events={eventos}
                      convocatorias={convocations}
                      attendances={attendances}
                      results={results}
                      users={users}
                      ageGroups={ageGroups}
                      canViewConvocations={permissions.convocatorias}
                    />
                  </Suspense>
                )
              ) : null}
            </TabsContent>
          ) : null}
        </Tabs>
      </div>
    </AuthenticatedLayout>
  );
}
