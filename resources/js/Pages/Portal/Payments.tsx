import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    CreditCard,
    FileText,
    History,
    Receipt,
} from 'lucide-react';
import PortalKpiCard from '@/Components/Portal/PortalKpiCard';
import PortalSection from '@/Components/Portal/PortalSection';
import PortalLayout from '@/Layouts/PortalLayout';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { amountToneClass, formatSignedCurrency } from '@/lib/financialDisplay';
import type { PageProps as SharedPageProps } from '@/types';

interface PaymentStatus {
    key: 'paid' | 'pending' | 'overdue' | 'partial' | 'cancelled';
    label: string;
}

interface PaymentSummary {
    id: string;
    label: string;
    date: string | null;
    amount: number;
    status: PaymentStatus;
}

interface PaymentMovement {
    id: string;
    description: string;
    date: string | null;
    due_date: string | null;
    amount: number;
    nominal_amount?: number | null;
    paid_amount?: number | null;
    amount_label?: string;
    type_label?: string;
    status: PaymentStatus;
    reference: string | null;
    receipt_number: string | null;
    payment_method: string | null;
    detail?: {
        kind: 'invoice' | 'movement';
        period: string | null;
        notes: string | null;
        items: Array<{
            id: string;
            description: string;
            quantity: number;
            unit_amount: number;
            total_amount: number;
        }>;
    };
    actions: {
        can_view_receipt: boolean;
        can_view_detail: boolean;
        can_pay: boolean;
    };
}

interface LatestReceipt {
    id: string;
    receipt_number: string;
    date: string | null;
    amount: number;
    can_view_receipt: boolean;
}

interface PortalPaymentsProps extends Record<string, unknown> {
    user: {
        id: string | number;
        name: string;
        email?: string | null;
    };
    is_also_admin: boolean;
    has_family: boolean;
    secure_payment_enabled: boolean;
    hero: {
        title: string;
        status: string;
        outstanding_value: number;
        next_payment: PaymentSummary | null;
        actions: {
            can_view_receipts: boolean;
            can_view_history: boolean;
            can_pay: boolean;
        };
    };
    kpis: {
        outstanding_value: number;
        next_payment: PaymentSummary | null;
        plan: string;
        receipts_this_year: number;
    };
    account_current: {
        outstanding_value: number;
        gross_debt: number;
        available_credit: number;
        overdue_invoices: number;
        overdue_value: number;
        next_payment: PaymentSummary | null;
        plan: string;
        general_status: string;
    };
    movements: PaymentMovement[];
    latest_receipts: LatestReceipt[];
}

type PageProps = SharedPageProps<PortalPaymentsProps>;

const statusClassMap: Record<PaymentStatus['key'], string> = {
    paid: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    pending: 'border-amber-200 bg-amber-50 text-amber-700',
    overdue: 'border-rose-200 bg-rose-50 text-rose-700',
    partial: 'border-sky-200 bg-sky-50 text-sky-700',
    cancelled: 'border-slate-200 bg-slate-100 text-slate-600',
};

function formatDate(date: string | null | undefined): string {
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

function formatFullDate(date: string | null | undefined): string {
    if (!date) {
        return 'Sem data';
    }

    const parsed = new Date(`${date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) {
        return 'Sem data';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(parsed);
}

function statusChip(status: PaymentStatus) {
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${statusClassMap[status.key]}`}>
            {status.label}
        </span>
    );
}

function EmptyState({ message }: { message: string }) {
    return (
        <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
            {message}
        </div>
    );
}

function scrollToSection(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function Payments() {
    const {
        auth,
        clubSettings,
        is_also_admin,
        has_family,
        secure_payment_enabled,
        hero,
        kpis,
        account_current,
        movements,
        latest_receipts,
    } = usePage<PageProps>().props;

    const hasDebt = account_current.outstanding_value > 0;
    const [selectedMovement, setSelectedMovement] = useState<PaymentMovement | null>(null);

    return (
        <>
            <Head title="Pagamentos" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="payments"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[20px] bg-[linear-gradient(180deg,#0f57b3_0%,#114c98_100%)] px-3.5 py-4 text-white shadow-[0_14px_28px_rgba(15,76,152,0.18)] sm:px-4">
                    <div className="flex flex-col items-start gap-3">
                        <div className="max-w-2xl">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Portal</p>
                            <h2 className="mt-1.5 text-xl font-semibold">{hero.title}</h2>
                            <div className="mt-2.5 flex flex-wrap items-center gap-2">
                                <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${hasDebt ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
                                    {hero.status}
                                </span>
                                {!hasDebt ? <span className="text-xs text-blue-100">Tudo em dia.</span> : null}
                            </div>
                        </div>

                        <div className="grid w-full max-w-[22rem] gap-2.5 rounded-[18px] border border-white/15 bg-white/10 p-3.5 backdrop-blur">
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-blue-100">Conta Corrente</p>
                                <p className={`mt-1.5 text-xl font-semibold ${amountToneClass(hero.outstanding_value, 'debt', 'dark')}`}>{formatSignedCurrency(hero.outstanding_value, 'debt')}</p>
                            </div>
                            <div>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-blue-100">Próximo em aberto</p>
                                {hero.next_payment ? (
                                    <>
                                        <p className="mt-1.5 text-xs font-semibold text-white">{hero.next_payment.label}</p>
                                        <p className="mt-1 text-xs text-blue-50">{formatFullDate(hero.next_payment.date)} · <span className={amountToneClass(hero.next_payment.amount, 'debt', 'dark')}>{formatSignedCurrency(hero.next_payment.amount, 'debt')}</span></p>
                                    </>
                                ) : (
                                    <p className="mt-1.5 text-xs text-blue-50">Sem pagamentos pendentes.</p>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2 pt-0.5">
                                <button
                                    type="button"
                                    onClick={() => scrollToSection('latest-receipts')}
                                    className="inline-flex items-center justify-center rounded-xl bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-50"
                                >
                                    Ver recibos
                                </button>
                                <button
                                    type="button"
                                    onClick={() => scrollToSection('movements')}
                                    className="inline-flex items-center justify-center rounded-xl border border-white/25 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/15"
                                >
                                    Histórico
                                </button>
                                {secure_payment_enabled && hero.actions.can_pay ? (
                                    <button
                                        type="button"
                                        className="inline-flex items-center justify-center rounded-xl border border-lime-200 bg-lime-50 px-3 py-1.5 text-xs font-semibold text-lime-700 transition hover:bg-lime-100"
                                    >
                                        Pagar
                                    </button>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <PortalKpiCard label="Conta Corrente" value={formatSignedCurrency(kpis.outstanding_value, 'debt')} valueClassName={amountToneClass(kpis.outstanding_value, 'debt')} helper="saldo operacional atual" icon={CreditCard} />
                    <PortalKpiCard label="Em aberto" value={kpis.next_payment ? formatSignedCurrency(kpis.next_payment.amount, 'debt') : formatSignedCurrency(0, 'debt')} helper={kpis.next_payment ? formatDate(kpis.next_payment.date) : 'Tudo em dia'} icon={CalendarClock} />
                    <PortalKpiCard label="Plano / mensalidade" value={kpis.plan} helper="configuração atual" icon={FileText} />
                    <PortalKpiCard label="Crédito disponível" value={formatSignedCurrency(account_current.available_credit, 'credit')} helper="créditos reais disponíveis" icon={Receipt} valueClassName={amountToneClass(account_current.available_credit, 'credit')} />
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.85fr)] xl:items-start">
                    <div className="space-y-4">
                        <PortalSection title="Faturas e movimentos" description="Valores em aberto reais visíveis ao utilizador final." actionLabel="Ver histórico" onAction={() => scrollToSection('movements')}>
                            <div id="movements" className="space-y-3">
                                {movements.length > 0 ? movements.map((movement) => (
                                    <article key={movement.id} className="rounded-[24px] border border-slate-200 bg-slate-50/70 p-4">
                                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-base font-semibold text-slate-900">{movement.description}</h3>
                                                    {movement.type_label ? <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600">{movement.type_label}</span> : null}
                                                    {statusChip(movement.status)}
                                                </div>
                                                <div className="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
                                                    <p><span className="font-medium text-slate-800">Data:</span> {formatFullDate(movement.date)}</p>
                                                    <p><span className="font-medium text-slate-800">{movement.amount_label || 'Valor'}:</span> <span className={amountToneClass(movement.amount, movement.status.key === 'paid' ? 'credit' : 'debt')}>{formatSignedCurrency(movement.amount, movement.status.key === 'paid' ? 'credit' : 'debt')}</span></p>
                                                    <p><span className="font-medium text-slate-800">Referência / recibo:</span> {movement.receipt_number || movement.reference || 'Sem referência'}</p>
                                                    <p><span className="font-medium text-slate-800">Método de pagamento:</span> {movement.payment_method || 'Não disponível'}</p>
                                                    {movement.nominal_amount !== undefined && movement.nominal_amount !== null && Math.abs(movement.nominal_amount - movement.amount) > 0.009 ? (
                                                        <p><span className="font-medium text-slate-800">Valor nominal:</span> <span className="text-slate-800">{formatSignedCurrency(movement.nominal_amount, 'debt')}</span></p>
                                                    ) : null}
                                                    {movement.paid_amount !== undefined && movement.paid_amount !== null && movement.paid_amount > 0 ? (
                                                        <p><span className="font-medium text-slate-800">Pago:</span> <span className={amountToneClass(movement.paid_amount, 'credit')}>{formatSignedCurrency(movement.paid_amount, 'credit')}</span></p>
                                                    ) : null}
                                                    <p className="sm:col-span-2"><span className="font-medium text-slate-800">Vencimento:</span> {formatFullDate(movement.due_date)}</p>
                                                </div>
                                            </div>

                                            <div className="flex shrink-0 flex-wrap gap-2 md:max-w-[180px] md:flex-col">
                                                {movement.actions.can_view_receipt ? (
                                                    <button type="button" className="inline-flex items-center justify-center rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                        Ver recibo
                                                    </button>
                                                ) : null}
                                                {movement.actions.can_view_detail ? (
                                                    <button type="button" onClick={() => setSelectedMovement(movement)} className="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-100">
                                                        Ver detalhe
                                                    </button>
                                                ) : null}
                                                {secure_payment_enabled && movement.actions.can_pay ? (
                                                    <button type="button" className="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-700">
                                                        Pagar
                                                    </button>
                                                ) : null}
                                            </div>
                                        </div>
                                    </article>
                                )) : (
                                    <EmptyState message="Tudo em dia." />
                                )}
                            </div>
                        </PortalSection>
                    </div>

                    <div className="space-y-4">
                        <PortalSection title="Conta Corrente" description="Leitura operacional consolidada com valores em aberto e crédito disponível." actionLabel="Abrir recibos" onAction={() => scrollToSection('latest-receipts')}>
                            <div className="space-y-3">
                                <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Estado geral</p>
                                    <div className="mt-2 flex items-center gap-2">
                                        <CheckCircle2 className={`h-5 w-5 ${hasDebt ? 'text-amber-500' : 'text-emerald-500'}`} />
                                        <p className="text-base font-semibold text-slate-900">{account_current.general_status}</p>
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Conta Corrente</p>
                                        <p className={`mt-2 text-xl font-semibold ${amountToneClass(account_current.outstanding_value, 'debt')}`}>{formatSignedCurrency(account_current.outstanding_value, 'debt')}</p>
                                    </div>
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Em aberto vencido</p>
                                        <p className="mt-2 text-xl font-semibold text-slate-900">{account_current.overdue_invoices}</p>
                                        <p className={`mt-1 text-sm ${amountToneClass(account_current.overdue_value, 'debt')}`}>{formatSignedCurrency(account_current.overdue_value, 'debt')}</p>
                                    </div>
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Em aberto</p>
                                        <p className={`mt-2 text-xl font-semibold ${amountToneClass(account_current.gross_debt, 'debt')}`}>{formatSignedCurrency(account_current.gross_debt, 'debt')}</p>
                                    </div>
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Crédito disponível</p>
                                        <p className={`mt-2 text-xl font-semibold ${amountToneClass(account_current.available_credit, 'credit')}`}>{formatSignedCurrency(account_current.available_credit, 'credit')}</p>
                                    </div>
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Próximo em aberto</p>
                                        {account_current.next_payment ? (
                                            <>
                                                <p className="mt-2 text-base font-semibold text-slate-900">{account_current.next_payment.label}</p>
                                                <p className="mt-1 text-sm text-slate-500">{formatFullDate(account_current.next_payment.date)} · <span className={amountToneClass(account_current.next_payment.amount, 'debt')}>{formatSignedCurrency(account_current.next_payment.amount, 'debt')}</span></p>
                                            </>
                                        ) : (
                                            <p className="mt-2 text-sm text-slate-500">Tudo em dia.</p>
                                        )}
                                    </div>
                                    <div className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Plano de mensalidade</p>
                                        <p className="mt-2 text-base font-semibold text-slate-900">{account_current.plan}</p>
                                    </div>
                                </div>
                            </div>
                        </PortalSection>

                        <PortalSection title="Últimos recibos" description="Recibos emitidos e disponíveis para consulta." actionLabel="Ir ao topo" onAction={() => window.scrollTo({ top: 0, behavior: 'smooth' })}>
                            <div id="latest-receipts" className="space-y-3">
                                {latest_receipts.length > 0 ? latest_receipts.map((receipt) => (
                                    <article key={receipt.id} className="rounded-[22px] border border-slate-200 bg-slate-50/70 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{receipt.receipt_number}</p>
                                                <p className="mt-1 text-xs text-slate-500">{formatFullDate(receipt.date)}</p>
                                                <p className={`mt-2 text-sm font-medium ${amountToneClass(receipt.amount, 'credit')}`}>{formatSignedCurrency(receipt.amount, 'credit')}</p>
                                            </div>
                                            {receipt.can_view_receipt ? (
                                                <button type="button" className="inline-flex items-center gap-1 rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                    Ver recibo
                                                    <ArrowRight className="h-3.5 w-3.5" />
                                                </button>
                                            ) : null}
                                        </div>
                                    </article>
                                )) : (
                                    <EmptyState message="Ainda não existem recibos emitidos." />
                                )}
                            </div>
                        </PortalSection>

                    </div>
                </section>

                <Dialog open={selectedMovement !== null} onOpenChange={(open) => !open && setSelectedMovement(null)}>
                    <DialogContent className="max-h-[90vh] w-[calc(100vw-2rem)] max-w-2xl overflow-y-auto rounded-[24px]">
                        {selectedMovement ? (
                            <>
                                <DialogHeader>
                                    <DialogTitle>{selectedMovement.type_label || 'Movimento'} — {selectedMovement.description}</DialogTitle>
                                    <DialogDescription>
                                        Consulta do detalhe da mensalidade ou movimento da conta corrente.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="space-y-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {statusChip(selectedMovement.status)}
                                        {selectedMovement.detail?.period ? (
                                            <span className="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700">
                                                Período: {selectedMovement.detail.period}
                                            </span>
                                        ) : null}
                                    </div>
                                    <dl className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2">
                                        <PaymentDetail label="Data" value={formatFullDate(selectedMovement.date)} />
                                        <PaymentDetail label="Vencimento" value={formatFullDate(selectedMovement.due_date)} />
                                        <PaymentDetail label="Valor nominal" value={formatSignedCurrency(selectedMovement.nominal_amount ?? selectedMovement.amount, 'debt')} />
                                        <PaymentDetail label="Em aberto" value={formatSignedCurrency(selectedMovement.amount, 'debt')} />
                                        <PaymentDetail label="Pago" value={formatSignedCurrency(selectedMovement.paid_amount ?? 0, 'credit')} />
                                        <PaymentDetail label="Referência" value={selectedMovement.receipt_number || selectedMovement.reference || 'Sem referência'} />
                                        <PaymentDetail label="Método de pagamento" value={selectedMovement.payment_method || 'Não disponível'} />
                                    </dl>
                                    {selectedMovement.detail?.items?.length ? (
                                        <div>
                                            <h4 className="text-sm font-semibold text-slate-900">Linhas</h4>
                                            <div className="mt-2 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                                                {selectedMovement.detail.items.map((item) => (
                                                    <div key={item.id} className="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                                                        <div>
                                                            <p className="font-medium text-slate-800">{item.description}</p>
                                                            <p className="mt-1 text-xs text-slate-500">{item.quantity} × {formatSignedCurrency(item.unit_amount, 'debt')}</p>
                                                        </div>
                                                        <p className="font-semibold text-slate-900">{formatSignedCurrency(item.total_amount, 'debt')}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                    {selectedMovement.detail?.notes ? (
                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Observações</p>
                                            <p className="mt-2 text-sm text-slate-700">{selectedMovement.detail.notes}</p>
                                        </div>
                                    ) : null}
                                </div>
                            </>
                        ) : null}
                    </DialogContent>
                </Dialog>
            </PortalLayout>
        </>
    );
}

function PaymentDetail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{label}</dt>
            <dd className="mt-1 text-sm font-medium text-slate-700">{value}</dd>
        </div>
    );
}
