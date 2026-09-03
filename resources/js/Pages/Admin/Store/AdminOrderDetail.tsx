import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, formatStoreDate, storeFiscalStatusLabel, storeOrderStatusClass, storeOrderStatusLabel, storePaymentStatusClass, storePaymentStatusLabel, type StoreOrder, storeRequest } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminOrderDetailProps {
    order: StoreOrder;
}

type PageProps = SharedPageProps<AdminOrderDetailProps>;

const statusOptions: StoreOrder['estado'][] = ['pendente', 'aprovado', 'preparado', 'entregue', 'cancelado'];

export default function AdminOrderDetail() {
    const { props } = usePage<PageProps>();
    const { order } = props;
    const [estado, setEstado] = useState<StoreOrder['estado']>(order.estado);
    const [submitting, setSubmitting] = useState(false);
    const [returnReason, setReturnReason] = useState(order.devolucao?.motivo || 'Devolução integral da encomenda entregue.');
    const [returning, setReturning] = useState(false);
    const isTerminal = order.estado === 'cancelado' || order.estado === 'entregue' || order.estado === 'devolvido';
    const availableStatusOptions = isTerminal ? [order.estado] : statusOptions;

    const updateStatus = async () => {
        try {
            setSubmitting(true);
            await storeRequest(`/api/admin/loja/encomendas/${order.id}/estado`, {
                method: 'PATCH',
                body: JSON.stringify({ estado }),
            });
            toast.success('Estado da encomenda atualizado.');
            router.reload({ only: ['order'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Não foi possível atualizar a encomenda.');
        } finally {
            setSubmitting(false);
        }
    };

    const processReturn = async () => {
        try {
            setReturning(true);
            const updated = await storeRequest<StoreOrder>(`/api/admin/loja/encomendas/${order.id}/devolucao`, {
                method: 'POST',
                body: JSON.stringify({ motivo: returnReason }),
            });
            if (updated.estado === 'devolvido') {
                toast.success('Devolução concluída, com reversão financeira/fiscal e reposição de stock.');
            } else {
                toast.success('Pedido de nota de crédito criado. Registe a emissão Wintouch antes de concluir.');
            }
            router.reload({ only: ['order'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Não foi possível processar a devolução.');
        } finally {
            setReturning(false);
        }
    };

    return (
        <StoreAdminShell
            title={order.numero}
            description="Detalhe completo da encomenda e controlo de estado."
            activeTab="encomendas"
            actions={
                <Button type="button" variant="outline" size="sm" onClick={() => router.visit('/admin/loja/encomendas')}>
                    Voltar às encomendas
                </Button>
            }
        >
            <Head title={order.numero} />

            <div className="space-y-3">
                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.25fr)_320px]">
                    <Card>
                        <CardHeader className="pb-2">
                            <SectionTitle title="Detalhe da encomenda" subtitle="Artigos, utilizador e contexto do pedido." />
                        </CardHeader>
                        <CardContent>
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-2xl font-semibold text-slate-900">{order.numero}</h2>
                                    <Badge variant="outline" className={storeOrderStatusClass(order.estado)}>
                                        {storeOrderStatusLabel(order.estado)}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-sm text-slate-500">{formatStoreDate(order.created_at)}</p>
                                <p className="mt-1 text-sm text-slate-600">Utilizador: {order.user?.nome_completo || 'Sem utilizador'}</p>
                                {order.target_user?.nome_completo ? <p className="mt-1 text-sm text-slate-600">Comprar para: {order.target_user.nome_completo}</p> : null}
                            </div>
                            <p className="text-xl font-semibold text-blue-700">{formatStoreCurrency(order.total)}</p>
                        </div>

                        <div className="mt-5 space-y-3">
                            {order.items.map((item) => (
                                <div key={item.id} className="rounded-md border border-border bg-slate-50 px-4 py-3">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{item.descricao}</p>
                                            <p className="mt-1 text-xs text-slate-500">{item.quantidade} x {formatStoreCurrency(item.preco_unitario)}</p>
                                        </div>
                                        <p className="text-sm font-semibold text-blue-700">{formatStoreCurrency(item.total_linha)}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <SectionTitle title="Atualizar estado" subtitle="Fluxo operacional do pedido." />
                            </CardHeader>
                            <CardContent>
                            <select disabled={isTerminal} value={estado} onChange={(event) => setEstado(event.target.value as StoreOrder['estado'])} className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none disabled:cursor-not-allowed disabled:bg-slate-100">
                                {availableStatusOptions.map((status) => (
                                    <option key={status} value={status}>{storeOrderStatusLabel(status)}</option>
                                ))}
                            </select>
                            <Button type="button" disabled={submitting || isTerminal} onClick={updateStatus} className="mt-3 w-full">
                                {isTerminal ? 'Estado terminal' : submitting ? 'A guardar...' : 'Guardar estado'}
                            </Button>
                            </CardContent>
                        </Card>

                        {(order.estado === 'entregue' || order.estado === 'devolvido' || order.devolucao) ? (
                            <Card>
                                <CardHeader className="pb-2">
                                    <SectionTitle title="Devolução" subtitle="Reversão explícita antes de repor o stock." />
                                </CardHeader>
                                <CardContent>
                                    {order.estado !== 'devolvido' ? (
                                        <>
                                            <label htmlFor="store-return-reason" className="text-xs font-semibold uppercase tracking-wide text-slate-500">Motivo</label>
                                            <textarea
                                                id="store-return-reason"
                                                value={returnReason}
                                                onChange={(event) => setReturnReason(event.target.value)}
                                                rows={3}
                                                disabled={Boolean(order.devolucao)}
                                                className="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none disabled:bg-slate-100"
                                            />
                                            <Button type="button" variant="destructive" disabled={returning || returnReason.trim().length < 5} onClick={processReturn} className="mt-3 w-full">
                                                {returning ? 'A processar...' : order.devolucao ? 'Verificar e concluir devolução' : 'Iniciar devolução'}
                                            </Button>
                                        </>
                                    ) : (
                                        <p className="rounded-md border border-violet-100 bg-violet-50 px-3 py-2 text-sm text-violet-800">
                                            Devolução concluída sem apagar o histórico financeiro, fiscal ou de stock.
                                        </p>
                                    )}

                                    {order.devolucao?.estado === 'aguarda_nota_credito' ? (
                                        <div className="mt-3 rounded-md border border-amber-100 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                                            Emitir a nota de crédito no Wintouch e registar o número externo no Financeiro. O stock permanece sem alterações até essa confirmação.
                                            {order.devolucao.nota_credito?.numero_externo ? <span className="mt-1 block font-semibold">Documento: {order.devolucao.nota_credito.numero_externo}</span> : null}
                                        </div>
                                    ) : null}
                                </CardContent>
                            </Card>
                        ) : null}

                        <Card>
                            <CardHeader className="pb-2">
                                <SectionTitle title="Resumo financeiro" />
                            </CardHeader>
                            <CardContent>
                            <div className="mt-4 space-y-3 text-sm text-slate-600">
                                <div className="flex items-center justify-between">
                                    <span>Subtotal</span>
                                    <span className="font-semibold text-slate-900">{formatStoreCurrency(order.subtotal)}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>Total</span>
                                    <span className="font-semibold text-blue-700">{formatStoreCurrency(order.total)}</span>
                                </div>
                                <div className="flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                                    <span>Pagamento</span>
                                    <Badge variant="outline" className={storePaymentStatusClass(order.financeiro.estado_pagamento)}>
                                        {storePaymentStatusLabel(order.financeiro.estado_pagamento)}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span>Pago / em aberto</span>
                                    <span className="font-semibold text-slate-900">{formatStoreCurrency(order.financeiro.valor_pago)} / {formatStoreCurrency(order.financeiro.valor_em_aberto)}</span>
                                </div>
                                <div className="flex items-start justify-between gap-3">
                                    <span>Fiscal</span>
                                    <span className="text-right font-semibold text-slate-900">{storeFiscalStatusLabel(order.financeiro.estado_fiscal)}</span>
                                </div>
                                {order.financeiro.numero_documento_fiscal ? (
                                    <div className="flex items-center justify-between gap-3">
                                        <span>Documento externo</span>
                                        <span className="font-semibold text-slate-900">{order.financeiro.numero_documento_fiscal}</span>
                                    </div>
                                ) : null}
                            </div>

                            {['pendente_emissao', 'em_emissao'].includes(order.financeiro.estado_fiscal) ? (
                                <p className="mt-4 rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-xs leading-5 text-blue-800">
                                    Emitir manualmente no Wintouch e registar depois o número externo no Financeiro.
                                </p>
                            ) : null}

                            {order.observacoes ? (
                                <div className="mt-4 rounded-md border border-border bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                    {order.observacoes}
                                </div>
                            ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </StoreAdminShell>
    );
}
