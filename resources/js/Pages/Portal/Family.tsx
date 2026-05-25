import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarDays,
    CreditCard,
    FileText,
    MessageCircle,
    Shield,
    Users,
    UserPlus,
    Search,
} from 'lucide-react';
import PortalCard from '@/Components/Portal/PortalCard';
import PortalKpiCard from '@/Components/Portal/PortalKpiCard';
import PortalSection from '@/Components/Portal/PortalSection';
import PortalUserContextSwitcher from '@/Components/Portal/PortalUserContextSwitcher';
import PortalLayout from '@/Layouts/PortalLayout';
import { amountToneClass, formatSignedCurrency } from '@/lib/financialDisplay';
import { portalRoutes } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps as SharedPageProps } from '@/types';

interface PortalUserSummary {
    id: string | number;
    name: string;
    email?: string | null;
    numero_socio?: string | null;
    foto_perfil?: string | null;
    estado?: string | null;
}

interface EducandoSummary {
    id: string | number;
    name: string;
    email?: string | null;
    numero_socio?: string | null;
    escalao?: string | null;
    estado?: string | null;
    foto_perfil?: string | null;
    member_url: string;
    pending_payments: number;
    pending_documents: number;
    pending_convocations: number;
    next_training?: {
        title?: string | null;
        date?: string | null;
        time?: string | null;
        location?: string | null;
    } | null;
}

interface FamilySummary {
    total_educandos: number;
    pagamentos_pendentes: number;
    pagamentos_pendentes_valor: number;
    gross_debt: number;
    available_credit: number;
    net_debt: number;
    convocatorias_pendentes: number;
    proximos_treinos: number;
    documentos_alerta: number;
}

interface PaymentItem {
    id: string;
    user_id: string | number;
    user_name: string;
    mes?: string | null;
    valor?: string | number | null;
    valor_nominal?: string | number | null;
    valor_pago?: string | number | null;
    valor_label?: string | null;
    estado?: string | null;
    tipo_label?: string | null;
    data_vencimento?: string | null;
}

interface AgendaItem {
    id: string;
    user_id: string | number;
    user_name: string;
    title?: string | null;
    date?: string | null;
    time?: string | null;
    location?: string | null;
    type?: string | null;
}

interface DocumentAlertItem {
    id: string;
    user_id: string | number;
    user_name: string;
    name: string;
    type?: string | null;
    expiry_date?: string | null;
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

interface FamilyPortalProps {
    user: PortalUserSummary;
    familyMember: PortalUserSummary;
    families?: Array<{
        id: string;
        nome: string;
        total_elementos: number;
        educandos_count: number;
        legacy: boolean;
        members?: Array<{
            id: string | number;
            name: string;
            email?: string | null;
            numero_socio?: string | null;
            foto_perfil?: string | null;
            estado?: string | null;
            tipo_membro?: string[];
            escalao?: string[];
            papel_na_familia?: string | null;
            can_view?: boolean;
            can_edit?: boolean;
        }>;
    }>;
    educandos: EducandoSummary[];
    familySummary: FamilySummary;
    pagamentos: PaymentItem[];
    convocatorias_pendentes: AgendaItem[];
    proximos_treinos: AgendaItem[];
    documentos_alerta: DocumentAlertItem[];
    comunicados_relevantes: AlertItem[];
    is_also_admin: boolean;
    has_family?: boolean;
    is_encarregado_educacao: boolean;
    is_also_athlete: boolean;
    athlete_portal_url?: string | null;
    clubSettings?: ClubSettingsProps;
    communicationAlerts?: {
        unreadCount: number;
        recent: AlertItem[];
    };
}

interface SearchResultMember {
    id: string | number;
    name: string;
    email?: string | null;
    numero_socio?: string | null;
    foto_perfil?: string | null;
    estado?: string | null;
    tipo_membro?: string[];
}

type PageProps = SharedPageProps<FamilyPortalProps>;

type FamilyRole = 'educando' | 'familiar' | 'encarregado_educacao' | 'responsavel' | 'family-member';

interface FamilyMemberCard {
    id: string | number;
    name: string;
    email?: string | null;
    numero_socio?: string | null;
    foto_perfil?: string | null;
    estado?: string | null;
    roleLabel: string;
    memberUrl?: string | null;
}

function getInitials(name: string): string {
    const parts = name.trim().split(' ').filter(Boolean);

    if (parts.length >= 2) {
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
    }

    return (parts[0]?.[0] ?? 'U').toUpperCase();
}

function formatDate(date?: string | null): string {
    if (!date) {
        return 'Sem data';
    }

    const parsed = new Date(`${date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
        return 'Sem data';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: 'short',
    }).format(parsed);
}

function formatDateTime(date?: string | null, time?: string | null): string {
    const label = formatDate(date);

    return time ? `${label} · ${time.slice(0, 5)}` : label;
}

function normalizeRole(value?: string | null): FamilyRole {
    const normalized = String(value ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    switch (normalized) {
        case 'educando':
        case 'familiar':
        case 'encarregado_educacao':
        case 'responsavel':
            return normalized;
        default:
            return 'family-member';
    }
}

function roleLabel(value?: string | null): string {
    switch (normalizeRole(value)) {
        case 'educando':
            return 'Educando';
        case 'encarregado_educacao':
        case 'responsavel':
            return 'Encarregado de educação';
        case 'familiar':
            return 'Familiar';
        default:
            return 'Membro';
    }
}

export default function Family() {
    const { props } = usePage<PageProps>();
    const { auth, has_family = true } = props;
    const {
        familyMember,
        families = [],
        educandos = [],
        familySummary,
        pagamentos = [],
        convocatorias_pendentes = [],
        proximos_treinos = [],
        documentos_alerta = [],
        communicationAlerts,
        clubSettings,
        is_also_admin,
        is_also_athlete,
        athlete_portal_url,
    } = props;

    const recentAlerts = (communicationAlerts?.recent ?? []).slice(0, 3);
    const clubName = clubSettings?.nome_clube?.trim() || clubSettings?.display_name?.trim() || 'ClubOS';
    const clubShortName = clubSettings?.sigla?.trim() || clubSettings?.short_name?.trim() || 'BSCN';
    const clubLogoUrl = clubSettings?.logo_url || null;

    const [searchQuery, setSearchQuery] = useState('');
    const [searchingMembers, setSearchingMembers] = useState(false);
    const [searchResults, setSearchResults] = useState<SearchResultMember[]>([]);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [submittingMemberId, setSubmittingMemberId] = useState<string | number | null>(null);

    const familyMembers: FamilyMemberCard[] = families
        .flatMap((family) => family.members ?? [])
        .filter((member) => member.can_view !== false)
        .filter((member) => String(member.id) !== String(familyMember.id))
        .reduce<FamilyMemberCard[]>((carry, member) => {
            if (carry.some((existing) => String(existing.id) === String(member.id))) {
                return carry;
            }

            carry.push({
                id: member.id,
                name: member.name,
                email: member.email ?? null,
                numero_socio: member.numero_socio ?? null,
                foto_perfil: member.foto_perfil ?? null,
                estado: member.estado ?? null,
                roleLabel: roleLabel(member.papel_na_familia),
                memberUrl: member.can_view ? `/portal/perfil?member=${member.id}` : null,
            });

            return carry;
        }, []);

    const totalManagedMembers = familyMembers.length;

    const runMemberSearch = async () => {
        const trimmed = searchQuery.trim();

        if (trimmed.length < 2) {
            setSearchResults([]);
            setSearchError('Introduza pelo menos 2 caracteres para pesquisar.');
            return;
        }

        setSearchingMembers(true);
        setSearchError(null);

        try {
            const response = await fetch(`/portal/familia/membros/search?search=${encodeURIComponent(trimmed)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Pesquisa indisponível.');
            }

            const payload = await response.json() as { results?: SearchResultMember[] };
            setSearchResults(payload.results ?? []);
        } catch (error) {
            setSearchResults([]);
            setSearchError(error instanceof Error ? error.message : 'Não foi possível pesquisar membros.');
        } finally {
            setSearchingMembers(false);
        }
    };

    const associateMember = (memberId: string | number, papelNaFamilia: 'educando' | 'familiar' | 'encarregado_educacao') => {
        setSubmittingMemberId(memberId);
        router.post('/portal/familia/membros', {
            member_id: memberId,
            papel_na_familia: papelNaFamilia,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSubmittingMemberId(null);
                setSearchQuery('');
                setSearchResults([]);
                setSearchError(null);
            },
            onError: () => {
                setSubmittingMemberId(null);
                setSearchError('Não foi possível associar o membro selecionado.');
            },
        });
    };

    const kpis = [
        { label: 'Membros', value: String(totalManagedMembers), helper: 'Associados', icon: Users },
        { label: 'Pagamentos', value: String(familySummary?.pagamentos_pendentes ?? pagamentos.length), helper: formatSignedCurrency(familySummary?.pagamentos_pendentes_valor ?? 0, 'debt'), icon: CreditCard },
        { label: 'Convocatórias', value: String(familySummary?.convocatorias_pendentes ?? convocatorias_pendentes.length), helper: 'Treinos por responder', icon: CalendarDays },
        { label: 'Documentos', value: String(familySummary?.documentos_alerta ?? documentos_alerta.length), helper: 'A expirar', icon: FileText },
    ];

    const quickAccess = [
        {
            key: 'payments',
            title: 'Pagamentos',
            description: 'Família e educandos',
            icon: CreditCard,
            accentClass: 'bg-emerald-50 text-emerald-600',
            onClick: () => router.visit(portalRoutes.payments),
        },
        {
            key: 'communications',
            title: 'Comunicados',
            description: 'Alertas relevantes',
            icon: MessageCircle,
            accentClass: 'bg-violet-50 text-violet-600',
            onClick: () => router.visit(portalRoutes.communications),
        },
    ];

    if (is_also_athlete && athlete_portal_url) {
        quickAccess.push({
            key: 'athlete-area',
            title: 'A minha área desportiva',
            description: 'Abrir portal atleta',
            icon: Shield,
            accentClass: 'bg-orange-50 text-orange-600',
            onClick: () => router.visit(athlete_portal_url),
        });
    }

    return (
        <>
            <Head title="A Minha Família" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="family"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[16px] bg-[linear-gradient(180deg,#0f57b3_0%,#114c98_100%)] px-2.5 py-2 text-white shadow-[0_8px_16px_rgba(15,76,152,0.14)]">
                    <div className="flex flex-col items-start gap-2">
                        <div className="min-w-0">
                            <p className="text-[9px] font-semibold uppercase tracking-[0.16em] text-blue-100">Portal Família</p>
                            <h2 className="mt-1 text-base font-semibold">A Minha Família</h2>
                        </div>
                        <div className="w-full max-w-[19rem] rounded-xl border border-white/15 bg-white/10 px-2.5 py-1.5 text-[10px] text-blue-50">
                            <p className="font-semibold text-white">{familyMember.name}</p>
                            <p className="mt-1">{familyMember.numero_socio ? `Sócio #${familyMember.numero_socio}` : 'Encarregado de Educação'}</p>
                        </div>
                    </div>
                </section>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    {kpis.map((item) => (
                        <PortalKpiCard key={item.label} label={item.label} value={item.value} helper={item.helper} icon={item.icon} />
                    ))}
                </div>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] xl:items-start">
                    <div className="space-y-4">
                        <PortalSection title="Membros" description="Todos os membros associados à família e respetiva relação.">
                            <div className="grid gap-3 md:grid-cols-2">
                                {familyMembers.length > 0 ? familyMembers.map((member) => (
                                    <button
                                        key={member.id}
                                        type="button"
                                        onClick={() => member.memberUrl ? router.visit(member.memberUrl) : undefined}
                                        className="rounded-[24px] border border-slate-200 bg-slate-50/70 p-4 text-left transition hover:border-blue-200 hover:bg-white"
                                    >
                                        <div className="flex items-start gap-3">
                                            {member.foto_perfil ? (
                                                <img src={member.foto_perfil} alt={member.name} className="h-12 w-12 rounded-2xl object-cover" />
                                            ) : (
                                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-200 text-sm font-semibold text-slate-700">
                                                    {getInitials(member.name)}
                                                </div>
                                            )}

                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-slate-900">{member.name}</p>
                                                <p className="mt-1 text-xs text-slate-500">{member.numero_socio ? `#${member.numero_socio} · ` : ''}{member.estado || 'Ativo'}</p>
                                                <div className="mt-3 flex flex-wrap gap-2 text-[11px] font-medium text-slate-600">
                                                    <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1">{member.roleLabel}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </button>
                                )) : (
                                    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 md:col-span-2">
                                        Nenhum membro associado.
                                    </div>
                                )}
                            </div>
                        </PortalSection>

                        <PortalSection title="Acesso rápido" description="Fluxos principais do Portal Família.">
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                                {quickAccess.map((item) => (
                                    <PortalCard
                                        key={item.key}
                                        title={item.title}
                                        description={item.description}
                                        icon={item.icon}
                                        accentClass={item.accentClass}
                                        onClick={item.onClick}
                                    />
                                ))}
                            </div>
                        </PortalSection>
                    </div>

                        <PortalSection title="Associar membro" description="Pesquise utilizadores existentes e associe-os à família com o papel correto.">
                            <div className="rounded-[24px] border border-slate-200 bg-slate-50/70 p-4">
                                <div className="flex flex-col gap-3 md:flex-row">
                                    <div className="flex-1">
                                        <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Nome, email ou número de sócio</label>
                                        <input
                                            value={searchQuery}
                                            onChange={(event) => setSearchQuery(event.target.value)}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    event.preventDefault();
                                                    void runMemberSearch();
                                                }
                                            }}
                                            placeholder="Pesquisar membro existente"
                                            className="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-blue-300"
                                        />
                                    </div>
                                    <div className="md:self-end">
                                        <button
                                            type="button"
                                            onClick={() => void runMemberSearch()}
                                            disabled={searchingMembers}
                                            className="inline-flex w-full items-center justify-center gap-2 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60 md:w-auto"
                                        >
                                            <Search className="h-4 w-4" />
                                            {searchingMembers ? 'A pesquisar...' : 'Pesquisar'}
                                        </button>
                                    </div>
                                </div>

                                {searchError ? <p className="mt-3 text-sm text-rose-600">{searchError}</p> : null}

                                <div className="mt-4 space-y-3">
                                    {searchResults.map((member) => (
                                        <div key={member.id} className="rounded-2xl border border-slate-200 bg-white p-3">
                                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-slate-900">{member.name}</p>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {[member.numero_socio ? `#${member.numero_socio}` : null, member.email, member.estado].filter(Boolean).join(' · ')}
                                                    </p>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => associateMember(member.id, 'educando')}
                                                        disabled={submittingMemberId === member.id}
                                                        className="inline-flex items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        <UserPlus className="h-3.5 w-3.5" />
                                                        Educando
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => associateMember(member.id, 'encarregado_educacao')}
                                                        disabled={submittingMemberId === member.id}
                                                        className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Encarregado
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => associateMember(member.id, 'familiar')}
                                                        disabled={submittingMemberId === member.id}
                                                        className="rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-60"
                                                    >
                                                        Familiar
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    ))}

                                    {!searchingMembers && searchQuery.trim().length >= 2 && searchResults.length === 0 && !searchError ? (
                                        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                            Sem resultados para associar.
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        </PortalSection>

                    <div className="space-y-4">
                        <PortalSection title="Conta Corrente da família" description="Saldo operacional agregado dos membros visíveis e crédito disponível real.">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <PortalKpiCard label="Conta Corrente" value={formatSignedCurrency(familySummary?.net_debt ?? 0, 'debt')} helper="saldo operacional agregado" icon={CreditCard} valueClassName={amountToneClass(familySummary?.net_debt ?? 0, 'debt')} />
                                <PortalKpiCard label="Em aberto" value={formatSignedCurrency(familySummary?.gross_debt ?? 0, 'debt')} helper="antes de créditos" icon={CreditCard} valueClassName={amountToneClass(familySummary?.gross_debt ?? 0, 'debt')} />
                                <PortalKpiCard label="Crédito disponível" value={formatSignedCurrency(familySummary?.available_credit ?? 0, 'credit')} helper="créditos reais da família" icon={Shield} valueClassName={amountToneClass(familySummary?.available_credit ?? 0, 'credit')} />
                            </div>
                        </PortalSection>

                        <PortalSection title="Pagamentos pendentes" description="Família e educandos associados.">
                            <div className="space-y-2.5">
                                {pagamentos.length > 0 ? pagamentos.map((payment) => (
                                    <div key={payment.id} className="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{payment.user_name}</p>
                                                <p className="mt-1 text-xs text-slate-500">{payment.mes || 'Pagamento pendente'} · {payment.tipo_label || 'Pagamento'} · {payment.estado || 'Pendente'}</p>
                                            </div>
                                            <p className={`text-sm font-semibold ${amountToneClass(payment.valor, 'debt')}`}>{payment.valor_label || 'Em aberto'}: {formatSignedCurrency(payment.valor, 'debt')}</p>
                                        </div>
                                        {payment.valor_nominal !== undefined && payment.valor_nominal !== null && Number(payment.valor_nominal) !== Number(payment.valor) ? (
                                            <p className="mt-2 text-xs text-slate-500">Valor nominal: {formatSignedCurrency(payment.valor_nominal, 'debt')}</p>
                                        ) : null}
                                        {payment.valor_pago !== undefined && payment.valor_pago !== null && Number(payment.valor_pago) > 0 ? (
                                            <p className="mt-1 text-xs text-slate-500">Pago: {formatSignedCurrency(payment.valor_pago, 'credit')}</p>
                                        ) : null}
                                        <p className="mt-2 text-xs text-slate-500">Vencimento: {formatDate(payment.data_vencimento)}</p>
                                    </div>
                                )) : (
                                    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                        Sem pagamentos pendentes.
                                    </div>
                                )}
                            </div>
                        </PortalSection>

                        <PortalSection title="Agenda da família" description="Treinos e convocatórias pendentes dos membros associados.">
                            <div className="space-y-2.5">
                                {[...proximos_treinos, ...convocatorias_pendentes].slice(0, 6).map((item) => (
                                    <div key={`${item.id}-${item.user_id}`} className="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                        <p className="text-sm font-semibold text-slate-900">{item.user_name}</p>
                                        <p className="mt-1 text-xs text-slate-500">{item.title || item.type || 'Agenda'} · {formatDateTime(item.date, item.time)}</p>
                                        <p className="mt-1 text-xs text-slate-500">{item.location || 'Local por definir'}</p>
                                    </div>
                                ))}
                                {proximos_treinos.length === 0 && convocatorias_pendentes.length === 0 ? (
                                    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                        Sem agenda pendente.
                                    </div>
                                ) : null}
                            </div>
                        </PortalSection>

                        <PortalSection title="Documentos e comunicados" description="Itens a acompanhar pela família.">
                            <div className="space-y-2.5">
                                {documentos_alerta.slice(0, 3).map((document) => (
                                    <div key={document.id} className="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                                        <p className="text-sm font-semibold text-slate-900">{document.user_name}</p>
                                        <p className="mt-1 text-xs text-slate-500">{document.name} · {document.type || 'Documento'}</p>
                                        <p className="mt-1 text-xs text-slate-500">Validade: {formatDate(document.expiry_date)}</p>
                                    </div>
                                ))}
                                {recentAlerts.slice(0, 2).map((alert) => (
                                    <button
                                        key={alert.id}
                                        type="button"
                                        onClick={() => router.visit(alert.link || portalRoutes.communications)}
                                        className="w-full rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-left transition hover:border-blue-200 hover:bg-white"
                                    >
                                        <p className="text-sm font-semibold text-slate-900">{alert.title}</p>
                                        <p className="mt-1 line-clamp-2 text-xs text-slate-500">{alert.message}</p>
                                    </button>
                                ))}
                                {documentos_alerta.length === 0 && recentAlerts.length === 0 ? (
                                    <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                        Sem alertas neste momento.
                                    </div>
                                ) : null}
                            </div>
                        </PortalSection>
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}