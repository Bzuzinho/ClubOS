import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { storeRequest } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminHighlightProduct {
    id: string;
    nome: string;
    slug: string;
    imagem?: string | null;
}

interface AdminHighlightItem {
    id: string;
    produto_id?: string | null;
    produto?: AdminHighlightProduct | null;
    ativo: boolean;
    ordem?: number | null;
    data_inicio?: string | null;
    data_fim?: string | null;
}

interface AdminHighlightFormProps extends Record<string, unknown> {
    item: AdminHighlightItem | null;
    products: AdminHighlightProduct[];
}

type PageProps = SharedPageProps<AdminHighlightFormProps>;

export default function AdminHeroForm() {
    const { item, products } = usePage<PageProps>().props;
    const editing = Boolean(item);
    const [submitting, setSubmitting] = useState(false);
    const [form, setForm] = useState({
        produto_id: item?.produto_id || '',
        ativo: item?.ativo ?? true,
        ordem: item?.ordem != null ? String(item.ordem) : '0',
        data_inicio: item?.data_inicio ? item.data_inicio.slice(0, 16) : '',
        data_fim: item?.data_fim ? item.data_fim.slice(0, 16) : '',
    });
    const selectedProduct = useMemo(
        () => products.find((product) => product.id === form.produto_id) ?? item?.produto ?? null,
        [form.produto_id, item?.produto, products],
    );

    const submit = async () => {
        if (!form.produto_id) {
            toast.error('Selecione o artigo a destacar.');
            return;
        }

        try {
            setSubmitting(true);
            await storeRequest(editing ? `/api/admin/loja/hero/${item?.id}` : '/api/admin/loja/hero', {
                method: editing ? 'PATCH' : 'POST',
                body: JSON.stringify({
                    produto_id: form.produto_id,
                    ativo: form.ativo,
                    ordem: Number(form.ordem || 0),
                    data_inicio: form.data_inicio || null,
                    data_fim: form.data_fim || null,
                }),
            });
            toast.success(editing ? 'Destaque atualizado com sucesso.' : 'Destaque criado com sucesso.');
            router.visit('/admin/loja/hero');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Não foi possível guardar o destaque.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <StoreAdminShell
            title={editing ? 'Editar destaque' : 'Novo destaque'}
            description="Escolha o artigo, o período de publicação, a ordem e o estado."
            activeTab="hero"
            actions={
                <Button type="button" variant="outline" size="sm" onClick={() => router.visit('/admin/loja/hero')}>
                    Voltar aos destaques
                </Button>
            }
        >
            <Head title={editing ? 'Editar destaque' : 'Novo destaque'} />

            <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_320px]">
                <Card>
                    <CardHeader className="pb-2">
                        <SectionTitle title="Configuração do destaque" subtitle="O card usa automaticamente o nome e a imagem principal do artigo selecionado." />
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Artigo</label>
                            <select
                                value={form.produto_id}
                                onChange={(event) => setForm((current) => ({ ...current, produto_id: event.target.value }))}
                                className="mt-2 h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none"
                            >
                                <option value="">Selecionar artigo</option>
                                {products.map((product) => <option key={product.id} value={product.id}>{product.nome}</option>)}
                            </select>
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Data de início</label>
                                <Input type="datetime-local" value={form.data_inicio} onChange={(event) => setForm((current) => ({ ...current, data_inicio: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Data de fim</label>
                                <Input type="datetime-local" value={form.data_fim} onChange={(event) => setForm((current) => ({ ...current, data_fim: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ordem</label>
                                <Input type="number" min="0" value={form.ordem} onChange={(event) => setForm((current) => ({ ...current, ordem: event.target.value }))} className="mt-2" />
                            </div>
                        </div>

                        <label className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium text-slate-700">
                            <input type="checkbox" checked={form.ativo} onChange={(event) => setForm((current) => ({ ...current, ativo: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                            Destaque ativo
                        </label>
                    </CardContent>
                </Card>

                <div className="space-y-3">
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-2">
                            <SectionTitle title="Artigo selecionado" subtitle="Pré-visualização da imagem já configurada no catálogo." />
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                {selectedProduct?.imagem ? (
                                    <img src={selectedProduct.imagem} alt={selectedProduct.nome} className="aspect-[4/3] w-full object-cover" />
                                ) : (
                                    <div className="flex aspect-[4/3] items-center justify-center text-sm text-slate-400">Sem imagem principal</div>
                                )}
                                <p className="p-3 text-sm font-semibold text-slate-900">{selectedProduct?.nome || 'Nenhum artigo selecionado'}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => router.visit('/admin/loja/hero')}>Cancelar</Button>
                        <Button type="button" disabled={submitting} onClick={submit}>
                            {submitting ? 'A guardar...' : editing ? 'Guardar destaque' : 'Criar destaque'}
                        </Button>
                    </div>
                </div>
            </div>
        </StoreAdminShell>
    );
}
