import { Head, router, usePage } from '@inertiajs/react';
import EmptyOrdersState from '@/Components/Store/EmptyOrdersState';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, formatStoreDate, storeOrderStatusClass, storeOrderStatusLabel, storePaymentStatusClass, storePaymentStatusLabel, type StoreOrder } from '@/lib/storeApi';

interface OrderHistoryPageProps {
    orders: StoreOrder[];
    filter: {
        estado?: string | null;
    };
}

type PageProps = SharedPageProps<OrderHistoryPageProps> & {
    accessControl?: {
        visibleMenuModules?: string[];
    };
};

const statusFilters = [
    { value: 'all', label: 'Todos' },
    { value: 'pendente', label: 'Pendentes' },
    { value: 'aprovado', label: 'Aprovados' },
    { value: 'preparado', label: 'Preparados' },
    { value: 'entregue', label: 'Entregues' },
    { value: 'cancelado', label: 'Cancelados' },
];

export default function OrderHistoryPage() {
    const { props } = usePage<PageProps>();
    const { auth, clubSettings, accessControl, orders, filter } = props;
    const activeFilter = filter.estado || 'all';

    return (
        <>
            <Head title="Historico da Loja" />
            <PortalLayout user={auth.user} clubSettings={clubSettings} isAlsoAdmin={Boolean(accessControl?.visibleMenuModules?.includes('loja'))} activeNav="shop" hasFamily={false}>
                <section className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h1 className="text-xl font-semibold text-slate-900">Historico de encomendas</h1>
                            <p className="text-sm text-slate-500">Consulta apenas as encomendas do utilizador autenticado e dos perfis autorizados.</p>
                        </div>
                        <div className="flex gap-2 overflow-x-auto pb-1">
                            {statusFilters.map((item) => (
                                <button
                                    key={item.value}
                                    type="button"
                                    onClick={() => router.get('/loja/historico', { estado: item.value === 'all' ? undefined : item.value }, { preserveState: true, replace: true })}
                                    className={`rounded-2xl px-4 py-2 text-sm font-semibold transition ${activeFilter === item.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                {orders.length === 0 ? (
                    <EmptyOrdersState />
                ) : (
                    <section className="space-y-3">
                        {orders.map((order) => (
                            <article key={order.id} className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-base font-semibold text-slate-900">{order.numero}</h2>
                                            <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${storeOrderStatusClass(order.estado)}`}>
                                                {storeOrderStatusLabel(order.estado)}
                                            </span>
                                            <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${storePaymentStatusClass(order.financeiro.estado_pagamento)}`}>
                                                {storePaymentStatusLabel(order.financeiro.estado_pagamento)}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-slate-500">{formatStoreDate(order.created_at)} {order.target_user?.nome_completo ? `· Para ${order.target_user.nome_completo}` : ''}</p>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <p className="text-lg font-semibold text-blue-700">{formatStoreCurrency(order.total)}</p>
                                        <button
                                            type="button"
                                            onClick={() => router.visit(`/loja/historico/${order.id}`)}
                                            className="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            Ver detalhe
                                        </button>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </section>
                )}
            </PortalLayout>
        </>
    );
}
