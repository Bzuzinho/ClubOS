/**
 * Pages/Desportivo/Index.tsx
 *
 * Página principal do módulo "Desportivo".
 *
 * Fontes de dados (reutilizadas do backend existente):
 *   - trainings         → tabela `trainings`
 *   - trainingOptions   → tabela `trainings` (subset para selector)
 *   - selectedTraining  → trainer selecionado via ?training_id=
 *   - presences         → tabela `training_athletes`
 *   - seasons           → tabela `seasons`
 *   - macrocycles       → tabela `macrocycles`
 *   - ageGroups         → tabela `age_groups`
 *   - competitions      → tabela `competitions`
 *   - results           → tabela `results` com contexto em `provas`/`competitions`
 *   - users             → tabela `users`
 *   - volumeByAthlete   → query sobre `training_athletes`
 *   - stats             → queries agregadas
 *   - alerts            → calculados no controller
 *
 * Tabs:
 *   dashboard | atletas | planeamento | biblioteca | treinos | cais | competicoes | performance
 */

import { Suspense, lazy, useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleScrollableContentClass, moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
  Buildings,
  ChartBar,
  UsersThree,
  CalendarBlank,
  CheckSquare,
  MapTrifold,
  Trophy,
  Lightning,
  GearSix,
  Books,
} from '@phosphor-icons/react';

import type {
  AgeGroup,
  AthleteOperationalRow,
  Competition,
  Event,
  EventResult,
  Macrocycle,
  MesocyclePlan,
  PresenceRow,
  Season,
  Stats,
  TeamResult,
  Training,
  User,
} from '@/types/sports';
import type { TrainingLibraryOption, TrainingLibraryPlan } from '@/components/sports/tabs/TrainingLibraryTab';

interface TrainingOption {
  id: string;
  numero_treino?: string | null;
  data: string;
}

interface Alert {
  title: string;
  message: string;
  type: string;
}

interface UpcomingCompetition {
  id: string;
  nome: string;
  data_inicio: string;
  num_atletas_inscritos: number;
}

interface VolumeRow {
  nome_completo: string;
  total_m: number;
}

interface MicrocycleOption {
  id: string;
  nome: string;
  mesociclo_id?: string;
  macrocycle_id?: string;
  epoca_id?: string;
}

interface MacrocycleOption {
  id: string;
  nome: string;
  epoca_id?: string;
}

interface MesocycleOption {
  id: string;
  nome: string;
  macrociclo_id?: string;
  epoca_id?: string;
}

interface DesportivoProps {
  tab?: string;
  stats?: Stats;
  alerts?: Alert[];
  upcomingCompetitions?: UpcomingCompetition[];
  seasons?: Season[];
  selectedSeason?: Season | null;
  macrocycles?: Macrocycle[];
  macrocycleOptions?: MacrocycleOption[];
  mesocycles?: MesocyclePlan[];
  mesocycleOptions?: MesocycleOption[];
  microcycles?: MicrocycleOption[];
  microcycleOptions?: MicrocycleOption[];
  ageGroups?: AgeGroup[];
  trainingTypeOptions?: Array<{ id: string; nome: string }>;
  trainingZoneOptions?: Array<{ id: string; codigo: string; nome: string }>;
  trainings?: { data: Training[] };
  calendarTrainings?: Array<{ id: string; numero_treino?: string | null; data: string; macrocycle_id?: string | null; mesociclo_id?: string | null; microciclo_id?: string | null }>;
  trainingOptions?: TrainingOption[];
  selectedTraining?: Training | null;
  presences?: PresenceRow[];
  competitions?: Competition[];
  results?: EventResult[];
  teamResults?: TeamResult[];
  users?: User[];
  eventos?: Event[];
  costCenters?: Array<{ id: string; nome: string; codigo?: string; ativo?: boolean }>;
  eventTypes?: Array<{ id: string; nome: string; visibilidade_default?: string; ativo?: boolean }>;
  convocations?: any[];
  convocationGroups?: any[];
  provaTipos?: Array<{ id: string; nome: string }>;
  statusOptions?: string[];
  classificacaoOptions?: string[];
  volumeByAthlete?: VolumeRow[];
  athleteOperationalRows?: AthleteOperationalRow[];
  libraryPlans?: TrainingLibraryPlan[];
  libraryModalities?: TrainingLibraryOption[];
  libraryTrainingTypes?: TrainingLibraryOption[];
  libraryZones?: TrainingLibraryOption[];
  libraryStrokes?: TrainingLibraryOption[];
  libraryMaterials?: TrainingLibraryOption[];
}

type DesportivoPageProps = DesportivoProps & Record<string, unknown>;

const DashboardTab = lazy(() => import('@/components/sports/tabs/DashboardTab').then((module) => ({ default: module.DashboardTab })));
const AthletesTab = lazy(() => import('@/components/sports/tabs/AthletesTab').then((module) => ({ default: module.AthletesTab })));
const TrainingsTab = lazy(() => import('@/components/sports/tabs/TrainingsTab').then((module) => ({ default: module.TrainingsTab })));
const PoolDeckTab = lazy(() => import('@/components/sports/tabs/PoolDeckTab').then((module) => ({ default: module.PoolDeckTab })));
const PlanningTab = lazy(() => import('@/components/sports/tabs/PlanningTab').then((module) => ({ default: module.PlanningTab })));
const TrainingLibraryTab = lazy(() => import('@/components/sports/tabs/TrainingLibraryTab').then((module) => ({ default: module.TrainingLibraryTab })));
const CompetitionsTab = lazy(() => import('@/components/sports/tabs/CompetitionsTab').then((module) => ({ default: module.CompetitionsTab })));
const PerformanceTab = lazy(() => import('@/components/sports/tabs/PerformanceTab').then((module) => ({ default: module.PerformanceTab })));

const TABS = [
  { value: 'dashboard',    label: 'Dashboard',    Icon: ChartBar },
  { value: 'atletas',      label: 'Atletas',      Icon: UsersThree },
  { value: 'planeamento',  label: 'Planeamento',  Icon: MapTrifold },
  { value: 'biblioteca',   label: 'Biblioteca',   Icon: Books },
  { value: 'treinos',      label: 'Treinos',      Icon: CalendarBlank },
  { value: 'cais',         label: 'Cais',         Icon: CheckSquare },
  { value: 'competicoes',  label: 'Competições',  Icon: Trophy },
  { value: 'performance',  label: 'Performance',  Icon: Lightning },
] as const;

type TabValue = typeof TABS[number]['value'];

const DEFAULT_STATS: Stats = {
  athletesCount: 0,
  trainings7Days: 0,
  trainings30Days: 0,
  km7Days: 0,
  km30Days: 0,
};

const TAB_ROUTES: Record<TabValue, () => string> = {
  dashboard: () => route('desportivo.index'),
  atletas: () => route('desportivo.index', { tab: 'atletas' }),
  planeamento: () => route('desportivo.planeamento'),
  biblioteca: () => route('desportivo.biblioteca'),
  treinos: () => route('desportivo.treinos'),
  cais: () => route('desportivo.cais'),
  competicoes: () => route('desportivo.competicoes'),
  performance: () => route('desportivo.relatorios'),
};

function TabLoadingState() {
  return (
    <div className={moduleScrollableContentClass}>
      <div className="min-h-[240px] rounded-lg border border-dashed border-border bg-background" />
    </div>
  );
}

export default function DesportivoIndex({
  tab = 'dashboard',
  stats = DEFAULT_STATS,
  alerts = [],
  upcomingCompetitions = [],
  seasons = [],
  selectedSeason = null,
  macrocycles = [],
  macrocycleOptions = [],
  mesocycles = [],
  mesocycleOptions = [],
  microcycles = [],
  microcycleOptions = [],
  ageGroups = [],
  trainingTypeOptions = [],
  trainingZoneOptions = [],
  trainings = { data: [] },
  calendarTrainings = [],
  trainingOptions = [],
  selectedTraining = null,
  presences = [],
  competitions = [],
  results = [],
  teamResults = [],
  users = [],
  eventos = [],
  costCenters = [],
  eventTypes = [],
  convocations = [],
  convocationGroups = [],
  provaTipos = [],
  statusOptions = ['presente', 'atrasado', 'falta', 'dispensado'],
  volumeByAthlete = [],
  athleteOperationalRows = [],
  libraryPlans = [],
  libraryModalities = [],
  libraryTrainingTypes = [],
  libraryZones = [],
  libraryStrokes = [],
  libraryMaterials = [],
}: DesportivoProps) {
  const page = usePage<DesportivoPageProps>();
  const initialTab = (tab === 'presencas' ? 'cais' : tab === 'resultados' ? 'competicoes' : tab) as TabValue;
  const [isNavigatingToCais, setIsNavigatingToCais] = useState(false);
  const activeTab = TABS.some(({ value }) => value === initialTab) ? initialTab : 'dashboard';
  const [loadingDashboardSlice, setLoadingDashboardSlice] = useState(false);
  const hasVolumeByAthlete = Object.prototype.hasOwnProperty.call(page.props, 'volumeByAthlete');

  const safeUsers = Array.isArray(users) ? users : [];
  const safeTrainings = Array.isArray(trainings?.data) ? trainings.data : [];
  const safeCompetitions = Array.isArray(competitions) ? competitions : [];
  const safeResults = Array.isArray(results) ? results : [];
  const safeVolumeByAthlete = Array.isArray(volumeByAthlete) ? volumeByAthlete : [];

  const resolvedUsers = safeUsers;
  const resolvedTrainings = safeTrainings;
  const resolvedCompetitions = safeCompetitions;
  const resolvedResults = safeResults;
  const resolvedVolumeByAthlete = safeVolumeByAthlete;

  useEffect(() => {
    if (activeTab !== 'dashboard' || hasVolumeByAthlete || loadingDashboardSlice) {
      return;
    }

    setLoadingDashboardSlice(true);
    router.reload({
      only: ['volumeByAthlete'],
      onFinish: () => setLoadingDashboardSlice(false),
    });
  }, [activeTab, hasVolumeByAthlete, loadingDashboardSlice]);

  const resolvedTrainingOptions = trainingOptions.length > 0
    ? trainingOptions
    : resolvedTrainings.map((training) => ({
      id: training.id,
      numero_treino: training.numero_treino ?? null,
      data: training.data ?? '',
    }));

  const resolvedTrainingTypeOptions = trainingTypeOptions.length > 0
    ? trainingTypeOptions
    : Array.from(new Set(resolvedTrainings.map((training) => training.tipo_treino).filter(Boolean))).map((nome, idx) => ({
        id: `fallback-type-${idx}`,
        nome,
      }));

  const handleTabChange = (value: string) => {
    const t = value as TabValue;

    if (t === activeTab) return;

    setIsNavigatingToCais(t === 'cais');

    router.get(TAB_ROUTES[t](), {}, {
      preserveScroll: true,
      preserveState: false,
    });
  };

  return (
    <AuthenticatedLayout
      fullWidth
      header={
        <div className="flex w-full items-center justify-between gap-4">
          <div>
            <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Desportivo</h1>
            <p className="text-muted-foreground text-xs mt-0.5">
              Sistema técnico de gestão desportiva: planeamento, biblioteca, sessões, cais, competições e performance
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="inline-flex h-9 items-center justify-center gap-2 rounded-md border bg-background px-3 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
              onClick={() => router.get(route('desportivo.estrutura.index'))}
              aria-label="Estrutura Desportiva"
              title="Estrutura Desportiva"
            >
              <Buildings size={17} />
              <span className="hidden sm:inline">Estrutura</span>
            </button>
            <button
              type="button"
              className="inline-flex h-9 w-9 items-center justify-center rounded-md border bg-background text-muted-foreground transition hover:bg-muted hover:text-foreground"
              onClick={() => router.get(route('desportivo.configuracao.index'))}
              aria-label="Configuração Desportiva"
              title="Configuração Desportiva"
            >
              <GearSix size={18} />
            </button>
          </div>
        </div>
      }
    >
      <Head title="Desportivo" />

      <div className={moduleViewportClass}>
        {isNavigatingToCais ? (
          <div className={moduleScrollableContentClass}>
            <div className="min-h-[240px] rounded-lg border border-dashed border-border bg-background" />
          </div>
        ) : (
          <>
            <Tabs value={activeTab} onValueChange={handleTabChange} className={moduleTabsClass}>
              <TabsList className="grid w-full shrink-0 grid-cols-4 sm:grid-cols-8 h-auto">
                {TABS.map(({ value, label, Icon }) => (
                  <TabsTrigger
                    key={value}
                    value={value}
                    className="flex items-center gap-1.5 py-1.5 text-xs"
                  >
                    <Icon size={12} />
                    <span>{label}</span>
                  </TabsTrigger>
                ))}
              </TabsList>

              <TabsContent value="dashboard" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <DashboardTab
                    stats={stats}
                    alerts={alerts}
                    trainings={resolvedTrainings}
                    upcomingCompetitions={upcomingCompetitions}
                    competitions={resolvedCompetitions}
                    eventos={eventos}
                    ageGroups={ageGroups}
                    users={resolvedUsers}
                    volumeByAthlete={resolvedVolumeByAthlete}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="atletas" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <AthletesTab
                    users={resolvedUsers}
                    volumeByAthlete={resolvedVolumeByAthlete}
                    athleteOperationalRows={athleteOperationalRows}
                    ageGroups={ageGroups}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="planeamento" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <PlanningTab
                    seasons={seasons}
                    macrocycles={macrocycles}
                    mesocycles={mesocycles}
                    selectedSeasonId={selectedSeason?.id ?? null}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="biblioteca" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <TrainingLibraryTab
                    plans={libraryPlans}
                    modalities={libraryModalities}
                    trainingTypes={libraryTrainingTypes}
                    zones={libraryZones}
                    strokes={libraryStrokes}
                    materials={libraryMaterials}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="treinos" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <TrainingsTab
                    trainings={resolvedTrainings}
                    seasons={seasons}
                    ageGroups={ageGroups}
                    users={resolvedUsers}
                    trainingTypeOptions={resolvedTrainingTypeOptions}
                    trainingZoneOptions={trainingZoneOptions}
                    selectedSeasonId={selectedSeason?.id}
                    macrocycles={macrocycleOptions.length > 0 ? macrocycleOptions : macrocycles}
                    mesocycles={mesocycleOptions.length > 0 ? mesocycleOptions : mesocycles}
                    microcycles={microcycleOptions.length > 0 ? microcycleOptions : microcycles}
                    planningSeason={selectedSeason}
                    planningMacrocycles={macrocycles}
                    planningMesocycles={mesocycles}
                    calendarTrainings={calendarTrainings}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="cais" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <PoolDeckTab
                    trainings={resolvedTrainings}
                    trainingOptions={resolvedTrainingOptions}
                    selectedTraining={selectedTraining}
                    users={resolvedUsers}
                    ageGroups={ageGroups}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="competicoes" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <CompetitionsTab
                    competitions={resolvedCompetitions}
                    results={resolvedResults}
                    teamResults={teamResults}
                    users={resolvedUsers}
                    eventos={eventos}
                    ageGroups={ageGroups}
                    costCenters={costCenters}
                    eventTypes={eventTypes}
                    convocations={convocations}
                    convocationGroups={convocationGroups}
                    provaTipos={provaTipos}
                  />
                </Suspense>
              </TabsContent>

              <TabsContent value="performance" className={moduleTabbedContentClass}>
                <Suspense fallback={<TabLoadingState />}>
                  <PerformanceTab
                    users={resolvedUsers}
                    volumeByAthlete={resolvedVolumeByAthlete}
                  />
                </Suspense>
              </TabsContent>
            </Tabs>
          </>
        )}
      </div>
    </AuthenticatedLayout>
  );
}
