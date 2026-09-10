import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    Activity,
    CalendarDays,
    CheckCircle2,
    CircleGauge,
    Clock3,
    Dumbbell,
    MapPin,
    NotebookPen,
    Ruler,
    Waves,
    XCircle,
} from 'lucide-react';
import PortalKpiCard from '@/Components/Portal/PortalKpiCard';
import PortalSection from '@/Components/Portal/PortalSection';
import PortalLayout from '@/Layouts/PortalLayout';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

interface PortalUserSummary {
    id: string | number;
    name: string;
    email?: string | null;
}

interface TrainingStatus {
    key: 'pending' | 'confirmed' | 'justified' | 'completed' | 'incomplete' | 'not_completed' | 'absent';
    label: string;
    tone: 'neutral' | 'info' | 'warning' | 'success' | 'danger';
}

interface TrainingPermissions {
    can_confirm_presence: boolean;
    can_justify_absence: boolean;
    can_correct_volume: boolean;
}

interface TrainingSeriesRow {
    id: string;
    ordem: number | null;
    descricao_texto: string | null;
    distancia_total_m: number | null;
    zona_intensidade: string | null;
    estilo: string | null;
    repeticoes: number | null;
    intervalo: string | null;
    observacoes: string | null;
}

interface TrainingCard {
    id: string;
    training_id: string;
    date: string | null;
    date_label: string;
    weekday_label: string | null;
    time_label: string;
    location: string;
    title: string;
    description: string | null;
    group_label: string;
    planned_meters: number | null;
    planned_meters_label: string;
    final_meters: number | null;
    final_meters_label: string;
    completion_percent: number | null;
    coach_note: string | null;
    plan_note: string | null;
    series: TrainingSeriesRow[];
    status: TrainingStatus;
    permissions: TrainingPermissions;
}

interface CoachNote {
    id: string;
    date_label: string;
    context: string;
    note: string;
}

interface TrainingsPortalProps {
    user: PortalUserSummary;
    perfil_tipos: string[];
    is_also_admin: boolean;
    is_athlete: boolean;
    has_family?: boolean;
    clubSettings?: ClubSettingsProps;
    communicationAlerts?: {
        unreadCount: number;
    };
    summary: {
        trainings_this_month: number;
        confirmed_presence: number;
        volume_km: string;
        attendance_rate: number;
    };
    next_training: TrainingCard | null;
    upcoming_trainings: TrainingCard[];
    latest_training: TrainingCard | null;
    history: TrainingCard[];
    latest_coach_note: CoachNote | null;
}

type PageProps = SharedPageProps<TrainingsPortalProps>;

const statusToneClasses: Record<TrainingStatus['tone'], string> = {
    neutral: 'border-slate-200 bg-slate-100 text-slate-600',
    info: 'border-sky-200 bg-sky-50 text-sky-700',
    warning: 'border-amber-200 bg-amber-50 text-amber-700',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    danger: 'border-rose-200 bg-rose-50 text-rose-700',
};

export default function Trainings() {
    const { props } = usePage<PageProps>();
    const { auth, clubSettings, has_family = false } = props;
    const {
        user,
        perfil_tipos = [],
        communicationAlerts,
        is_also_admin,
        is_athlete,
        summary,
        next_training,
        upcoming_trainings = [],
        latest_training,
        history = [],
        latest_coach_note,
    } = props;

    const [expandedTrainingId, setExpandedTrainingId] = useState<string | null>(next_training?.id ?? null);
    const [editingTrainingId, setEditingTrainingId] = useState<string | null>(null);
    const scrollTargetTrainingId = useRef<string | null>(null);
    const volumeForm = useForm<{ action: string; volume_real_m: string }>({
        action: 'correct_volume',
        volume_real_m: '',
    });

    useEffect(() => {
        if (!expandedTrainingId || scrollTargetTrainingId.current !== expandedTrainingId) {
            return;
        }

        const target = document.getElementById(`training-detail-${expandedTrainingId}`)
            ?? document.getElementById(`training-card-${expandedTrainingId}`);

        target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        scrollTargetTrainingId.current = null;
    }, [expandedTrainingId]);

    const submitTrainingAction = (training: TrainingCard, action: 'confirm_presence' | 'justify_absence') => {
        router.patch(
            `/portal/treinos/${training.id}`,
            { action },
            { preserveScroll: true },
        );
    };

    const startVolumeEdit = (training: TrainingCard) => {
        setEditingTrainingId(training.id);
        volumeForm.setData({ action: 'correct_volume', volume_real_m: training.final_meters ? String(training.final_meters) : '' });
        volumeForm.clearErrors();
    };

    const submitVolumeCorrection = (trainingId: string) => {
        volumeForm.patch(`/portal/treinos/${trainingId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingTrainingId(null);
                volumeForm.reset('volume_real_m');
            },
        });
    };

    const toggleTrainingDetail = (trainingId: string, shouldScroll = true) => {
        scrollTargetTrainingId.current = shouldScroll ? trainingId : null;
        setExpandedTrainingId((current) => current === trainingId ? null : trainingId);
    };

    return (
        <>
            <Head title="Treinos" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="trainings"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[20px] border border-blue-900/10 bg-[linear-gradient(180deg,rgba(29,78,216,0.95)_0%,rgba(30,64,175,0.92)_100%)] px-3.5 py-4 text-white shadow-[0_14px_28px_rgba(30,64,175,0.16)] sm:px-4 lg:px-5">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Treinos</p>
                    <div className="mt-1.5 flex flex-col items-start gap-3">
                        <div className="max-w-2xl">
                            <h2 className="text-xl font-semibold">Treinos</h2>
                        </div>

                        {next_training ? (
                            <div className="w-full max-w-[22rem] rounded-[18px] border border-white/15 bg-white/10 px-3.5 py-3 backdrop-blur">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-blue-100">Próximo treino</p>
                                <p className="mt-1.5 text-base font-semibold">{formatHeroDate(next_training.weekday_label, next_training.time_label)}</p>
                                <p className="mt-1 text-xs text-blue-50">{next_training.location}</p>
                                <p className="mt-1 text-xs text-blue-50">{next_training.group_label}</p>
                                <p className="mt-1 text-sm text-white">{next_training.title}</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {next_training.permissions.can_confirm_presence ? (
                                        <button
                                            type="button"
                                            onClick={() => submitTrainingAction(next_training, 'confirm_presence')}
                                            className="inline-flex items-center justify-center rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-50"
                                        >
                                            Confirmar presença
                                        </button>
                                    ) : null}
                                    {next_training.permissions.can_justify_absence ? (
                                        <button
                                            type="button"
                                            onClick={() => submitTrainingAction(next_training, 'justify_absence')}
                                            className="inline-flex items-center justify-center rounded-xl border border-rose-200/70 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                                        >
                                            Avisar ausência
                                        </button>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={() => toggleTrainingDetail(next_training.id)}
                                        className="inline-flex items-center justify-center rounded-xl border border-white/30 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/10"
                                    >
                                        Ver plano do treino
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <div className="rounded-[18px] border border-white/15 bg-white/10 px-3.5 py-3 text-xs text-blue-50 backdrop-blur">
                                Sem treinos agendados.
                            </div>
                        )}
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <PortalKpiCard label="Treinos do mês" value={String(summary.trainings_this_month)} helper="agendados" icon={CalendarDays} />
                    <PortalKpiCard label="Presenças confirmadas" value={String(summary.confirmed_presence)} helper="estado atual" icon={CheckCircle2} />
                    <PortalKpiCard label="Volume treinado" value={`${summary.volume_km} km`} helper="metros finais" icon={Waves} />
                    <PortalKpiCard label="Assiduidade" value={`${summary.attendance_rate}%`} helper="registos fechados" icon={Activity} />
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.9fr)] xl:items-start">
                    <div className="space-y-4">
                        <PortalSection title="Próximos treinos" description="Agenda confirmável do atleta, sem ações administrativas.">
                            {upcoming_trainings.length > 0 ? (
                                <div className="grid gap-3 md:grid-cols-2">
                                    {upcoming_trainings.map((training) => (
                                        <article id={`training-card-${training.id}`} key={training.id} className="rounded-[24px] border border-slate-200 bg-slate-50/70 p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{training.date_label}</p>
                                                    <h3 className="mt-2 text-base font-semibold text-slate-900">{training.title}</h3>
                                                    <p className="mt-1 text-sm text-slate-500">{training.group_label}</p>
                                                </div>
                                                <StatusBadge status={training.status} />
                                            </div>

                                            <div className="mt-4 space-y-2 text-sm text-slate-600">
                                                <InlineMeta icon={Clock3} label={training.time_label} />
                                                <InlineMeta icon={MapPin} label={training.location} />
                                                <InlineMeta icon={Ruler} label={`Metros previstos: ${training.planned_meters_label}`} />
                                            </div>

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                {training.permissions.can_confirm_presence ? (
                                                    <ActionButton onClick={() => submitTrainingAction(training, 'confirm_presence')}>
                                                        Confirmar presença
                                                    </ActionButton>
                                                ) : null}
                                                <SecondaryButton onClick={() => toggleTrainingDetail(training.id)}>
                                                    Ver treino
                                                </SecondaryButton>
                                                {training.permissions.can_justify_absence ? (
                                                    <SecondaryButton onClick={() => submitTrainingAction(training, 'justify_absence')} tone="danger">
                                                        Avisar ausência
                                                    </SecondaryButton>
                                                ) : null}
                                            </div>

                                            {expandedTrainingId === training.id ? (
                                                <TrainingDetailPanel
                                                    training={training}
                                                    detailId={`training-detail-${training.id}`}
                                                    editingTrainingId={editingTrainingId}
                                                    volumeValue={volumeForm.data.volume_real_m}
                                                    volumeError={volumeForm.errors.volume_real_m}
                                                    volumeProcessing={volumeForm.processing}
                                                    onVolumeChange={(value) => volumeForm.setData('volume_real_m', value)}
                                                    onStartVolumeEdit={() => startVolumeEdit(training)}
                                                    onCancelVolumeEdit={() => setEditingTrainingId(null)}
                                                    onSubmitVolume={() => submitVolumeCorrection(training.id)}
                                                />
                                            ) : null}
                                        </article>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState message="Sem treinos agendados." />
                            )}
                        </PortalSection>

                        <PortalSection title="Último treino" description="Último registo concluído ou acompanhado neste portal.">
                            {latest_training ? (
                                <article className="rounded-[24px] border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge status={latest_training.status} />
                                                <span className="text-xs font-medium uppercase tracking-[0.16em] text-slate-400">{latest_training.date_label}</span>
                                            </div>
                                            <h3 className="mt-3 text-lg font-semibold text-slate-900">{latest_training.title}</h3>
                                            <p className="mt-1 text-sm text-slate-500">{latest_training.group_label}</p>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => toggleTrainingDetail(latest_training.id)}
                                            className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300"
                                        >
                                            {expandedTrainingId === latest_training.id ? 'Ocultar detalhe' : 'Abrir detalhe'}
                                        </button>
                                    </div>

                                    {expandedTrainingId === latest_training.id ? (
                                        <TrainingDetailPanel
                                            training={latest_training}
                                            detailId={`training-detail-${latest_training.id}`}
                                            className="mt-4"
                                            editingTrainingId={editingTrainingId}
                                            volumeValue={volumeForm.data.volume_real_m}
                                            volumeError={volumeForm.errors.volume_real_m}
                                            volumeProcessing={volumeForm.processing}
                                            onVolumeChange={(value) => volumeForm.setData('volume_real_m', value)}
                                            onStartVolumeEdit={() => startVolumeEdit(latest_training)}
                                            onCancelVolumeEdit={() => setEditingTrainingId(null)}
                                            onSubmitVolume={() => submitVolumeCorrection(latest_training.id)}
                                        />
                                    ) : null}
                                </article>
                            ) : (
                                <EmptyState message={is_athlete ? 'Ainda não existem treinos concluídos.' : 'Sem treinos disponíveis para este utilizador.'} />
                            )}
                        </PortalSection>
                    </div>

                    <div className="space-y-4">
                        <PortalSection title="Histórico recente" description="Últimos treinos realizados e respetivo estado de presença.">
                            {history.length > 0 ? (
                                <div className="space-y-3">
                                    {history.map((training) => (
                                        <article key={training.id} className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{training.date_label}</p>
                                                    <h3 className="mt-2 text-sm font-semibold text-slate-900">{training.title}</h3>
                                                    <p className="mt-1 text-sm text-slate-500">{training.final_meters_label}</p>
                                                </div>
                                                <StatusBadge status={training.status} compact />
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState message="Ainda não existem treinos concluídos." />
                            )}
                        </PortalSection>

                        <PortalSection title="Notas do treinador" description="Última observação registada sobre o teu treino.">
                            {latest_coach_note ? (
                                <article className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{latest_coach_note.date_label}</p>
                                    <h3 className="mt-2 text-base font-semibold text-slate-900">{latest_coach_note.context}</h3>
                                    <p className="mt-3 text-sm leading-6 text-slate-600">{latest_coach_note.note}</p>
                                </article>
                            ) : (
                                <EmptyState message="Sem notas do treinador." />
                            )}
                        </PortalSection>
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}

function StatusBadge({ status, compact = false }: { status: TrainingStatus; compact?: boolean }) {
    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ${statusToneClasses[status.tone]} ${compact ? '' : 'shadow-sm'}`}
        >
            {status.label}
        </span>
    );
}

function InlineMeta({ icon: Icon, label }: { icon: typeof Clock3; label: string }) {
    return (
        <div className="flex items-start gap-2">
            <Icon className="mt-0.5 h-4 w-4 text-slate-400" />
            <span>{label}</span>
        </div>
    );
}

function MetricTile({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-3">
            <p className="text-xs font-medium text-slate-500">{label}</p>
            <p className="mt-2 text-base font-semibold text-slate-900">{value}</p>
        </div>
    );
}

function TrainingDetailPanel({
    training,
    detailId,
    className = 'mt-4',
    editingTrainingId,
    volumeValue,
    volumeError,
    volumeProcessing,
    onVolumeChange,
    onStartVolumeEdit,
    onCancelVolumeEdit,
    onSubmitVolume,
}: {
    training: TrainingCard;
    detailId: string;
    className?: string;
    editingTrainingId: string | null;
    volumeValue: string;
    volumeError?: string;
    volumeProcessing: boolean;
    onVolumeChange: (value: string) => void;
    onStartVolumeEdit: () => void;
    onCancelVolumeEdit: () => void;
    onSubmitVolume: () => void;
}) {
    return (
        <div id={detailId} className={`${className} rounded-2xl border border-slate-200 bg-white p-3 text-sm text-slate-600`}>
            <p className="font-medium text-slate-800">Detalhe do treino</p>

            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricTile label="Volume total" value={training.planned_meters_label} />
                <MetricTile label="Metros finais" value={training.final_meters_label} />
                <MetricTile label="Conclusão" value={training.completion_percent !== null ? `${training.completion_percent}%` : 'Sem cálculo'} />
                <MetricTile label="Estado" value={training.status.label} />
            </div>

            <div className="mt-4 space-y-2">
                <InlineMeta icon={Clock3} label={training.time_label} />
                <InlineMeta icon={MapPin} label={training.location} />
            </div>

            <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Descrição do treino</p>
                <p className="mt-2 text-sm text-slate-700">{training.description || 'Sem descrição do treino.'}</p>
            </div>

            {training.plan_note ? (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Notas do plano</p>
                    <p className="mt-2 text-sm text-slate-700">{training.plan_note}</p>
                </div>
            ) : null}

            {training.series.length > 0 ? (
                <div className="mt-4 overflow-hidden rounded-2xl border border-slate-200">
                    <div className="border-b border-slate-200 bg-slate-50 px-3 py-2">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Tabela de séries</p>
                    </div>
                    <div className="min-w-0">
                        <Table responsive className="text-sm">
                            <TableHeader className="bg-white text-xs uppercase tracking-[0.14em] text-slate-400">
                                <TableRow>
                                    <TableHead className="px-3 py-2 font-semibold">#</TableHead>
                                    <TableHead className="px-3 py-2 font-semibold">Descrição</TableHead>
                                    <TableHead className="px-3 py-2 font-semibold">Metros</TableHead>
                                    <TableHead className="px-3 py-2 font-semibold">Zona</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-slate-200 bg-white text-slate-700">
                                {training.series.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell label="#" className="px-3 py-2 align-top">{row.ordem ?? '—'}</TableCell>
                                        <TableCell label="Descrição" className="px-3 py-2 align-top">
                                            <p>{row.descricao_texto || 'Sem descrição'}</p>
                                            {(row.repeticoes || row.estilo || row.intervalo || row.observacoes) ? (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {[row.repeticoes ? `${row.repeticoes} rep.` : null, row.estilo, row.intervalo ? `Intervalo ${row.intervalo}` : null, row.observacoes].filter(Boolean).join(' · ')}
                                                </p>
                                            ) : null}
                                        </TableCell>
                                        <TableCell label="Metros" className="px-3 py-2 align-top">{formatMetersValue(row.distancia_total_m)}</TableCell>
                                        <TableCell label="Zona" className="px-3 py-2 align-top">{row.zona_intensidade || '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            ) : (
                <div className="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-500">
                    Sem tabela de séries disponível.
                </div>
            )}

            {training.permissions.can_correct_volume ? (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                    {editingTrainingId === training.id ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                onSubmitVolume();
                            }}
                            className="space-y-3"
                        >
                            <label className="block text-sm font-medium text-slate-700">
                                Metros feitos
                                <input
                                    type="number"
                                    min={0}
                                    max={50000}
                                    value={volumeValue}
                                    onChange={(event) => onVolumeChange(event.target.value)}
                                    className="mt-2 w-full rounded-2xl border border-slate-200 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-blue-400"
                                    placeholder="Ex.: 3200"
                                />
                            </label>
                            <p className="text-xs text-slate-500">
                                Igual ao volume total: concluído. Abaixo do volume total: incompleto. Zero metros: não concluído.
                            </p>
                            {volumeError ? <p className="text-sm text-rose-600">{volumeError}</p> : null}
                            <div className="flex flex-wrap gap-2">
                                <ActionButton type="submit" disabled={volumeProcessing}>Guardar metros</ActionButton>
                                <SecondaryButton onClick={onCancelVolumeEdit}>Cancelar</SecondaryButton>
                            </div>
                        </form>
                    ) : (
                        <ActionButton onClick={onStartVolumeEdit}>
                            {training.final_meters !== null ? 'Atualizar metros' : 'Adicionar metros feitos'}
                        </ActionButton>
                    )}
                </div>
            ) : null}

            {training.coach_note ? (
                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Nota do treinador</p>
                    <p className="mt-2 text-sm text-slate-700">{training.coach_note}</p>
                </div>
            ) : null}
        </div>
    );
}

function formatMetersValue(value: number | null): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${new Intl.NumberFormat('pt-PT').format(value)} m`;
}

function EmptyState({ message }: { message: string }) {
    return (
        <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50/70 px-4 py-6 text-sm text-slate-500">
            {message}
        </div>
    );
}

function ActionButton({
    children,
    onClick,
    type = 'button',
    disabled = false,
}: {
    children: React.ReactNode;
    onClick?: () => void;
    type?: 'button' | 'submit';
    disabled?: boolean;
}) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
        >
            {children}
        </button>
    );
}

function SecondaryButton({
    children,
    onClick,
    tone = 'default',
}: {
    children: React.ReactNode;
    onClick: () => void;
    tone?: 'default' | 'danger';
}) {
    const toneClass = tone === 'danger'
        ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'
        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300';

    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex items-center justify-center rounded-2xl border px-3.5 py-2 text-sm font-semibold transition ${toneClass}`}
        >
            {children}
        </button>
    );
}

function formatHeroDate(weekdayLabel: string | null, timeLabel: string): string {
    if (!weekdayLabel) {
        return timeLabel;
    }

    return `${weekdayLabel}, ${timeLabel}`;
}