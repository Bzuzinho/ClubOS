import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarDays,
    Check,
    ChevronDown,
    ChevronRight,
    Clock3,
    FileText,
    MapPin,
    Megaphone,
    X,
} from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps as SharedPageProps } from '@/types';

interface PortalUserSummary {
    id: string | number;
    name: string;
    email?: string | null;
}

interface SelectedProfile {
    id: string;
    name: string;
    type: string;
    portal_href: string;
    viewing_self: boolean;
}

interface EventStatus {
    key: 'pending' | 'confirmed' | 'justified' | 'informative' | 'expired';
    label: string;
    tone: 'warning' | 'success' | 'info' | 'danger';
}

interface EventTypeBadge {
    key: string;
    label: string;
    badge_class: string;
}

interface EventCard {
    id: string;
    convocation_id: string | null;
    event_id: string;
    title: string;
    subtitle: string;
    source: 'convocation' | 'informative';
    status: EventStatus;
    type: EventTypeBadge;
    is_upcoming: boolean;
    date: {
        day_label: string;
        full_label: string;
        time_label: string;
    };
    location: {
        name: string;
        meeting_point: string | null;
    };
    group: {
        label: string;
    };
    trip: {
        meeting_point: string | null;
        departure_time: string | null;
        transport: string | null;
        return_estimate: string | null;
    };
    details: {
        time: string;
        location: string;
        meeting_time: string | null;
        meeting_point: string | null;
        transport: string | null;
        material: string | null;
        notes: string | null;
        participations: string | null;
        convocatoria_file: string | null;
        regulation_file: string | null;
    };
    actions: {
        can_confirm: boolean;
        can_justify: boolean;
        can_change_response: boolean;
    };
    justification: string | null;
    response_date: string | null;
}

interface PortalEventsProps extends Record<string, unknown> {
    user: PortalUserSummary;
    view_mode: 'personal' | 'family';
    selected_profile: SelectedProfile;
    summary: {
        pending_convocations: number;
        confirmed_events: number;
        upcoming_events: number;
        registered_competitions: number;
    };
    active_items: EventCard[];
    response_state: {
        pending_count: number;
        upcoming_deadlines: Array<{
            id: string;
            title: string;
            deadline_label: string;
        }>;
        alerts: string[];
    };
    recent_history: EventCard[];
    is_also_admin: boolean;
    has_family: boolean;
}

type PageProps = SharedPageProps<PortalEventsProps>;
type AgendaTab = 'upcoming' | 'respond' | 'history';

function statusClasses(status: EventStatus): string {
    switch (status.key) {
        case 'confirmed':
            return 'bg-emerald-50 text-emerald-700';
        case 'justified':
            return 'bg-slate-100 text-slate-600';
        case 'pending':
            return 'bg-amber-50 text-amber-700';
        case 'expired':
            return 'bg-rose-50 text-rose-700';
        default:
            return 'bg-blue-50 text-blue-700';
    }
}

function AgendaItem({
    card,
    expanded,
    onToggle,
    onConfirm,
    onJustify,
    onReset,
    justification,
    onJustificationChange,
    showResponseActions = true,
}: {
    card: EventCard;
    expanded: boolean;
    onToggle: () => void;
    onConfirm: () => void;
    onJustify: () => void;
    onReset: () => void;
    justification: string;
    onJustificationChange: (value: string) => void;
    showResponseActions?: boolean;
}) {
    return (
        <article className="overflow-hidden rounded-[20px] border border-slate-200 bg-white shadow-[0_6px_18px_rgba(15,23,42,0.04)]">
            <button type="button" onClick={onToggle} className="w-full p-3.5 text-left sm:p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                        <CalendarDays className="h-4 w-4" />
                        <span className="mt-0.5 text-[8px] font-semibold uppercase">{card.date.day_label}</span>
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-900">{card.title}</p>
                                <p className="mt-1 truncate text-xs text-slate-500">{card.type.label} · {card.group.label}</p>
                            </div>
                            <span className={`shrink-0 rounded-full px-2 py-1 text-[9px] font-semibold ${statusClasses(card.status)}`}>
                                {card.status.label}
                            </span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                            <span className="inline-flex items-center gap-1"><Clock3 className="h-3 w-3" /> {card.date.full_label}{card.date.time_label ? ` · ${card.date.time_label}` : ''}</span>
                            <span className="inline-flex items-center gap-1"><MapPin className="h-3 w-3" /> {card.location.name}</span>
                        </div>
                    </div>
                    {expanded ? <ChevronDown className="mt-1 h-4 w-4 shrink-0 text-slate-400" /> : <ChevronRight className="mt-1 h-4 w-4 shrink-0 text-slate-300" />}
                </div>
            </button>

            {expanded ? (
                <div className="border-t border-slate-100 bg-slate-50/60 px-3.5 pb-3.5 pt-3 sm:px-4 sm:pb-4">
                    <div className="grid gap-2 sm:grid-cols-2">
                        {card.details.meeting_time ? <Detail label="Hora de encontro" value={card.details.meeting_time} /> : null}
                        {card.details.meeting_point ? <Detail label="Ponto de encontro" value={card.details.meeting_point} /> : null}
                        {card.details.transport ? <Detail label="Transporte" value={card.details.transport} /> : null}
                        {card.details.material ? <Detail label="Material" value={card.details.material} /> : null}
                    </div>

                    {card.details.notes ? <p className="mt-3 text-xs leading-5 text-slate-600">{card.details.notes}</p> : null}

                    {(card.details.convocatoria_file || card.details.regulation_file) ? (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {card.details.convocatoria_file ? (
                                <a href={card.details.convocatoria_file} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-xs font-semibold text-blue-700">
                                    <FileText className="h-3.5 w-3.5" /> Convocatória
                                </a>
                            ) : null}
                            {card.details.regulation_file ? (
                                <a href={card.details.regulation_file} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-xs font-semibold text-blue-700">
                                    <FileText className="h-3.5 w-3.5" /> Regulamento
                                </a>
                            ) : null}
                        </div>
                    ) : null}

                    {showResponseActions && (card.actions.can_confirm || card.actions.can_justify || card.actions.can_change_response) ? (
                        <div className="mt-3 border-t border-slate-200 pt-3">
                            {card.actions.can_justify ? (
                                <textarea
                                    value={justification}
                                    onChange={(event) => onJustificationChange(event.target.value)}
                                    placeholder="Justificação, se não puderes estar presente"
                                    className="mb-2.5 min-h-20 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-300"
                                />
                            ) : null}
                            <div className="flex flex-wrap gap-2">
                                {card.actions.can_confirm ? (
                                    <button type="button" onClick={onConfirm} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                        <Check className="h-3.5 w-3.5" /> Vou
                                    </button>
                                ) : null}
                                {card.actions.can_justify ? (
                                    <button type="button" onClick={onJustify} className="inline-flex items-center gap-1.5 rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                                        <X className="h-3.5 w-3.5" /> Não vou
                                    </button>
                                ) : null}
                                {card.actions.can_change_response ? (
                                    <button type="button" onClick={onReset} className="rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-600">
                                        Alterar resposta
                                    </button>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </article>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl bg-white px-3 py-2.5">
            <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p>
            <p className="mt-1 text-xs font-medium text-slate-700">{value}</p>
        </div>
    );
}

export default function Events() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        clubSettings,
        view_mode,
        selected_profile,
        summary,
        active_items = [],
        response_state,
        recent_history = [],
        is_also_admin,
        has_family,
    } = props;

    const [activeTab, setActiveTab] = useState<AgendaTab>('upcoming');
    const [expandedCardId, setExpandedCardId] = useState<string | null>(null);
    const [justifications, setJustifications] = useState<Record<string, string>>({});

    const pendingItems = active_items.filter((card) => card.status.key === 'pending' || card.actions.can_confirm || card.actions.can_justify);
    const tabItems = activeTab === 'history' ? recent_history : activeTab === 'respond' ? pendingItems : active_items;

    const submitAction = (card: EventCard, action: 'confirm_presence' | 'justify_absence' | 'reset_response') => {
        if (!card.convocation_id) {
            return;
        }

        const justification = justifications[card.id]?.trim();
        if (action === 'justify_absence' && !justification) {
            setExpandedCardId(card.id);
            return;
        }

        const query = view_mode === 'family' ? '?scope=family' : '';
        router.patch(`/portal/eventos/${card.convocation_id}${query}`, {
            action,
            justification,
            scope: view_mode === 'family' ? 'family' : undefined,
        }, {
            preserveScroll: true,
        });
    };

    const tabs: Array<{ key: AgendaTab; label: string; count?: number }> = [
        { key: 'upcoming', label: 'Próximos' },
        { key: 'respond', label: 'Responder', count: response_state?.pending_count ?? summary.pending_convocations },
        { key: 'history', label: 'Histórico' },
    ];

    return (
        <>
            <Head title="Agenda" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="events"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[22px] bg-[linear-gradient(145deg,#0f62c8_0%,#0c4d9d_100%)] p-4 text-white shadow-[0_16px_32px_rgba(15,76,152,0.18)] sm:p-5">
                    <div className="flex min-w-0 items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-blue-100">Agenda</p>
                            <h1 className="mt-1 line-clamp-2 break-words text-lg font-semibold text-white">{selected_profile.name}</h1>
                            <p className="mt-1 break-words text-xs text-blue-100">{view_mode === 'family' ? 'Agenda familiar' : selected_profile.type}</p>
                        </div>
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-white">
                            <CalendarDays className="h-5 w-5" />
                        </span>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_22px_rgba(15,23,42,0.045)]">
                    <div className="grid grid-cols-3 border-b border-slate-100 px-2 pt-2">
                        {tabs.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setActiveTab(tab.key)}
                                className={`relative flex items-center justify-center gap-1.5 px-2 py-3 text-xs font-semibold transition ${
                                    activeTab === tab.key ? 'text-blue-700' : 'text-slate-500'
                                }`}
                            >
                                {tab.label}
                                {tab.count ? <span className="rounded-full bg-amber-50 px-1.5 py-0.5 text-[9px] text-amber-700">{tab.count}</span> : null}
                                {activeTab === tab.key ? <span className="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-blue-600" /> : null}
                            </button>
                        ))}
                    </div>

                    <div className="space-y-3 p-3.5 sm:p-4">
                        {activeTab === 'respond' && response_state?.alerts?.length > 0 ? (
                            <div className="rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800">
                                {response_state.alerts[0]}
                            </div>
                        ) : null}

                        {tabItems.length > 0 ? tabItems.map((card) => (
                            <AgendaItem
                                key={card.id}
                                card={card}
                                expanded={expandedCardId === card.id}
                                onToggle={() => setExpandedCardId((current) => current === card.id ? null : card.id)}
                                onConfirm={() => submitAction(card, 'confirm_presence')}
                                onJustify={() => submitAction(card, 'justify_absence')}
                                onReset={() => submitAction(card, 'reset_response')}
                                justification={justifications[card.id] ?? ''}
                                onJustificationChange={(value) => setJustifications((current) => ({ ...current, [card.id]: value }))}
                                showResponseActions={activeTab !== 'history'}
                            />
                        )) : (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center">
                                <Megaphone className="mx-auto h-5 w-5 text-slate-300" />
                                <p className="mt-2 text-sm font-medium text-slate-600">
                                    {activeTab === 'respond' ? 'Sem respostas pendentes.' : activeTab === 'history' ? 'Sem histórico disponível.' : 'Sem compromissos próximos.'}
                                </p>
                            </div>
                        )}
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}
