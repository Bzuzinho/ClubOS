import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    CircleDot,
    Clock3,
    FileText,
    MapPin,
    Megaphone,
    ShieldAlert,
    Trophy,
} from 'lucide-react';
import PortalKpiCard from '@/Components/Portal/PortalKpiCard';
import PortalSection from '@/Components/Portal/PortalSection';
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
    hero_card: EventCard | null;
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

export default function Events() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        clubSettings,
        view_mode,
        selected_profile,
        summary,
        hero_card,
        active_items,
        response_state,
        recent_history,
        is_also_admin,
        has_family,
    } = props;

    const [expandedCardId, setExpandedCardId] = useState<string | null>(hero_card?.id ?? active_items[0]?.id ?? null);
    const [justifyingCardId, setJustifyingCardId] = useState<string | null>(null);
    const [justifications, setJustifications] = useState<Record<string, string>>({});

    const submitAction = (card: EventCard, action: 'confirm_presence' | 'justify_absence' | 'reset_response') => {
        if (!card.convocation_id) {
            return;
        }

        const justification = justifications[card.id]?.trim();

        if (action === 'justify_absence' && !justification) {
            setJustifyingCardId(card.id);
            setExpandedCardId(card.id);
            return;
        }

        const query = view_mode === 'family' ? '?scope=family' : '';
        router.patch(
            `/portal/eventos/${card.convocation_id}${query}`,
            {
                action,
                justification,
                scope: view_mode === 'family' ? 'family' : undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (action === 'justify_absence') {
                        setJustifyingCardId(null);
                    }
                },
            },
        );
    };

    const highlightCard = hero_card ?? active_items[0] ?? null;

    return (
        <>
            <Head title="Convocatórias e Eventos" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="events"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[20px] border border-blue-900/10 bg-[linear-gradient(180deg,rgba(30,98,193,0.96)_0%,rgba(20,75,154,0.92)_100%)] px-3.5 py-4 text-white shadow-[0_14px_28px_rgba(20,75,154,0.16)] sm:px-4 lg:px-5">
                    <div className="flex flex-col items-start gap-3">
                        <div className="max-w-2xl">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Portal</p>
                            <h2 className="mt-1.5 text-xl font-semibold">Convocatórias e Eventos</h2>
                            <p className="mt-2 text-xs text-blue-100">
                                {view_mode === 'family' ? 'A acompanhar:' : 'Perfil ativo:'} <span className="font-semibold text-white">{selected_profile.name}</span> · {selected_profile.type}
                            </p>
                        </div>

                        <div className="w-full max-w-[22rem] rounded-[18px] border border-white/15 bg-white/10 p-3.5 backdrop-blur">
                            {highlightCard ? (
                                <>
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-blue-100">
                                                {highlightCard.status.key === 'pending' ? 'Convocatória pendente' : 'Próximo destaque'}
                                            </p>
                                            <h3 className="mt-1.5 text-base font-semibold text-white">{highlightCard.title}</h3>
                                        </div>
                                        <StatusBadge status={highlightCard.status} />
                                    </div>
                                    <p className="mt-2.5 text-xs text-blue-50">{highlightCard.date.full_label}</p>
                                    <p className="mt-1 text-xs text-blue-50">{highlightCard.location.name}</p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {highlightCard.actions.can_confirm ? (
                                            <button
                                                type="button"
                                                onClick={() => submitAction(highlightCard, 'confirm_presence')}
                                                className="inline-flex items-center justify-center rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-50"
                                            >
                                                Confirmar presença
                                            </button>
                                        ) : null}
                                        <button
                                            type="button"
                                            onClick={() => setExpandedCardId(highlightCard.id)}
                                            className="inline-flex items-center justify-center rounded-xl border border-white/30 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/10"
                                        >
                                            Ver detalhes
                                        </button>
                                        {highlightCard.actions.can_justify ? (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setExpandedCardId(highlightCard.id);
                                                    setJustifyingCardId(highlightCard.id);
                                                }}
                                                className="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/15"
                                            >
                                                Justificar ausência
                                            </button>
                                        ) : null}
                                    </div>
                                </>
                            ) : (
                                <p className="text-xs text-blue-50">Sem eventos próximos.</p>
                            )}
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <PortalKpiCard label="Convocatórias pendentes" value={String(summary.pending_convocations)} helper="aguardam resposta" icon={Megaphone} />
                    <PortalKpiCard label="Eventos confirmados" value={String(summary.confirmed_events)} helper="com presença validada" icon={CheckCircle2} />
                    <PortalKpiCard label="Próximos eventos" value={String(summary.upcoming_events)} helper="agenda visível" icon={CalendarDays} />
                    <PortalKpiCard label="Provas inscritas" value={String(summary.registered_competitions)} helper="provas e competições" icon={Trophy} />
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.9fr)] xl:items-start">
                    <div className="space-y-4">
                        <PortalSection title="Convocatórias ativas" description="Agenda do utilizador final, sem ferramentas administrativas.">
                            {active_items.length > 0 ? (
                                <div className="grid gap-3 md:grid-cols-2">
                                    {active_items.map((card) => {
                                        const isExpanded = expandedCardId === card.id;
                                        const isJustifying = justifyingCardId === card.id;

                                        return (
                                            <article key={card.id} className="rounded-[24px] border border-slate-200 bg-slate-50/80 p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{card.date.day_label}</p>
                                                        <h3 className="mt-2 text-base font-semibold text-slate-900">{card.title}</h3>
                                                        {view_mode === 'family' ? (
                                                            <p className="mt-1 text-xs font-medium text-slate-500">{card.subtitle}</p>
                                                        ) : null}
                                                        <p className="mt-1 text-sm text-slate-500">{card.location.name}</p>
                                                    </div>
                                                    <StatusBadge status={card.status} />
                                                </div>

                                                <div className="mt-4 flex flex-wrap gap-2">
                                                    <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ${card.type.badge_class}`}>
                                                        {card.type.label}
                                                    </span>
                                                    <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600">
                                                        {card.group.label}
                                                    </span>
                                                    {card.details.participations ? (
                                                        <span className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600">
                                                            {card.details.participations}
                                                        </span>
                                                    ) : null}
                                                </div>

                                                <div className="mt-4 space-y-2 text-sm text-slate-600">
                                                    <InlineMeta icon={Clock3} label={card.date.time_label} />
                                                    <InlineMeta icon={MapPin} label={card.location.meeting_point || card.location.name} />
                                                    <InlineMeta icon={CircleDot} label={card.trip.transport || 'Sem transporte definido'} />
                                                </div>

                                                <div className="mt-4 flex flex-wrap gap-2">
                                                    {card.actions.can_confirm ? (
                                                        <ActionButton tone="primary" onClick={() => submitAction(card, 'confirm_presence')}>
                                                            Confirmar
                                                        </ActionButton>
                                                    ) : null}
                                                    <ActionButton tone="secondary" onClick={() => setExpandedCardId(isExpanded ? null : card.id)}>
                                                        {isExpanded ? 'Ocultar detalhes' : 'Ver detalhes'}
                                                    </ActionButton>
                                                    {card.actions.can_justify ? (
                                                        <ActionButton
                                                            tone="danger"
                                                            onClick={() => {
                                                                setExpandedCardId(card.id);
                                                                setJustifyingCardId(card.id);
                                                            }}
                                                        >
                                                            Justificar ausência
                                                        </ActionButton>
                                                    ) : null}
                                                    {card.actions.can_change_response ? (
                                                        <ActionButton tone="muted" onClick={() => submitAction(card, 'reset_response')}>
                                                            Alterar resposta
                                                        </ActionButton>
                                                    ) : null}
                                                </div>

                                                {isExpanded ? (
                                                    <div className="mt-4 rounded-[20px] border border-slate-200 bg-white p-4">
                                                        <dl className="grid gap-3 text-sm text-slate-600 sm:grid-cols-2">
                                                            <DetailItem label="Hora de concentração" value={card.details.meeting_time || 'Por definir'} />
                                                            <DetailItem label="Local" value={card.details.location} />
                                                            <DetailItem label="Transporte" value={card.details.transport || 'Sem transporte definido'} />
                                                            <DetailItem label="Material a levar" value={card.details.material || 'Sem indicação adicional'} />
                                                            <DetailItem label="Observações" value={card.details.notes || 'Sem observações adicionais'} />
                                                            <DetailItem label="Estado" value={card.status.label} />
                                                        </dl>
                                                        <EventFiles card={card} />

                                                        {card.justification ? (
                                                            <p className="mt-3 rounded-2xl bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                                                Justificação enviada: {card.justification}
                                                            </p>
                                                        ) : null}

                                                        {isJustifying ? (
                                                            <div className="mt-4 space-y-3">
                                                                <label className="block text-sm font-medium text-slate-700" htmlFor={`justification-${card.id}`}>
                                                                    Motivo da ausência
                                                                </label>
                                                                <textarea
                                                                    id={`justification-${card.id}`}
                                                                    value={justifications[card.id] ?? ''}
                                                                    onChange={(event) => setJustifications((current) => ({ ...current, [card.id]: event.target.value }))}
                                                                    rows={3}
                                                                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 outline-none transition focus:border-blue-300 focus:bg-white"
                                                                    placeholder="Indica o motivo para ausência"
                                                                />
                                                                <div className="flex flex-wrap gap-2">
                                                                    <ActionButton tone="danger" onClick={() => submitAction(card, 'justify_absence')}>
                                                                        Enviar justificação
                                                                    </ActionButton>
                                                                    <ActionButton tone="muted" onClick={() => setJustifyingCardId(null)}>
                                                                        Cancelar
                                                                    </ActionButton>
                                                                </div>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </article>
                                        );
                                    })}
                                </div>
                            ) : (
                                <EmptyState icon={Megaphone} label="Sem convocatórias pendentes." />
                            )}
                        </PortalSection>
                    </div>

                    <div className="space-y-4">
                        <PortalSection title="Estado das respostas" description="Pendências, prazos e alertas importantes.">
                            <div className="space-y-3">
                                <ResponseMetric icon={ShieldAlert} label="Convocatórias pendentes" value={String(response_state.pending_count)} />
                                {response_state.upcoming_deadlines.length > 0 ? (
                                    <div className="space-y-2">
                                        {response_state.upcoming_deadlines.map((deadline) => (
                                            <div key={deadline.id} className="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2">
                                                <p className="text-sm font-semibold text-amber-900">{deadline.title}</p>
                                                <p className="text-xs text-amber-700">Prazo implícito: {deadline.deadline_label}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500">Sem convocatórias pendentes.</p>
                                )}
                                <div className="space-y-2">
                                    {response_state.alerts.map((alert) => (
                                        <div key={alert} className="flex items-start gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                            <AlertTriangle className="mt-0.5 h-4 w-4 text-amber-500" />
                                            <span>{alert}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </PortalSection>

                        <PortalSection title="Histórico de convocatórias e eventos" description="Consulta de todos os eventos passados e respetivos detalhes.">
                            {recent_history.length > 0 ? (
                                <div className="space-y-2">
                                    {recent_history.map((card) => {
                                        const isExpanded = expandedCardId === card.id;

                                        return (
                                            <article key={card.id} className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-900">{card.title}</p>
                                                        {view_mode === 'family' ? (
                                                            <p className="mt-1 text-xs font-medium text-slate-500">{card.subtitle}</p>
                                                        ) : null}
                                                        <p className="mt-1 text-xs text-slate-500">{card.date.full_label} · {card.location.name}</p>
                                                    </div>
                                                    <StatusBadge status={card.status} compact />
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => setExpandedCardId(isExpanded ? null : card.id)}
                                                    className="mt-3 text-xs font-semibold text-blue-700"
                                                >
                                                    {isExpanded ? 'Ocultar detalhes' : 'Ver detalhes'}
                                                </button>
                                                {isExpanded ? (
                                                    <div className="mt-3 rounded-2xl border border-slate-200 bg-white p-3">
                                                        <dl className="grid gap-3 sm:grid-cols-2">
                                                            <DetailItem label="Data e hora" value={`${card.date.full_label} · ${card.details.time}`} />
                                                            <DetailItem label="Local" value={card.details.location} />
                                                            <DetailItem label="Ponto de encontro" value={card.details.meeting_point || 'Não definido'} />
                                                            <DetailItem label="Transporte" value={card.details.transport || 'Sem transporte definido'} />
                                                            <DetailItem label="Material" value={card.details.material || 'Sem indicação adicional'} />
                                                            <DetailItem label="Observações" value={card.details.notes || 'Sem observações adicionais'} />
                                                        </dl>
                                                        <EventFiles card={card} />
                                                    </div>
                                                ) : null}
                                            </article>
                                        );
                                    })}
                                </div>
                            ) : (
                                <EmptyState icon={FileText} label="Sem histórico recente." />
                            )}
                        </PortalSection>
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}

const statusToneClasses: Record<EventStatus['tone'], string> = {
    warning: 'border-amber-200 bg-amber-50 text-amber-700',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    info: 'border-sky-200 bg-sky-50 text-sky-700',
    danger: 'border-rose-200 bg-rose-50 text-rose-700',
};

function StatusBadge({ status, compact = false }: { status: EventStatus; compact?: boolean }) {
    return (
        <span
            className={`inline-flex items-center rounded-full border font-semibold ${statusToneClasses[status.tone]} ${compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1 text-xs'}`}
        >
            {status.label}
        </span>
    );
}

function InlineMeta({ icon: Icon, label }: { icon: typeof Clock3; label: string }) {
    return (
        <div className="flex items-center gap-2">
            <Icon className="h-4 w-4 text-slate-400" />
            <span>{label}</span>
        </div>
    );
}

function DetailItem({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{label}</dt>
            <dd className="mt-1 text-sm text-slate-700">{value}</dd>
        </div>
    );
}

function resolveEventFileUrl(path: string): string {
    if (/^https?:\/\//i.test(path) || path.startsWith('/')) {
        return path;
    }

    return `/storage/${path.replace(/^storage\//, '')}`;
}

function EventFiles({ card }: { card: EventCard }) {
    const files = [
        card.details.convocatoria_file ? { label: 'Abrir convocatória', path: card.details.convocatoria_file } : null,
        card.details.regulation_file ? { label: 'Abrir regulamento', path: card.details.regulation_file } : null,
    ].filter((file): file is { label: string; path: string } => file !== null);

    if (files.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 flex flex-wrap gap-2">
            {files.map((file) => (
                <a
                    key={file.label}
                    href={resolveEventFileUrl(file.path)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                >
                    <FileText className="h-3.5 w-3.5" />
                    {file.label}
                </a>
            ))}
        </div>
    );
}

function ResponseMetric({ icon: Icon, label, value }: { icon: typeof Clock3; label: string; value: string }) {
    return (
        <div className="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-2xl bg-white text-blue-700">
                <Icon className="h-4 w-4" />
            </div>
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{label}</p>
                <p className="mt-1 text-sm font-medium text-slate-800">{value}</p>
            </div>
        </div>
    );
}

function EmptyState({ icon: Icon, label }: { icon: typeof Clock3; label: string }) {
    return (
        <div className="rounded-[24px] border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
            <Icon className="mx-auto h-5 w-5 text-slate-400" />
            <p className="mt-2">{label}</p>
        </div>
    );
}

function ActionButton({
    children,
    onClick,
    tone,
}: {
    children: string;
    onClick: () => void;
    tone: 'primary' | 'secondary' | 'danger' | 'muted';
}) {
    const classes = {
        primary: 'bg-blue-600 text-white hover:bg-blue-700',
        secondary: 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-100',
        danger: 'bg-rose-50 text-rose-700 hover:bg-rose-100',
        muted: 'bg-slate-100 text-slate-700 hover:bg-slate-200',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center justify-center rounded-2xl px-3.5 py-2 text-sm font-semibold transition ${classes[tone]}`}
        >
            {children}
        </button>
    );
}
