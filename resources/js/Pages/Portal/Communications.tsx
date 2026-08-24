import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    BellRing,
    CalendarDays,
    CircleAlert,
    CircleCheckBig,
    FileText,
    Inbox,
    MailOpen,
    ShieldAlert,
} from 'lucide-react';
import CommunicationsTab, {
    type MailboxFolder,
    type MemberOption,
    type ReceivedCommunication,
    type SentCommunication,
} from '@/Pages/Membros/CommunicationsTab';
import PortalKpiCard from '@/Components/Portal/PortalKpiCard';
import PortalSection from '@/Components/Portal/PortalSection';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps as SharedPageProps } from '@/types';

interface CommunicationCategory {
    key: 'financeiro' | 'treinos' | 'eventos' | 'geral' | 'documentos';
    label: string;
}

interface CommunicationState {
    key: 'read' | 'unread' | 'action_required';
    label: string;
}

interface CommunicationItem {
    id: string;
    title: string;
    description: string;
    body: string;
    date: string | null;
    category: CommunicationCategory;
    state: CommunicationState;
    source: 'alert' | 'internal';
    link: string | null;
    sender_name: string | null;
    can_mark_read: boolean;
    mark_read_payload: {
        source: 'alert' | 'internal';
        alert_id?: string | null;
        recipient_entry_id?: string | null;
    };
    actions: {
        can_open: boolean;
        can_open_detail: boolean;
        can_respond: boolean;
        detail_label: string;
    };
}

interface PortalCommunicationsProps {
    is_also_admin: boolean;
    has_family: boolean;
    communicationMembers: MemberOption[];
    internalCommunications: {
        received: ReceivedCommunication[];
        sent: SentCommunication[];
    };
    communicationState: {
        initialFolder: MailboxFolder;
        initialMessageId: string | null;
    };
    communications: {
        hero: {
            title: string;
            unread_label: string;
            action_label: string;
        };
        kpis: {
            unread: number;
            action_required: number;
            total_this_month: number;
            read: number;
        };
        inbox: {
            unread: number;
            action_required: number;
            total_this_month: number;
        };
        categories: Array<CommunicationCategory & { count: number }>;
        items: CommunicationItem[];
        empty_states: {
            unread: string;
            recent: string;
            pending: string;
        };
    };
}

type PageProps = SharedPageProps<PortalCommunicationsProps>;

function formatDate(date: string | null): string {
    if (!date) {
        return 'Sem data';
    }

    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return 'Sem data';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(parsed);
}

function EmptyState({ message }: { message: string }) {
    return (
        <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
            {message}
        </div>
    );
}

export default function Communications() {
    const {
        auth,
        clubSettings,
        is_also_admin,
        has_family,
        communicationMembers,
        internalCommunications,
        communicationState,
        communications,
    } = usePage<PageProps>().props;
    const [selectedCategory, setSelectedCategory] = useState<CommunicationCategory['key'] | null>(null);

    const pendingItems = useMemo(
        () => communications.items.filter((item) => item.state.key === 'action_required'),
        [communications.items],
    );
    const recentItems = useMemo(
        () => communications.items
            .filter((item) => selectedCategory === null || item.category.key === selectedCategory)
            .slice(0, 6),
        [communications.items, selectedCategory],
    );
    const selectedCategoryLabel = selectedCategory === null
        ? null
        : communications.categories.find((category) => category.key === selectedCategory)?.label ?? null;

    const visitItemTarget = (item: CommunicationItem) => {
        if (item.link) {
            if (/^https?:\/\//i.test(item.link)) {
                window.location.assign(item.link);
                return;
            }

            router.visit(item.link);
            return;
        }

        router.visit(route('portal.communications', {
            folder: 'received',
            message: item.id,
        }));
    };

    const openItem = (item: CommunicationItem) => {
        if (!item.can_mark_read) {
            visitItemTarget(item);
            return;
        }

        router.post(route('portal.communications.read'), item.mark_read_payload, {
            preserveScroll: true,
            preserveState: true,
            only: ['communications', 'internalCommunications', 'communicationAlerts', 'flash'],
            onSuccess: () => visitItemTarget(item),
        });
    };

    return (
        <>
            <Head title="Comunicações" />
            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="communications"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[20px] border border-blue-900/10 bg-[linear-gradient(180deg,rgba(24,102,191,0.96)_0%,rgba(18,79,152,0.92)_100%)] px-3.5 py-4 text-white shadow-[0_14px_28px_rgba(20,75,154,0.16)] sm:px-4 lg:px-5">
                    <div className="flex flex-col items-start gap-3">
                        <div className="max-w-2xl">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Portal</p>
                            <h2 className="mt-1.5 text-xl font-semibold">{communications.hero.title}</h2>
                            <div className="mt-2.5 flex flex-wrap gap-2">
                                <span className="rounded-full bg-white/15 px-2.5 py-0.5 text-[11px] font-semibold text-white">
                                    {communications.hero.unread_label}
                                </span>
                                <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-semibold text-blue-50">
                                    {communications.hero.action_label}
                                </span>
                            </div>
                        </div>

                        <div className="grid w-full max-w-[22rem] gap-2 sm:grid-cols-2">
                            <button
                                type="button"
                                onClick={() => router.visit(route('portal.communications', { folder: 'received' }))}
                                className="rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-left text-xs font-semibold text-white backdrop-blur transition hover:bg-white/15"
                            >
                                Abrir recebidas
                            </button>
                            <button
                                type="button"
                                onClick={() => router.post(route('portal.communications.markAllRead'), {}, {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['communications', 'internalCommunications', 'communicationAlerts', 'flash'],
                                })}
                                className="rounded-xl border border-white/20 bg-white px-3 py-2 text-left text-xs font-semibold text-blue-700 transition hover:bg-blue-50"
                            >
                                Marcar todos como lidos
                            </button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <PortalKpiCard label="Não lidos" value={String(communications.kpis.unread)} helper="por abrir" icon={MailOpen} />
                    <PortalKpiCard label="Ações pendentes" value={String(communications.kpis.action_required)} helper="exigem atenção" icon={ShieldAlert} />
                    <PortalKpiCard label="Total este mês" value={String(communications.kpis.total_this_month)} helper="recebidos" icon={CalendarDays} />
                    <PortalKpiCard label="Lidos" value={String(communications.kpis.read)} helper="já tratados" icon={CircleCheckBig} />
                </section>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(300px,0.85fr)] xl:items-start">
                    <PortalSection
                        title="Comunicações"
                        description="Caixa de entrada e enviados do utilizador autenticado, com resposta interna diretamente na app."
                    >
                        <CommunicationsTab
                            members={communicationMembers}
                            communications={internalCommunications}
                            initialFolder={communicationState.initialFolder}
                            initialMessageId={communicationState.initialMessageId}
                            ownerLabel="o utilizador autenticado"
                            context="portal"
                        />
                    </PortalSection>

                    <div className="space-y-4">
                        <PortalSection title="Caixa de entrada" description="Resumo rápido da atividade recente.">
                            <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
                                <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex items-center gap-2 text-slate-700">
                                        <Inbox className="h-4 w-4" />
                                        <p className="text-sm font-semibold">Não lidos</p>
                                    </div>
                                    <p className="mt-3 text-2xl font-semibold text-slate-900">{communications.inbox.unread}</p>
                                </div>
                                <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex items-center gap-2 text-slate-700">
                                        <CircleAlert className="h-4 w-4" />
                                        <p className="text-sm font-semibold">Ações necessárias</p>
                                    </div>
                                    <p className="mt-3 text-2xl font-semibold text-slate-900">{communications.inbox.action_required}</p>
                                </div>
                                <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex items-center gap-2 text-slate-700">
                                        <BellRing className="h-4 w-4" />
                                        <p className="text-sm font-semibold">Total do mês</p>
                                    </div>
                                    <p className="mt-3 text-2xl font-semibold text-slate-900">{communications.inbox.total_this_month}</p>
                                </div>
                            </div>
                        </PortalSection>

                        <PortalSection title="Categorias" description="Filtre a atividade recente por área.">
                            <div className="space-y-2">
                                <button
                                    type="button"
                                    aria-pressed={selectedCategory === null}
                                    onClick={() => setSelectedCategory(null)}
                                    className={`flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left transition ${selectedCategory === null ? 'border-blue-200 bg-blue-50' : 'border-slate-200 bg-slate-50/70 hover:border-slate-300 hover:bg-white'}`}
                                >
                                    <span className="text-sm font-semibold text-slate-800">Todas</span>
                                    <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">{communications.items.length}</span>
                                </button>
                                {communications.categories.map((category) => (
                                    <button
                                        key={category.key}
                                        type="button"
                                        aria-pressed={selectedCategory === category.key}
                                        onClick={() => setSelectedCategory(category.key)}
                                        className={`flex w-full items-center justify-between rounded-2xl border px-4 py-3 text-left transition ${selectedCategory === category.key ? 'border-blue-200 bg-blue-50' : 'border-slate-200 bg-slate-50/70 hover:border-slate-300 hover:bg-white'}`}
                                    >
                                        <span className="text-sm font-semibold text-slate-800">{category.label}</span>
                                        <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">{category.count}</span>
                                    </button>
                                ))}
                            </div>
                        </PortalSection>

                        <PortalSection
                            title={selectedCategoryLabel ? `Recentes — ${selectedCategoryLabel}` : 'Recentes'}
                            description="Abra a comunicação ou a área da aplicação associada."
                        >
                            {recentItems.length === 0 ? (
                                <EmptyState message={communications.empty_states.recent} />
                            ) : (
                                <div className="space-y-2">
                                    {recentItems.map((item) => (
                                        <button
                                            key={`recent-${item.id}`}
                                            type="button"
                                            onClick={() => openItem(item)}
                                            className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-blue-200 hover:bg-blue-50/30"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <p className="text-sm font-semibold text-slate-900">{item.title}</p>
                                                <span className="shrink-0 text-[11px] font-semibold text-slate-500">{item.state.label}</span>
                                            </div>
                                            {item.description && (
                                                <p className="mt-1 line-clamp-2 text-xs text-slate-600">{item.description}</p>
                                            )}
                                            <p className="mt-2 text-[11px] font-medium text-slate-500">{item.category.label} · {formatDate(item.date)}</p>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </PortalSection>

                        <PortalSection title="Ações pendentes" description="Itens que exigem resposta ou detalhe adicional.">
                            {pendingItems.length === 0 ? (
                                <EmptyState message={communications.empty_states.pending} />
                            ) : (
                                <div className="space-y-2">
                                    {pendingItems.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => openItem(item)}
                                            className="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-left transition hover:bg-amber-100"
                                        >
                                            <p className="text-sm font-semibold text-amber-900">{item.title}</p>
                                            <p className="mt-1 text-xs text-amber-700">{item.category.label} · {formatDate(item.date)}</p>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </PortalSection>

                        <PortalSection title="Acesso rápido" description="Atalhos para treinos, eventos e documentos relacionados.">
                            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                                <button
                                    type="button"
                                    onClick={() => router.visit('/portal/eventos')}
                                    className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    Agenda e treinos
                                    <CalendarDays className="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => router.visit('/portal/documentos')}
                                    className="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    Documentos
                                    <FileText className="h-4 w-4" />
                                </button>
                            </div>
                        </PortalSection>
                    </div>
                </div>
            </PortalLayout>
        </>
    );
}
