import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    CreditCard,
    FileText,
    Receipt,
    WalletCards,
} from 'lucide-react';
import PortalLayout from '@/Layouts/PortalLayout';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { amountToneClass, formatSignedCurrency } from '@/lib/financialDisplay';
import { portalRoutes } from '@/lib/portalRoutes';
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
    paid: 'bg-emerald-50 text-emerald-700',
    pending: 'bg-amber-50 text-amber-700',
    overdue: 'bg-rose-50 text-rose-700',
    partial: 'bg-sky-50 text-sky-700',
    cancelled: 'bg-slate-100 text-slate-600',
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
        year: 'numeric',
    }).format(parsed);
}

function movementTone(movement: PaymentMovement): 'credit' | 'debt' {
    return movement.status.key === 'paid' ? 'credit' : 'debt';
}

function formatPlainCurrency(value: number): string {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
}

export default function Payments() {
    const {
        auth,
        clubSettings,
        is_also_admin,
        has_family,
        account_current,
        movements = [],
        latest_receipts = [],
    } = usePage<PageProps>().props;

    const [selectedMovement, setSelectedMovement] = useState<PaymentMovement | null>(null);
    const openMovements = movements.filter((movement) => ['pending', 'overdue', 'partial'].includes(movement.status.key));
    const settledMovements = movements.filter((movement) => !['pending', 'overdue', 'partial'].includes(movement.status.key));
    const orderedMovements = [...openMovements, ...settledMovements];

    return (
        <>
            <Head title="Pagamentos e Documentos" />

            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={is_also_admin}
                activeNav="payments"
                hasFamily={has_family}
            >
                <section className="overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-[0_8px_22px_rgba(15,23,42,0.045)]">
                    <div className="flex items-center gap-3 px-4 pb-3 pt-4 sm:px-5 sm:pt-5">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <WalletCards className="h-5 w-5" />
                        </span>
                        <div>
                            <h1 className="text-lg font-semibold text-slate-900">Pagamentos e Documentos</h1>
                            <p className="mt-0.5 text-xs text-slate-500">Conta Corrente, faturas, recibos e documentos pessoais.</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 border-t border-slate-100 px-2 pt-1">
                        <button
                            type="button"
                            aria-current="page"
                            className="relative px-3 py-3 text-xs font-semibold text-blue-700"
                        >
                            Pagamentos
                            <span className="absolute inset-x-5 bottom-0 h-0.5 rounded-full bg-blue-600" />
                        </button>
                        <button
                            type="button"
                            onClick={() => router.visit(portalRoutes.documents)}
                            className="px-3 py-3 text-xs font-semibold text-slate-500 transition hover:text-blue-700"
                        >
                            Documentos
                        </button>
                    </div>
                </section>

                <section className="grid grid-cols-2 gap-3">
                    <div className="rounded-[20px] border border-slate-200 bg-white p-3.5 shadow-[0_6px_18px_rgba(15,23,42,0.04)] sm:p-4">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.13em] text-slate-400">Em aberto</p>
                                <p className={`mt-1.5 truncate text-xl font-semibold ${amountToneClass(account_current.outstanding_value, 'debt')}`}>
                                    {formatSignedCurrency(account_current.outstanding_value, 'debt')}
                                </p>
                                <p className="mt-1 text-[10px] text-slate-500">{account_current.overdue_invoices > 0 ? `${account_current.overdue_invoices} vencido(s)` : account_current.general_status}</p>
                            </div>
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                <CreditCard className="h-4 w-4" />
                            </span>
                        </div>
                    </div>

                    <div className="rounded-[20px] border border-slate-200 bg-white p-3.5 shadow-[0_6px_18px_rgba(15,23,42,0.04)] sm:p-4">
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.13em] text-slate-400">Crédito disponível</p>
                                <p className={`mt-1.5 truncate text-xl font-semibold ${amountToneClass(account_current.available_credit, 'credit')}`}>
                                    {formatSignedCurrency(account_current.available_credit, 'credit')}
                                </p>
                                <p className="mt-1 text-[10px] text-slate-500">crédito real disponível</p>
                            </div>
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                                <Receipt className="h-4 w-4" />
                            </span>
                        </div>
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Faturas e movimentos</h2>
                            <p className="mt-1 text-xs text-slate-500">Pendentes primeiro; histórico logo abaixo.</p>
                        </div>
                        <span className="text-xs font-semibold text-slate-400">{movements.length}</span>
                    </div>

                    <div className="mt-4 divide-y divide-slate-100">
                        {orderedMovements.length > 0 ? orderedMovements.map((movement) => {
                            const tone = movementTone(movement);
                            const canOpen = movement.actions.can_view_detail || Boolean(movement.detail);

                            return (
                                <button
                                    key={movement.id}
                                    type="button"
                                    disabled={!canOpen}
                                    onClick={() => canOpen ? setSelectedMovement(movement) : undefined}
                                    className="flex w-full items-center gap-3 py-3 text-left first:pt-0 last:pb-0 disabled:cursor-default"
                                >
                                    <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${movement.status.key === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700'}`}>
                                        {movement.status.key === 'paid' ? <Receipt className="h-4 w-4" /> : <FileText className="h-4 w-4" />}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-semibold text-slate-900">{movement.description}</span>
                                            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[9px] font-semibold ${statusClassMap[movement.status.key]}`}>
                                                {movement.status.label}
                                            </span>
                                        </span>
                                        <span className="mt-1 block truncate text-[11px] text-slate-500">
                                            {movement.type_label || 'Movimento'} · {movement.due_date ? `vence ${formatDate(movement.due_date)}` : formatDate(movement.date)}
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <span className={`block text-sm font-semibold ${amountToneClass(movement.amount, tone)}`}>
                                            {formatSignedCurrency(movement.amount, tone)}
                                        </span>
                                        {canOpen ? <ChevronRight className="ml-auto mt-1 h-3.5 w-3.5 text-slate-300" /> : null}
                                    </span>
                                </button>
                            );
                        }) : (
                            <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                Sem movimentos para apresentar.
                            </div>
                        )}
                    </div>
                </section>

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Recibos recentes</h2>
                            <p className="mt-1 text-xs text-slate-500">Comprovativos dos pagamentos já registados.</p>
                        </div>
                        <Receipt className="h-4 w-4 text-slate-400" />
                    </div>

                    <div className="mt-4 divide-y divide-slate-100">
                        {latest_receipts.length > 0 ? latest_receipts.map((receipt) => (
                            <div key={receipt.id} className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-slate-900">{receipt.receipt_number}</p>
                                    <p className="mt-1 text-[11px] text-slate-500">{formatDate(receipt.date)}</p>
                                </div>
                                <p className={`shrink-0 text-sm font-semibold ${amountToneClass(receipt.amount, 'credit')}`}>
                                    {formatSignedCurrency(receipt.amount, 'credit')}
                                </p>
                            </div>
                        )) : (
                            <p className="text-sm text-slate-500">Sem recibos recentes.</p>
                        )}
                    </div>
                </section>
            </PortalLayout>

            <Dialog open={Boolean(selectedMovement)} onOpenChange={(open) => !open && setSelectedMovement(null)}>
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                    {selectedMovement ? (
                        <>
                            <DialogHeader>
                                <DialogTitle>{selectedMovement.description}</DialogTitle>
                                <DialogDescription>
                                    {selectedMovement.type_label || 'Movimento'} · {formatDate(selectedMovement.date)}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                <Detail label={selectedMovement.amount_label || 'Valor'} value={formatPlainCurrency(selectedMovement.amount)} />
                                <Detail label="Estado" value={selectedMovement.status.label} />
                                <Detail label="Vencimento" value={formatDate(selectedMovement.due_date)} />
                                <Detail label="Referência" value={selectedMovement.receipt_number || selectedMovement.reference || 'Sem referência'} />
                                {selectedMovement.payment_method ? <Detail label="Método" value={selectedMovement.payment_method} /> : null}
                                {selectedMovement.detail?.period ? <Detail label="Período" value={selectedMovement.detail.period} /> : null}
                            </div>

                            {selectedMovement.detail?.items?.length ? (
                                <div className="mt-4 rounded-2xl border border-slate-200">
                                    <div className="border-b border-slate-100 px-3 py-2.5 text-xs font-semibold text-slate-700">Detalhe</div>
                                    <div className="divide-y divide-slate-100">
                                        {selectedMovement.detail.items.map((item) => (
                                            <div key={item.id} className="flex items-start justify-between gap-3 px-3 py-2.5">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-slate-900">{item.description}</p>
                                                    <p className="mt-0.5 text-[11px] text-slate-500">{item.quantity} × {formatPlainCurrency(item.unit_amount)}</p>
                                                </div>
                                                <p className="shrink-0 text-sm font-semibold text-slate-700">{formatPlainCurrency(item.total_amount)}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}

                            {selectedMovement.detail?.notes ? (
                                <p className="mt-4 rounded-2xl bg-slate-50 px-3 py-2.5 text-xs leading-5 text-slate-600">{selectedMovement.detail.notes}</p>
                            ) : null}
                        </>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl bg-slate-50 px-3 py-2.5">
            <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p>
            <p className="mt-1 text-sm font-medium text-slate-800">{value}</p>
        </div>
    );
}
