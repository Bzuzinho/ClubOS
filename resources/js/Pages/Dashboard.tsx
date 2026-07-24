import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleScrollableContentClass, moduleViewportClass } from '@/lib/module-layout';
import { Users, Trophy, CalendarBlank, CurrencyCircleDollar, Heartbeat, UserCircle, WarningCircle } from '@phosphor-icons/react';
import { Card } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { router } from '@inertiajs/react';
import UnreadAlertsList from '@/Components/Comunicacao/UnreadAlertsList';

interface Event {
    id: string;
    titulo: string;
    data_inicio: string;
    tipo: string;
    estado: string;
}

interface FinancialEntry {
    id: string;
    descricao: string;
    data: string;
    valor: number;
    tipo: 'receita' | 'despesa';
}

interface Props {
    stats: {
        totalMembers: number;
        activeAthletes: number;
        guardians: number;
        upcomingEvents: number;
        monthlyRevenue: number;
    };
    recentEvents: Event[];
    recentActivity: FinancialEntry[];
    minorsWithoutGuardian?: {
        total: number;
        has_more: boolean;
        all_url: string;
        items: Array<{
            id: string;
            name: string;
            age: number | null;
            birthdate: string | null;
            status: string;
            member_url: string;
        }>;
    };
}

interface PageProps {
    communicationAlerts?: {
        unreadCount: number;
        recent: Array<{
            id: string;
            title: string;
            message: string;
            type: 'info' | 'warning' | 'success' | 'error';
            link?: string | null;
            is_read: boolean;
            created_at: string;
        }>;
    };
}

export default function Dashboard({ stats, recentEvents = [], recentActivity = [], minorsWithoutGuardian }: Props) {
    const { communicationAlerts } = usePage<PageProps>().props;
    // Validação de dados
    const toNumber = (value: unknown, fallback = 0) => {
        if (typeof value === 'number' && !Number.isNaN(value)) return value;
        if (typeof value === 'string' && value.trim() !== '') {
            const parsed = Number(value);
            return Number.isNaN(parsed) ? fallback : parsed;
        }
        return fallback;
    };
    const safeStats = {
        totalMembers: stats?.totalMembers ?? 0,
        activeAthletes: stats?.activeAthletes ?? 0,
        guardians: stats?.guardians ?? 0,
        upcomingEvents: stats?.upcomingEvents ?? 0,
        monthlyRevenue: toNumber(stats?.monthlyRevenue ?? 0),
    };

    const handleNavigate = (view: string) => {
        const routes: Record<string, string> = {
            'members': '/membros',
            'sports': '/desportivo',
            'events': '/eventos',
            'financial': '/financeiro'
        };
        router.visit(routes[view] || '/dashboard');
    };

    const statsConfig = [
        {
            title: 'Membros Ativos',
            value: safeStats.totalMembers,
            icon: Users,
            color: 'text-blue-600',
            bgColor: 'bg-blue-50',
            view: 'members'
        },
        {
            title: 'Atletas Ativos',
            value: safeStats.activeAthletes,
            icon: Heartbeat,
            color: 'text-emerald-600',
            bgColor: 'bg-emerald-50',
            view: 'members'
        },
        {
            title: 'Encarregados de Educação',
            value: safeStats.guardians,
            icon: UserCircle,
            color: 'text-cyan-600',
            bgColor: 'bg-cyan-50',
            view: 'members'
        },
        {
            title: 'Eventos Próximos',
            value: safeStats.upcomingEvents,
            icon: CalendarBlank,
            color: 'text-orange-600',
            bgColor: 'bg-orange-50',
            view: 'events'
        },
        {
            title: 'Receitas do Mês',
            value: `€${safeStats.monthlyRevenue.toFixed(2)}`,
            icon: CurrencyCircleDollar,
            color: 'text-purple-600',
            bgColor: 'bg-purple-50',
            view: 'financial'
        }
    ];

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div>
                    <h1 className="text-lg sm:text-xl font-semibold tracking-tight">Dashboard</h1>
                    <p className="text-muted-foreground text-xs mt-0.5">Visão geral do clube</p>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className={moduleViewportClass}>
                <div className={`${moduleScrollableContentClass} space-y-2 sm:space-y-3`}>
                {/* Stats Cards - 5 cards */}
                <div className="grid gap-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    {statsConfig.map((stat) => {
                        const Icon = stat.icon;
                        return (
                            <Card
                                key={stat.title}
                                className="p-2 sm:p-3 cursor-pointer transition-all hover:shadow-md hover:border-primary/50"
                                onClick={() => handleNavigate(stat.view)}
                            >
                                <div className="flex items-start justify-between gap-1">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs text-muted-foreground font-medium leading-tight truncate">{stat.title}</p>
                                        <p className="text-base sm:text-xl font-bold mt-0.5 truncate">{stat.value}</p>
                                    </div>
                                    <div className={`p-1.5 rounded-lg ${stat.bgColor} flex-shrink-0`}>
                                        <Icon className={stat.color} size={16} weight="bold" />
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>

                {(minorsWithoutGuardian?.total ?? 0) > 0 && (
                    <Card className="border-amber-300 bg-amber-50/60 p-3">
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex min-w-0 items-start gap-2">
                                <WarningCircle className="mt-0.5 flex-shrink-0 text-amber-700" size={20} weight="fill" />
                                <div>
                                    <h2 className="text-sm sm:text-base font-semibold text-amber-950">
                                        Menores sem encarregado de educação
                                    </h2>
                                    <p className="text-xs text-amber-800">
                                        {minorsWithoutGuardian?.total ?? 0} pendência(s) administrativa(s) para gestão progressiva.
                                    </p>
                                </div>
                            </div>
                            <span className="rounded-full bg-amber-200 px-2.5 py-1 text-sm font-bold text-amber-950">
                                {minorsWithoutGuardian?.total ?? 0}
                            </span>
                        </div>

                        <div className="mt-3 space-y-1.5">
                            {(minorsWithoutGuardian?.items ?? []).map((minor) => (
                                <div key={minor.id} className="flex flex-col gap-2 rounded-lg border border-amber-200 bg-white/80 p-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="truncate text-xs font-semibold">{minor.name}</p>
                                        <p className="text-[11px] text-muted-foreground">
                                            Idade: {minor.age ?? 'Não disponível'} · Nascimento: {minor.birthdate ? new Date(`${minor.birthdate}T00:00:00`).toLocaleDateString('pt-PT') : 'Data inválida/não disponível'} · Estado: {minor.status}
                                        </p>
                                    </div>
                                    <Button variant="outline" size="sm" className="h-7 text-xs" onClick={() => router.visit(minor.member_url)}>
                                        Abrir ficha
                                    </Button>
                                </div>
                            ))}
                        </div>

                        {(minorsWithoutGuardian?.has_more ?? false) && (
                            <Button variant="link" className="mt-2 h-auto p-0 text-xs text-amber-900" onClick={() => router.visit(minorsWithoutGuardian?.all_url ?? '/membros')}>
                                Ver todos
                            </Button>
                        )}
                    </Card>
                )}

                {/* Two column layout: Events + Activity */}
                <div className="grid gap-2 sm:gap-3 lg:grid-cols-2">
                    {/* Próximos Eventos */}
                    <Card className="p-2 sm:p-3 overflow-hidden">
                        <h2 className="text-sm sm:text-base font-semibold mb-2">Próximos Eventos</h2>
                        {recentEvents && recentEvents.length > 0 ? (
                            <div className="space-y-1.5 overflow-hidden">
                                {recentEvents.slice(0, 3).map(event => (
                                    <div key={event.id} className="flex items-center justify-between p-1.5 border rounded-lg gap-2 overflow-hidden">
                                        <div className="min-w-0 flex-1 overflow-hidden">
                                            <p className="font-medium text-xs truncate">{event.titulo}</p>
                                            <p className="text-[10px] xs:text-xs text-muted-foreground truncate">
                                                {new Date(event.data_inicio).toLocaleDateString('pt-PT')}
                                            </p>
                                        </div>
                                        <span className="text-xs px-1.5 py-0.5 bg-primary/10 text-primary rounded whitespace-nowrap flex-shrink-0">
                                            {event.tipo}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-center py-3 text-xs">Nenhum evento agendado</p>
                        )}
                        <Button variant="outline" className="w-full mt-2 h-7 text-xs" onClick={() => handleNavigate('events')}>
                            Ver Todos os Eventos
                        </Button>
                    </Card>

                    {/* Atividade Recente */}
                    <Card className="p-2 sm:p-3 overflow-hidden">
                        <h2 className="text-sm sm:text-base font-semibold mb-2">Atividade Recente</h2>
                        {recentActivity && recentActivity.length > 0 ? (
                            <div className="space-y-1.5 overflow-hidden">
                                {recentActivity.slice(0, 3).map(entry => {
                                    // Validar data
                                    const date = entry.data ? new Date(entry.data) : null;
                                    const isValidDate = date && !isNaN(date.getTime());
                                    const formattedDate = isValidDate 
                                        ? date.toLocaleDateString('pt-PT') 
                                        : 'Data inválida';
                                    
                                    // Validar valor
                                    const valor = typeof entry.valor === 'number' && !isNaN(entry.valor) 
                                        ? Math.abs(entry.valor) 
                                        : 0;

                                    return (
                                        <div key={entry.id} className="flex items-center justify-between p-1.5 border rounded-lg gap-2 overflow-hidden">
                                            <div className="flex-1 min-w-0 overflow-hidden">
                                                <p className="font-medium truncate text-xs">{entry.descricao}</p>
                                                <p className="text-[10px] xs:text-xs text-muted-foreground truncate">
                                                    {formattedDate}
                                                </p>
                                            </div>
                                            <span className={`font-semibold text-xs whitespace-nowrap flex-shrink-0 ${entry.tipo === 'receita' ? 'text-green-600' : 'text-red-600'}`}>
                                                {entry.tipo === 'receita' ? '+' : '-'}€{valor.toFixed(2)}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-center py-3 text-xs">Nenhuma transação registada</p>
                        )}
                        <Button variant="outline" className="w-full mt-2 h-7 text-xs" onClick={() => handleNavigate('financial')}>
                            Ver Financeiro
                        </Button>
                    </Card>
                </div>

                <UnreadAlertsList
                    unreadCount={communicationAlerts?.unreadCount ?? 0}
                    alerts={(communicationAlerts?.recent ?? []).filter((alert) => !alert.is_read)}
                />

                {/* Acesso Rápido */}
                <Card className="p-2 sm:p-3">
                    <h2 className="text-sm sm:text-base font-semibold mb-2">Acesso Rápido</h2>
                    <div className="grid gap-2 grid-cols-2 lg:grid-cols-4">
                        <Button variant="outline" className="h-auto py-2 sm:py-2.5 flex-col gap-1" onClick={() => handleNavigate('members')}>
                            <Users size={18} />
                            <span className="text-xs">Membros</span>
                        </Button>
                        <Button variant="outline" className="h-auto py-2 sm:py-2.5 flex-col gap-1" onClick={() => handleNavigate('sports')}>
                            <Trophy size={18} />
                            <span className="text-xs">Desportiva</span>
                        </Button>
                        <Button variant="outline" className="h-auto py-2 sm:py-2.5 flex-col gap-1" onClick={() => handleNavigate('events')}>
                            <CalendarBlank size={18} />
                            <span className="text-xs">Eventos</span>
                        </Button>
                        <Button variant="outline" className="h-auto py-2 sm:py-2.5 flex-col gap-1" onClick={() => handleNavigate('financial')}>
                            <CurrencyCircleDollar size={18} />
                            <span className="text-xs">Financeiro</span>
                        </Button>
                    </div>
                </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
