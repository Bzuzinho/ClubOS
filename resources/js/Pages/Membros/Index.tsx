import { Suspense, lazy, useState } from 'react';
import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ChartBar, ChartLineUp, Users as UsersIcon } from '@phosphor-icons/react';

interface User {
    id: string;
    numero_socio: string;
    nome_completo: string;
    email_utilizador?: string;
    foto_perfil?: string;
    estado: string;
    tipo_membro: string[];
}

interface Stats {
    totalMembros: number;
    membrosAtivos: number;
    membrosInativos: number;
    totalAtletas: number;
    atletasAtivos: number;
    encarregados: number;
    treinadores: number;
    novosUltimos30Dias: number;
    atestadosACaducar: number;
}

interface TipoStat {
    tipo: string;
    count: number;
}

interface EscalaoStat {
    escalao: string;
    count: number;
}

interface Props {
    members: User[];
    membersPagination?: MembersPagination;
    filters?: MemberFilters;
    userTypes: any[];
    ageGroups: any[];
    stats: Stats;
    tipoMembrosStats: TipoStat[];
    escaloesStats: EscalaoStat[];
    communicationState?: {
        initialTab?: string;
    };
}

interface MemberFilters {
    search?: string;
    status?: string;
    sports_status?: 'ativo' | 'inativo' | null;
    monthly_fee_status?: 'defined' | 'undefined' | null;
    type?: string | null;
}

interface MembersPagination {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

const MembrosDashboard = lazy(() => import('./Dashboard'));
const MembrosListTab = lazy(() => import('./ListTab'));
const MembrosReportsTab = lazy(() => import('./ReportsTab'));

function TabLoadingState() {
    return <div className="min-h-[240px] rounded-lg border border-dashed border-border bg-background" />;
}

export default function MembrosIndex({ members, membersPagination, filters, userTypes, stats, tipoMembrosStats, escaloesStats, communicationState }: Props) {
    const [activeTab, setActiveTab] = useState(() => {
        if (typeof window !== 'undefined') {
            const queryTab = new URLSearchParams(window.location.search).get('tab');
            if (queryTab) {
                return queryTab;
            }
        }

        return communicationState?.initialTab || 'dashboard';
    });

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div>
                    <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Gestão de Membros</h1>
                    <p className="text-muted-foreground text-xs mt-0.5">
                        Visão geral, gestão e reporting dos membros do clube
                    </p>
                </div>
            }
        >
            <Head title="Membros" />

            <div className={moduleViewportClass}>
                <Tabs value={activeTab} onValueChange={setActiveTab} className={moduleTabsClass}>
                    <TabsList className="grid h-auto w-full shrink-0 grid-cols-3">
                        <TabsTrigger value="dashboard" className="flex items-center gap-1.5 py-1.5 text-xs">
                            <ChartLineUp size={14} weight="duotone" />
                            <span>Dashboard</span>
                        </TabsTrigger>
                        <TabsTrigger value="list" className="flex items-center gap-1.5 py-1.5 text-xs">
                            <UsersIcon size={14} weight="duotone" />
                            <span>Lista de Membros</span>
                        </TabsTrigger>
                        <TabsTrigger value="reports" className="flex items-center gap-1.5 py-1.5 text-xs">
                            <ChartBar size={14} weight="duotone" />
                            <span>Relatórios</span>
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="dashboard" className={moduleTabbedContentClass}>
                        <Suspense fallback={<TabLoadingState />}>
                            <MembrosDashboard
                                stats={stats}
                                tipoMembrosStats={tipoMembrosStats}
                                escaloesStats={escaloesStats}
                            />
                        </Suspense>
                    </TabsContent>

                    <TabsContent value="list" className={moduleTabbedContentClass}>
                        <Suspense fallback={<TabLoadingState />}>
                            <MembrosListTab members={members} membersPagination={membersPagination} filters={filters} userTypes={userTypes} />
                        </Suspense>
                    </TabsContent>

                    <TabsContent value="reports" className={moduleTabbedContentClass}>
                        <Suspense fallback={<TabLoadingState />}>
                            <MembrosReportsTab />
                        </Suspense>
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}