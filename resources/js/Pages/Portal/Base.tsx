import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronRight, CreditCard, FileText, IdCard, Megaphone } from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import { portalRoutes } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

interface AlertItem {
    id: string;
    title: string;
    message: string;
    type: 'info' | 'warning' | 'success' | 'error';
    link?: string | null;
    is_read: boolean;
    created_at: string;
}

interface BasePortalProps {
    user: {
        id: string | number;
        name: string;
        email?: string | null;
    };
    perfil_tipos: string[];
    is_also_admin: boolean;
    has_family?: boolean;
    clubSettings?: ClubSettingsProps;
    communicationAlerts?: {
        unreadCount: number;
        recent: AlertItem[];
    };
}

type PageProps = SharedPageProps<BasePortalProps>;

function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] || 'Utilizador';
}

export default function Base() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        clubSettings,
        user,
        is_also_admin,
        has_family = false,
        perfil_tipos = [],
        communicationAlerts,
    } = props;

    const quickActions = [
        { key: 'profile', label: 'Perfil', icon: IdCard, href: portalRoutes.profile },
        { key: 'payments', label: 'Pagamentos', icon: CreditCard, href: portalRoutes.payments },
        { key: 'events', label: 'Agenda', icon: CalendarDays, href: portalRoutes.events },
        { key: 'documents', label: 'Documentos', icon: FileText, href: portalRoutes.documents },
    ];
    const recentAlerts = (communicationAlerts?.recent ?? []).slice(0, 4);

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
                    <p className="text-[10px] font-semibold uppercase tracking-[0.15em] text-blue-100">Portal pessoal</p>
                    <h1 className="mt-1.5 text-lg font-semibold">Olá, {firstName(user.name)}</h1>
                    <p className="mt-1 text-xs text-blue-100">
                        {perfil_tipos.length > 0 ? perfil_tipos.join(' · ') : 'A tua área do clube'}
                    </p>
                </section>

                <section>
                    <h2 className="mb-3 text-base font-semibold text-slate-900">Ações rápidas</h2>
                    <div className="grid grid-cols-4 gap-2.5">
                        {quickActions.map((action) => (
                            <button
                                key={action.key}
                                type="button"
                                onClick={() => router.visit(action.href)}
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

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Comunicados recentes</h2>
                            <p className="mt-1 text-xs text-slate-500">Informação relevante do clube.</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.visit(portalRoutes.communications)}
                            className="text-xs font-semibold text-blue-700"
                        >
                            Ver todos
                        </button>
                    </div>

                    <div className="mt-4 divide-y divide-slate-100">
                        {recentAlerts.length > 0 ? recentAlerts.map((alert) => (
                            <button
                                key={alert.id}
                                type="button"
                                onClick={() => router.visit(alert.link || portalRoutes.communications)}
                                className="flex w-full items-start gap-3 py-3 text-left first:pt-0 last:pb-0"
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
                            <p className="text-sm text-slate-500">Sem comunicados recentes.</p>
                        )}
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}
