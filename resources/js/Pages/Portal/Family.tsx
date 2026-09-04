import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarDays,
    ChevronRight,
    CreditCard,
    Search,
    UserPlus,
    Users,
    WalletCards,
    X,
} from 'lucide-react';
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

interface FamilyPortalProps {
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
    familySummary: FamilySummary;
    pagamentos?: PaymentItem[];
    convocatorias_pendentes?: AgendaItem[];
    proximos_treinos?: AgendaItem[];
    is_also_admin: boolean;
    has_family?: boolean;
    can_manage_family?: boolean;
    clubSettings?: ClubSettingsProps;
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
    numero_socio?: string | null;
    foto_perfil?: string | null;
    estado?: string | null;
    escalao?: string | null;
    roleLabel: string;
    memberUrl?: string | null;
}

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length > 1) {
        return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
    }

    return (parts[0]?.slice(0, 2) || 'U').toUpperCase();
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

function paymentAmount(payment: PaymentItem): number {
    const raw = payment.valor_label ?? payment.valor ?? payment.valor_nominal ?? 0;
    if (typeof raw === 'number') {
        return raw;
    }

    const normalized = String(raw).replace(/[^0-9,.-]/g, '').replace(',', '.');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : 0;
}

export default function Family() {
    const { props } = usePage<PageProps>();
    const {
        auth,
        familyMember,
        families = [],
        familySummary,
        pagamentos = [],
        convocatorias_pendentes = [],
        proximos_treinos = [],
        is_also_admin,
        has_family = true,
        can_manage_family = false,
        clubSettings,
    } = props;

    const [showAssociate, setShowAssociate] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchingMembers, setSearchingMembers] = useState(false);
    const [searchResults, setSearchResults] = useState<SearchResultMember[]>([]);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [submittingMemberId, setSubmittingMemberId] = useState<string | number | null>(null);

    const familyMembers = families
        .flatMap((family) => family.members ?? [])
        .filter((member) => member.can_view !== false)
        .reduce<FamilyMemberCard[]>((carry, member) => {
            if (carry.some((current) => String(current.id) === String(member.id))) {
                return carry;
            }

            carry.push({
                id: member.id,
                name: member.name,
                numero_socio: member.numero_socio ?? null,
                foto_perfil: member.foto_perfil ?? null,
                estado: member.estado ?? null,
                escalao: member.escalao?.[0] ?? null,
                roleLabel: roleLabel(member.papel_na_familia),
                memberUrl: member.can_view ? `/portal/perfil?member=${member.id}` : null,
            });

            return carry;
        }, []);

    const pendingPayments = pagamentos.slice(0, 3);
    const agendaItems = [...convocatorias_pendentes, ...proximos_treinos]
        .sort((left, right) => String(left.date ?? '').localeCompare(String(right.date ?? '')))
        .slice(0, 3);

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
        if (!can_manage_family) {
            setSearchError('Sem permissão para gerir associações familiares.');
            return;
        }

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
                setShowAssociate(false);
            },
            onError: () => {
                setSubmittingMemberId(null);
                setSearchError('Não foi possível associar o membro selecionado.');
            },
        });
    };

    return (
        <>
            <Head title="Família" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="family"
                hasFamily={has_family}
            >
                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            {familyMember.foto_perfil ? (
                                <img src={familyMember.foto_perfil} alt={familyMember.name} className="h-11 w-11 rounded-2xl object-cover" />
                            ) : (
                                <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-sm font-semibold text-blue-700">
                                    {getInitials(familyMember.name)}
                                </div>
                            )}
                            <div className="min-w-0">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Família</p>
                                <h1 className="truncate text-base font-semibold text-slate-900">{familyMember.name}</h1>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    {familyMember.numero_socio ? `Sócio #${familyMember.numero_socio}` : 'Gestão familiar'}
                                </p>
                            </div>
                        </div>
                        <Users className="h-5 w-5 shrink-0 text-blue-600" />
                    </div>
                </section>

                <button
                    type="button"
                    onClick={() => router.visit(portalRoutes.payments)}
                    className="w-full rounded-[22px] border border-slate-200 bg-white p-4 text-left shadow-[0_8px_22px_rgba(15,23,42,0.045)] transition hover:border-blue-200 sm:p-5"
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold text-slate-900">Conta Corrente da família</p>
                            <div className="mt-3 flex flex-wrap items-end gap-x-8 gap-y-3">
                                <div>
                                    <p className="text-[10px] uppercase tracking-[0.13em] text-slate-400">Saldo total</p>
                                    <p className={`mt-1 text-2xl font-semibold ${amountToneClass(familySummary?.net_debt ?? 0, 'debt')}`}>
                                        {formatSignedCurrency(familySummary?.net_debt ?? 0, 'debt')}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[10px] uppercase tracking-[0.13em] text-slate-400">Em aberto</p>
                                    <p className="mt-1 text-base font-semibold text-slate-900">{familySummary?.pagamentos_pendentes ?? pagamentos.length}</p>
                                    <p className="text-[11px] text-slate-500">pagamentos</p>
                                </div>
                                <div>
                                    <p className="text-[10px] uppercase tracking-[0.13em] text-slate-400">Crédito disponível</p>
                                    <p className="mt-1 text-base font-semibold text-slate-900">
                                        {formatSignedCurrency(familySummary?.available_credit ?? 0, 'credit')}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <WalletCards className="h-4 w-4" />
                        </span>
                    </div>
                </button>

                <section className="grid grid-cols-2 gap-3">
                    <button
                        type="button"
                        onClick={() => router.visit(portalRoutes.payments)}
                        className="rounded-[20px] border border-slate-200 bg-white p-3.5 text-left shadow-[0_6px_18px_rgba(15,23,42,0.04)] transition hover:border-blue-200"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Pagamentos</p>
                                <p className="mt-1.5 text-xl font-semibold text-slate-900">{familySummary?.pagamentos_pendentes ?? pagamentos.length}</p>
                                <p className="mt-1 text-[11px] text-slate-500">em aberto</p>
                            </div>
                            <CreditCard className="h-4 w-4 text-blue-600" />
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() => router.visit(portalRoutes.events)}
                        className="rounded-[20px] border border-slate-200 bg-white p-3.5 text-left shadow-[0_6px_18px_rgba(15,23,42,0.04)] transition hover:border-blue-200"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Agenda</p>
                                <p className="mt-1.5 text-xl font-semibold text-slate-900">
                                    {(familySummary?.convocatorias_pendentes ?? convocatorias_pendentes.length) + (familySummary?.proximos_treinos ?? proximos_treinos.length)}
                                </p>
                                <p className="mt-1 text-[11px] text-slate-500">itens a acompanhar</p>
                            </div>
                            <CalendarDays className="h-4 w-4 text-blue-600" />
                        </div>
                    </button>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Membros</h2>
                            <p className="mt-1 text-xs text-slate-500">Pessoas associadas à tua família.</p>
                        </div>
                        {can_manage_family ? (
                            <button
                                type="button"
                                onClick={() => setShowAssociate((current) => !current)}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700"
                            >
                                {showAssociate ? <X className="h-3.5 w-3.5" /> : <UserPlus className="h-3.5 w-3.5" />}
                                {showAssociate ? 'Fechar' : 'Associar'}
                            </button>
                        ) : null}
                    </div>

                    <div className="mt-4 grid gap-2.5 sm:grid-cols-2">
                        {familyMembers.length > 0 ? familyMembers.map((member) => (
                            <button
                                key={member.id}
                                type="button"
                                onClick={() => member.memberUrl ? router.visit(member.memberUrl) : undefined}
                                className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 text-left transition hover:border-blue-200 hover:bg-white"
                            >
                                {member.foto_perfil ? (
                                    <img src={member.foto_perfil} alt={member.name} className="h-11 w-11 rounded-xl object-cover" />
                                ) : (
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-200 text-xs font-semibold text-slate-700">
                                        {getInitials(member.name)}
                                    </div>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-semibold text-slate-900">{member.name}</p>
                                    <p className="mt-0.5 truncate text-[11px] text-slate-500">
                                        {[member.roleLabel, member.escalao, member.numero_socio ? `#${member.numero_socio}` : null].filter(Boolean).join(' · ')}
                                    </p>
                                    <span className="mt-1.5 inline-flex rounded-full bg-white px-2 py-0.5 text-[9px] font-semibold text-slate-600">
                                        {member.estado || 'Ativo'}
                                    </span>
                                </div>
                                <ChevronRight className="h-4 w-4 shrink-0 text-slate-300" />
                            </button>
                        )) : (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4 text-sm text-slate-500 sm:col-span-2">
                                Não existem outros membros familiares visíveis.
                            </div>
                        )}
                    </div>

                    {showAssociate ? (
                        <div className="mt-4 rounded-2xl border border-blue-100 bg-blue-50/40 p-3.5">
                            <label className="text-xs font-semibold text-slate-600">Nome, email ou número de sócio</label>
                            <div className="mt-2 flex gap-2">
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
                                    className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-300"
                                />
                                <button
                                    type="button"
                                    onClick={() => void runMemberSearch()}
                                    disabled={searchingMembers}
                                    className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white disabled:opacity-60"
                                    aria-label="Pesquisar membro"
                                >
                                    <Search className="h-4 w-4" />
                                </button>
                            </div>

                            {searchError ? <p className="mt-2 text-xs text-rose-600">{searchError}</p> : null}

                            <div className="mt-3 space-y-2.5">
                                {searchResults.map((member) => (
                                    <div key={member.id} className="rounded-xl border border-slate-200 bg-white p-3">
                                        <p className="text-sm font-semibold text-slate-900">{member.name}</p>
                                        <p className="mt-0.5 text-[11px] text-slate-500">
                                            {[member.numero_socio ? `#${member.numero_socio}` : null, member.email, member.estado].filter(Boolean).join(' · ')}
                                        </p>
                                        <div className="mt-2.5 flex flex-wrap gap-2">
                                            <button type="button" onClick={() => associateMember(member.id, 'educando')} disabled={submittingMemberId === member.id} className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-[10px] font-semibold text-emerald-700 disabled:opacity-60">Educando</button>
                                            <button type="button" onClick={() => associateMember(member.id, 'encarregado_educacao')} disabled={submittingMemberId === member.id} className="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[10px] font-semibold text-amber-700 disabled:opacity-60">Encarregado</button>
                                            <button type="button" onClick={() => associateMember(member.id, 'familiar')} disabled={submittingMemberId === member.id} className="rounded-lg bg-slate-100 px-2.5 py-1.5 text-[10px] font-semibold text-slate-700 disabled:opacity-60">Familiar</button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-slate-900">Pagamentos pendentes</h2>
                            <button type="button" onClick={() => router.visit(portalRoutes.payments)} className="text-xs font-semibold text-blue-700">Ver todos</button>
                        </div>
                        <div className="mt-3 space-y-2.5">
                            {pendingPayments.length > 0 ? pendingPayments.map((payment) => (
                                <button
                                    key={payment.id}
                                    type="button"
                                    onClick={() => router.visit(portalRoutes.payments)}
                                    className="flex w-full items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-left"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-slate-900">{payment.user_name}</p>
                                        <p className="mt-0.5 text-[11px] text-slate-500">{payment.tipo_label || payment.mes || 'Pagamento'} · vence {formatDate(payment.data_vencimento)}</p>
                                    </div>
                                    <span className="shrink-0 text-sm font-semibold text-rose-600">
                                        {formatSignedCurrency(paymentAmount(payment), 'debt')}
                                    </span>
                                </button>
                            )) : <p className="text-sm text-slate-500">Sem pagamentos pendentes.</p>}
                        </div>
                    </div>

                    <div className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-base font-semibold text-slate-900">Agenda da família</h2>
                            <button type="button" onClick={() => router.visit(portalRoutes.events)} className="text-xs font-semibold text-blue-700">Ver agenda</button>
                        </div>
                        <div className="mt-3 space-y-2.5">
                            {agendaItems.length > 0 ? agendaItems.map((item) => (
                                <button
                                    key={`${item.type || 'agenda'}-${item.id}`}
                                    type="button"
                                    onClick={() => router.visit(portalRoutes.events)}
                                    className="flex w-full items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5 text-left"
                                >
                                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                        <CalendarDays className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-slate-900">{item.title || item.type || 'Agenda'}</p>
                                        <p className="mt-0.5 truncate text-[11px] text-slate-500">
                                            {item.user_name} · {formatDate(item.date)}{item.time ? ` · ${item.time.slice(0, 5)}` : ''}
                                        </p>
                                    </div>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-slate-300" />
                                </button>
                            )) : <p className="text-sm text-slate-500">Sem agenda pendente.</p>}
                        </div>
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}
