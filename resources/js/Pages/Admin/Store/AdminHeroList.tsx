import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreDate, storeRequest } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminHighlightItem {
    id: string;
    produto?: { id: string; nome: string; imagem_principal_path?: string | null } | null;
    ativo: boolean;
    ordem?: number | null;
    data_inicio?: string | null;
    data_fim?: string | null;
}

interface AdminHighlightListProps extends Record<string, unknown> {
    items: AdminHighlightItem[];
}

type PageProps = SharedPageProps<AdminHighlightListProps>;

export default function AdminHeroList() {
    const { items } = usePage<PageProps>().props;
    const [loadingId, setLoadingId] = useState<string | null>(null);

    const toggleItem = async (itemId: string) => {
        try {
            setLoadingId(itemId);
            await storeRequest(`/api/admin/loja/hero/${itemId}/toggle`, { method: 'PATCH', body: JSON.stringify({}) });
            toast.success('Estado do destaque atualizado.');
            router.reload({ only: ['items'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Não foi possível atualizar o destaque.');
        } finally {
            setLoadingId(null);
        }
    };

    return (
        <StoreAdminShell
            title="Destaques da Loja"
            description="Artigos apresentados no card Destaques da loja do utilizador."
            activeTab="hero"
            actions={
                <Button type="button" size="sm" onClick={() => router.visit('/admin/loja/hero/criar')}>
                    Novo destaque
                </Button>
            }
        >
            <Head title="Destaques da Loja" />

            <Card>
                <CardHeader className="pb-2">
                    <SectionTitle title="Artigos destacados" subtitle="Gestão do período, ordem e publicação dos destaques." />
                </CardHeader>
                <CardContent>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {items.length > 0 ? items.map((item) => (
                            <article key={item.id} className="overflow-hidden rounded-lg border border-border bg-white shadow-sm">
                                <div className="aspect-[4/3] bg-slate-100">
                                    {item.produto?.imagem_principal_path ? (
                                        <img src={item.produto.imagem_principal_path} alt={item.produto.nome} className="h-full w-full object-cover" />
                                    ) : (
                                        <div className="flex h-full items-center justify-center text-sm text-slate-400">Sem imagem principal</div>
                                    )}
                                </div>
                                <div className="p-4">
                                    <h2 className="text-base font-semibold text-slate-900">{item.produto?.nome || 'Artigo indisponível'}</h2>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Badge variant="outline" className={item.ativo ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-700'}>
                                            {item.ativo ? 'Ativo' : 'Inativo'}
                                        </Badge>
                                        <Badge variant="outline">Ordem {item.ordem ?? 0}</Badge>
                                    </div>
                                    <p className="mt-3 text-sm text-slate-500">Período: {formatStoreDate(item.data_inicio)} até {formatStoreDate(item.data_fim)}</p>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button type="button" variant="outline" size="sm" onClick={() => router.visit(`/admin/loja/hero/${item.id}/editar`)}>Editar</Button>
                                        <Button type="button" variant="outline" size="sm" disabled={loadingId === item.id} onClick={() => toggleItem(item.id)}>
                                            {loadingId === item.id ? 'A atualizar...' : item.ativo ? 'Desativar' : 'Ativar'}
                                        </Button>
                                    </div>
                                </div>
                            </article>
                        )) : (
                            <article className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                                Ainda não existem artigos configurados como destaque.
                            </article>
                        )}
                    </div>
                </CardContent>
            </Card>
        </StoreAdminShell>
    );
}
