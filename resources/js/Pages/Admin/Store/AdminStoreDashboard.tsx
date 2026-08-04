import { Head, router, usePage } from '@inertiajs/react';
import { ArrowRight, BellRing, Package, ShoppingBag, Truck, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { SmallMetric, SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, formatStoreDate, storeOrderStatusClass, storeOrderStatusLabel } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface DashboardOrder {
    id: string;
    numero: string;
    estado: string;
    total: number;
    created_at?: string | null;
    user?: string | null;
    target_user?: string | null;
}

interface AdminStoreDashboardProps extends Record<string, unknown> {
    dashboard: {
        total_produtos_ativos: number;
        produtos_sem_stock: number;
        encomendas_pendentes: number;
        encomendas_preparadas: number;
        ultimos_pedidos: DashboardOrder[];
    };
}

type PageProps = SharedPageProps<AdminStoreDashboardProps>;

export default function AdminStoreDashboard() {
    const { props } = usePage<PageProps>();
    const { dashboard } = props;
    const storeAlerts = (props.communicationAlerts?.recent ?? []).filter((alert) => alert.link?.startsWith('/admin/loja/encomendas/'));
    const unreadStoreAlerts = storeAlerts.filter((alert) => !alert.is_read);

    return (
        <StoreAdminShell
            title="Loja do Clube"
            description="Dashboard administrativo da Loja integrado no ClubOS."
            activeTab="dashboard"
        >
            <Head title="Administração da Loja" />

            <div className="space-y-3">
                {unreadStoreAlerts.length > 0 ? (
                    <Alert className="border-amber-200 bg-amber-50 text-amber-950">
                        <BellRing className="h-4 w-4 text-amber-600" />
                        <AlertTitle>Novas encomendas para validar</AlertTitle>
                        <AlertDescription>
                            <div className="space-y-3">
                                <p>
                                    Existem {unreadStoreAlerts.length} alerta(s) de nova compra na loja do utilizador por tratar.
                                </p>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {unreadStoreAlerts.slice(0, 3).map((alert) => (
                                        <button
                                            key={alert.id}
                                            type="button"
                                            onClick={() => router.visit(alert.link || '/admin/loja/encomendas')}
                                            className="rounded-md border border-amber-200 bg-white px-3 py-2 text-left transition hover:border-amber-300 hover:bg-amber-100/60"
                                        >
                                            <span className="block text-sm font-semibold text-slate-900">{alert.title}</span>
                                            <span className="mt-1 block text-xs text-slate-600">{alert.message}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="grid gap-2 grid-cols-2 xl:grid-cols-4">
                    <SmallMetric label="Produtos ativos" value={dashboard.total_produtos_ativos} hint="catálogo publicado" icon={<Package className="h-4 w-4 text-blue-600" />} />
                    <SmallMetric label="Sem stock" value={dashboard.produtos_sem_stock} hint="reposições pendentes" icon={<TriangleAlert className="h-4 w-4 text-amber-600" />} />
                    <SmallMetric label="Pendentes" value={dashboard.encomendas_pendentes} hint="a validar" icon={<ShoppingBag className="h-4 w-4 text-sky-600" />} />
                    <SmallMetric label="Preparadas" value={dashboard.encomendas_preparadas} hint="prontas a entregar" icon={<Truck className="h-4 w-4 text-emerald-600" />} />
                </div>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.35fr)_320px]">
                    <Card>
                        <CardHeader className="pb-2">
                            <SectionTitle
                                title="Últimos pedidos"
                                subtitle="Pedidos recentes submetidos a partir do portal pessoal."
                                right={
                                    <Button type="button" variant="outline" size="sm" onClick={() => router.visit('/admin/loja/encomendas')}>
                                        Ver encomendas
                                    </Button>
                                }
                            />
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-md border border-border">
                                <table className="min-w-full divide-y divide-border text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Pedido</th>
                                        <th className="px-4 py-3">Utilizador</th>
                                        <th className="px-4 py-3">Estado</th>
                                        <th className="px-4 py-3">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border bg-white">
                                    {dashboard.ultimos_pedidos.length > 0 ? dashboard.ultimos_pedidos.map((order) => (
                                        <tr key={order.id} className="hover:bg-slate-50">
                                            <td className="px-4 py-3">
                                                <button
                                                    type="button"
                                                    onClick={() => router.visit(`/admin/loja/encomendas/${order.id}`)}
                                                    className="text-left"
                                                >
                                                    <span className="block font-semibold text-slate-900">{order.numero}</span>
                                                    <span className="block text-xs text-slate-500">{formatStoreDate(order.created_at)}</span>
                                                </button>
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                <span className="block">{order.user || 'Sem utilizador'}</span>
                                                {order.target_user ? <span className="block text-xs text-slate-400">Para {order.target_user}</span> : null}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${storeOrderStatusClass(order.estado)}`}>
                                                    {storeOrderStatusLabel(order.estado)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-blue-700">{formatStoreCurrency(order.total)}</td>
                                        </tr>
                                    )) : (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-8 text-center text-sm text-slate-500">
                                                Ainda não existem pedidos na Loja.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="space-y-3">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Atalhos rápidos</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                <Button type="button" className="justify-between" onClick={() => router.visit('/admin/loja/produtos')}>
                                    Gerir produtos
                                    <ArrowRight className="h-4 w-4" />
                                </Button>
                                <Button type="button" variant="outline" className="justify-between" onClick={() => router.visit('/admin/loja/hero')}>
                                    Gerir destaques
                                    <ArrowRight className="h-4 w-4" />
                                </Button>
                                <Button type="button" variant="outline" className="justify-between" onClick={() => router.visit('/admin/loja/encomendas')}>
                                    Ver encomendas
                                    <ArrowRight className="h-4 w-4" />
                                </Button>
                            </CardContent>
                        </Card>

                        <Card className="border-blue-200 bg-gradient-to-br from-blue-600 to-sky-500 text-white">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm text-white">Loja integrada no portal</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-xs text-blue-100">
                                <p>Destaques, catálogo, carrinho e encomendas usam a mesma lógica de dados do utilizador autenticado.</p>
                                <p>Esta área mantém gestão administrativa sem abrir uma loja externa ao ClubOS.</p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </StoreAdminShell>
    );
}
