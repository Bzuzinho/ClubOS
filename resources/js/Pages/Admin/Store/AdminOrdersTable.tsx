import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Head, router, usePage } from '@inertiajs/react';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, formatStoreDate, storeOrderStatusClass, storeOrderStatusLabel, storePaymentStatusClass, storePaymentStatusLabel, type StoreOrder } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminOrdersTableProps {
    orders: StoreOrder[];
    filters: {
        estado?: string;
        user_id?: string;
    };
}

type PageProps = SharedPageProps<AdminOrdersTableProps>;

const statuses = ['all', 'pendente', 'aprovado', 'preparado', 'entregue', 'devolvido', 'cancelado'];

export default function AdminOrdersTable() {
    const { props } = usePage<PageProps>();
    const { orders, filters } = props;
    const activeStatus = filters.estado || 'all';

    return (
        <StoreAdminShell
            title="Encomendas da Loja"
            description="Todas as encomendas submetidas pelos utilizadores autenticados."
            activeTab="encomendas"
        >
            <Head title="Encomendas da Loja" />

            <div className="space-y-3">
                <Card>
                    <CardHeader className="pb-2">
                        <SectionTitle title="Estados" subtitle="Filtrar rapidamente o pipeline operacional da Loja." />
                    </CardHeader>
                    <CardContent>
                    <div className="flex flex-wrap gap-2">
                        {statuses.map((status) => (
                            <button
                                key={status}
                                type="button"
                                onClick={() => router.get('/admin/loja/encomendas', { estado: status === 'all' ? undefined : status }, { preserveState: true, replace: true })}
                                className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${activeStatus === status ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                            >
                                {status === 'all' ? 'Todos' : storeOrderStatusLabel(status)}
                            </button>
                        ))}
                    </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <SectionTitle title="Lista de encomendas" subtitle={`${orders.length} encomenda(s) no filtro atual.`} />
                    </CardHeader>
                    <CardContent>
                        <div className="min-w-0 rounded-md border border-border">
                            <Table responsive className="divide-y divide-border text-sm">
                            <TableHeader className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <TableRow>
                                    <TableHead className="px-5 py-3">Pedido</TableHead>
                                    <TableHead className="px-5 py-3">Utilizador</TableHead>
                                    <TableHead className="px-5 py-3">Estado</TableHead>
                                    <TableHead className="px-5 py-3">Pagamento</TableHead>
                                    <TableHead className="px-5 py-3">Data</TableHead>
                                    <TableHead className="px-5 py-3">Total</TableHead>
                                    <TableHead className="px-5 py-3">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-border bg-white">
                                {orders.length > 0 ? orders.map((order) => (
                                    <TableRow key={order.id} className="hover:bg-slate-50">
                                        <TableCell label="Pedido" className="px-5 py-4 font-semibold text-slate-900">{order.numero}</TableCell>
                                        <TableCell label="Utilizador" className="px-5 py-4 text-slate-600">
                                            <span className="block">{order.user?.nome_completo || 'Sem utilizador'}</span>
                                            {order.target_user?.nome_completo ? <span className="block text-xs text-slate-400">Para {order.target_user.nome_completo}</span> : null}
                                        </TableCell>
                                        <TableCell label="Estado" className="px-5 py-4">
                                            <Badge variant="outline" className={storeOrderStatusClass(order.estado)}>
                                                {storeOrderStatusLabel(order.estado)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell label="Pagamento" className="px-5 py-4">
                                            <Badge variant="outline" className={storePaymentStatusClass(order.financeiro.estado_pagamento)}>
                                                {storePaymentStatusLabel(order.financeiro.estado_pagamento)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell label="Data" className="px-5 py-4 text-slate-600">{formatStoreDate(order.created_at)}</TableCell>
                                        <TableCell label="Total" className="px-5 py-4 font-semibold text-blue-700">{formatStoreCurrency(order.total)}</TableCell>
                                        <TableCell label="Ações" className="px-5 py-4">
                                            <button type="button" onClick={() => router.visit(`/admin/loja/encomendas/${order.id}`)} className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">Abrir</button>
                                        </TableCell>
                                    </TableRow>
                                )) : (
                                    <TableRow>
                                        <TableCell colSpan={7} className="px-5 py-10 text-center text-sm text-slate-500">Não existem encomendas com este filtro.</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </StoreAdminShell>
    );
}
