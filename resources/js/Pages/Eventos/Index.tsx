import { Suspense, lazy, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ModuleHeader } from '@/Components/layout/ModuleHeader';
import { ModuleTabsList } from '@/Components/layout/ModuleTabsList';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import { ListChecks, CalendarBlank, ChartBar } from '@phosphor-icons/react';
import { useUrlTab } from '@/hooks/useUrlTab';

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

const EVENT_TABS = ['dashboard', 'calendario', 'eventos', 'relatorios'] as const;
const EVENT_TABS_WITHOUT_REPORTS = ['dashboard', 'calendario', 'eventos'] as const;

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
  const availableTabs = permissions.resultados ? EVENT_TABS : EVENT_TABS_WITHOUT_REPORTS;
  const [activeTab, setActiveTab] = useUrlTab(availableTabs, 'dashboard');
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
        <ModuleHeader
          title="Eventos"
          description="Calendário, eventos, convocatórias, resultados e presenças."
        />
      }
    >
      <Head title="Gestão de Eventos" />

      <div className={moduleViewportClass}>
        <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
          <ModuleTabsList label="Áreas de Eventos">
            <TabsTrigger
              value="dashboard"
              className="flex items-center gap-1.5 px-1 py-1 text-xs"
            >
              <ChartBar size={16} className="flex-shrink-0" />
              <span>Visão geral</span>
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
          </ModuleTabsList>

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
