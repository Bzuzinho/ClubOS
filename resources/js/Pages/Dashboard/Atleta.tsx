import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronRight,
    CreditCard,
    FileText,
    MapPin,
    Megaphone,
    ShoppingBag,
} from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import { amountToneClass, formatSignedCurrency } from '@/lib/financialDisplay';
import { portalRoutes } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

interface Athlete {
    name: string;
    escalao: string | null;
    numero_socio: string | null;
    foto_perfil: string | null;
    estado: string | null;
    conta_corrente: string | number | null;
}

interface ProximoTreino {
    id: string;
    numero_treino: string | null;
    data: string | null;
    hora_inicio: string | null;
    hora_fim: string | null;
    local: string | null;
    tipo_treino: string | null;
    escaloes: string[];
    grupo_label: string | null;
}

interface ProximoEvento {
    id: string;
    titulo: string;
    data_inicio: string | null;
    hora_inicio: string | null;
    local: string | null;
    estado: string | null;
    tipo: string | null;
}

interface ResumoPortal {
    treinos_mes: number;
    eventos_proximos: number;
    conta_corrente: string | number | null;
    assiduidade_percent: number | null;
    treinos_agendados_mes: number;
}

interface AlertItem {
    id: string;
    title: string;
    message: string;
    type: 'info' | 'warning' | 'success' | 'error';
    link?: string | null;
    is_read: boolean;
    created_at: string;
}

interface AccessControlProps {
    currentUserType?: {
        id?: string;
        nome?: string | null;
        codigo?: string | null;
    } | null;
}

interface DashboardProps extends Record<string, unknown> {
    athlete: Athlete;
    proximo_treino?: ProximoTreino | null;
    proximos_eventos?: ProximoEvento[];
    resumo?: ResumoPortal;
    is_also_admin?: boolean;
    is_atleta?: boolean;
    has_family?: boolean;
    perfil_tipos?: string[];
    portal_context_label?: string | null;
    accessControl?: AccessControlProps;
    communicationAlerts?: {
        unreadCount: number;
        recent: AlertItem[];
    };
    clubSettings?: ClubSettingsProps;
}

type PageProps = SharedPageProps<DashboardProps>;

function parseNumber(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = typeof value === 'number' ? value : Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] || 'Utilizador';
}

function formatDate(dateStr: string | null | undefined, withWeekday = false): string {
    if (!dateStr) {
        return 'Data por definir';
    }

    const date = new Date(`${dateStr}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return 'Data por definir';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        ...(withWeekday ? { weekday: 'short' as const } : {}),
        day: '2-digit',
        month: 'short',
    }).format(date);
}

function formatTime(start?: string | null, end?: string | null): string {
    const startLabel = start?.slice(0, 5) || null;
    const endLabel = end?.slice(0, 5) || null;

    if (startLabel && endLabel) {
        return `${startLabel} – ${endLabel}`;
    }

    return startLabel || 'Horário por definir';
}

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length > 1) {
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
    }

    return (parts[0]?.slice(0, 2) || 'U').toUpperCase();
}

export default function Atleta() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        athlete,
        clubSettings,
        communicationAlerts,
        proximo_treino: nextTraining = null,
        proximos_eventos: upcomingEvents = [],
        resumo,
        is_also_admin = false,
        is_atleta = true,
        has_family = false,
        perfil_tipos = [],
        portal_context_label: portalContextLabel,
        accessControl,
    } = props;

    const currentProfile = portalContextLabel
        || accessControl?.currentUserType?.nome?.trim()
        || (is_atleta ? 'Atleta' : perfil_tipos[0] || 'Portal');
    const currentBalance = parseNumber(resumo?.conta_corrente ?? athlete.conta_corrente);
    const recentAlerts = (communicationAlerts?.recent ?? []).slice(0, 3);
    const nextEvent = upcomingEvents[0] ?? null;

    const visit = (href: string) => router.visit(href);

    const quickActions = [
        { key: 'payments', label: 'Pagamentos', icon: CreditCard, href: portalRoutes.payments },
        { key: 'agenda', label: 'Agenda', icon: CalendarDays, href: portalRoutes.events },
        { key: 'documents', label: 'Documentos', icon: FileText, href: portalRoutes.documents },
        { key: 'shop', label: 'Loja', icon: ShoppingBag, href: portalRoutes.shop },
    ];

    return (
        <>
            <Head title="Início" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="dashboard"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[22px] bg-[linear-gradient(145deg,#0f62c8_0%,#0c4d9d_100%)] px-4 py-4 text-white shadow-[0_16px_32px_rgba(15,76,152,0.18)] sm:px-5">
                    <div className="flex items-center gap-3">
                        {athlete.foto_perfil ? (
                            <img
                                src={athlete.foto_perfil}
                                alt={athlete.name}
                                className="h-12 w-12 rounded-2xl border border-white/20 object-cover"
                            />
                        ) : (
                            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/90 text-sm font-bold text-blue-700">
                                {getInitials(athlete.name)}
                            </div>
                        )}

                        <div className="min-w-0 flex-1">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Portal pessoal</p>
                            <h1 className="mt-1 truncate text-lg font-semibold">Olá, {firstName(athlete.name)}</h1>
                            <p className="mt-0.5 truncate text-xs text-blue-100">
                                {[athlete.escalao, currentProfile].filter(Boolean).join(' · ') || 'A tua área do clube'}
                            </p>
                        </div>
                    </div>
                </section>

                <section className="grid gap-3 md:grid-cols-2">
                    <button
                        type="button"
                        onClick={() => visit(portalRoutes.payments)}
                        className="rounded-[22px] border border-slate-200 bg-white p-4 text-left shadow-[0_8px_22px_rgba(15,23,42,0.05)] transition hover:border-blue-200"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Saldo da conta</p>
                                <p className={`mt-2 text-2xl font-semibold ${amountToneClass(currentBalance, 'debt')}`}>
                                    {formatSignedCurrency(currentBalance, 'debt')}
                                </p>
                                <p className="mt-1 text-xs text-slate-500">Conta corrente atual</p>
                            </div>
                            <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <CreditCard className="h-4 w-4" />
                            </span>
                        </div>
                    </button>

                    <button
                        type="button"
                        onClick={() => visit(portalRoutes.events)}
                        className="rounded-[22px] border border-slate-200 bg-white p-4 text-left shadow-[0_8px_22px_rgba(15,23,42,0.05)] transition hover:border-blue-200"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Próximo compromisso</p>
                                {nextTraining ? (
                                    <>
                                        <p className="mt-2 text-base font-semibold text-slate-900">
                                            {nextTraining.tipo_treino || nextTraining.numero_treino || 'Treino'}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {formatDate(nextTraining.data, true)} · {formatTime(nextTraining.hora_inicio, nextTraining.hora_fim)}
                                        </p>
                                        <p className="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                            <MapPin className="h-3 w-3" />
                                            <span className="truncate">{nextTraining.local || 'Local por definir'}</span>
                                        </p>
                                    </>
                                ) : nextEvent ? (
                                    <>
                                        <p className="mt-2 truncate text-base font-semibold text-slate-900">{nextEvent.titulo}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {formatDate(nextEvent.data_inicio, true)} · {nextEvent.hora_inicio?.slice(0, 5) || 'Horário por definir'}
                                        </p>
                                        <p className="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                            <MapPin className="h-3 w-3" />
                                            <span className="truncate">{nextEvent.local || 'Local por definir'}</span>
                                        </p>
                                    </>
                                ) : (
                                    <p className="mt-2 text-sm text-slate-500">Sem compromissos agendados.</p>
                                )}
                            </div>
                            <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <CalendarDays className="h-4 w-4" />
                            </span>
                        </div>
                    </button>
                </section>

                <section>
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h2 className="text-base font-semibold text-slate-900">Ações rápidas</h2>
                    </div>
                    <div className="grid grid-cols-4 gap-2.5">
                        {quickActions.map((action) => (
                            <button
                                key={action.key}
                                type="button"
                                onClick={() => visit(action.href)}
                                className="flex min-w-0 flex-col items-center gap-2 rounded-[18px] border border-slate-200 bg-white px-2 py-3 text-center shadow-[0_6px_18px_rgba(15,23,42,0.04)] transition hover:border-blue-200 hover:bg-blue-50/40"
                            >
                                <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <action.icon className="h-4 w-4" />
                                </span>
                                <span className="truncate text-[10px] font-semibold text-slate-700 sm:text-xs">{action.label}</span>
                            </button>
                        ))}
                    </div>
                </section>

                <section className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.05)]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Comunicados recentes</h2>
                            <p className="mt-0.5 text-xs text-slate-500">O que precisas de saber agora.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => visit(portalRoutes.communications)}
                            className="text-xs font-semibold text-blue-700"
                        >
                            Ver todos
                        </button>
                    </div>

                    <div className="mt-4 space-y-2.5">
                        {recentAlerts.length > 0 ? recentAlerts.map((alert) => (
                            <button
                                key={alert.id}
                                type="button"
                                onClick={() => visit(alert.link || portalRoutes.communications)}
                                className="flex w-full items-start gap-3 rounded-2xl border border-slate-200 px-3 py-3 text-left transition hover:border-blue-200 hover:bg-slate-50"
                            >
                                <span className="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <Megaphone className="h-4 w-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-semibold text-slate-900">{alert.title}</span>
                                    <span className="mt-1 line-clamp-2 block text-xs leading-5 text-slate-500">{alert.message}</span>
                                </span>
                                <ChevronRight className="mt-2 h-4 w-4 shrink-0 text-slate-300" />
                            </button>
                        )) : (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                Sem comunicados recentes.
                            </div>
                        )}
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}
