import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { type StoreCategory, type StoreProduct, type StoreVariant, storeRequest } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminProductFormProps {
    product: StoreProduct | null;
    categories: StoreCategory[];
}

type PageProps = SharedPageProps<AdminProductFormProps>;

interface ProductVariantInput extends StoreVariant {
    ativo: boolean;
}

interface ProductFormState {
    categoria_id: string;
    codigo: string;
    nome: string;
    slug: string;
    descricao: string;
    preco: string;
    imagem_principal_path: string;
    ativo: boolean;
    destaque: boolean;
    gere_stock: boolean;
    stock_atual: string;
    stock_minimo: string;
    ordem: string;
    variantes: ProductVariantInput[];
}

function emptyVariant(): ProductVariantInput {
    return {
        id: crypto.randomUUID(),
        nome: '',
        tamanho: '',
        cor: '',
        sku: '',
        preco_extra: 0,
        stock_atual: 0,
        etiqueta: '',
        ativo: true,
    };
}

export default function AdminProductForm() {
    const { props } = usePage<PageProps>();
    const { product, categories } = props;
    const editing = Boolean(product);
    const [submitting, setSubmitting] = useState(false);
    const [form, setForm] = useState<ProductFormState>({
        categoria_id: product?.categoria_id || '',
        codigo: product?.codigo || '',
        nome: product?.nome || '',
        slug: product?.slug || '',
        descricao: product?.descricao || '',
        preco: product ? String(product.preco) : '',
        imagem_principal_path: product?.imagem_principal_path || '',
        ativo: product?.ativo ?? true,
        destaque: product?.destaque ?? false,
        gere_stock: product?.gere_stock ?? true,
        stock_atual: product ? String(product.stock_atual) : '0',
        stock_minimo: product?.stock_minimo != null ? String(product.stock_minimo) : '',
        ordem: product?.ordem != null ? String(product.ordem) : '',
        variantes: product?.variantes?.map((variant) => ({ ...variant, ativo: variant.ativo ?? true })) || [],
    });

    const pageTitle = useMemo(() => (editing ? `Editar ${product?.nome}` : 'Novo produto da Loja'), [editing, product?.nome]);
    const variantStockTotal = useMemo(
        () => form.variantes.reduce((total, variant) => total + Number(variant.stock_atual || 0), 0),
        [form.variantes],
    );

    const updateVariant = (index: number, field: keyof ProductVariantInput, value: string | number | boolean) => {
        setForm((current) => ({
            ...current,
            variantes: current.variantes.map((variant, variantIndex) => variantIndex === index ? { ...variant, [field]: value } : variant),
        }));
    };

    const removeVariant = (index: number) => {
        setForm((current) => ({
            ...current,
            variantes: current.variantes.filter((_, variantIndex) => variantIndex !== index),
        }));
    };

    const submit = async () => {
        try {
            setSubmitting(true);
            const payload = {
                categoria_id: form.categoria_id || null,
                codigo: form.codigo || null,
                nome: form.nome,
                slug: form.slug || null,
                descricao: form.descricao || null,
                preco: Number(form.preco || 0),
                imagem_principal_path: form.imagem_principal_path || null,
                ativo: form.ativo,
                destaque: form.destaque,
                gere_stock: form.gere_stock,
                stock_atual: form.variantes.length > 0 ? variantStockTotal : Number(form.stock_atual || 0),
                stock_minimo: form.stock_minimo === '' ? null : Number(form.stock_minimo),
                ordem: form.ordem === '' ? null : Number(form.ordem),
                variantes: form.variantes.map((variant) => ({
                    id: product?.variantes?.some((item) => item.id === variant.id) ? variant.id : undefined,
                    nome: variant.nome || null,
                    tamanho: variant.tamanho || null,
                    cor: variant.cor || null,
                    sku: variant.sku || null,
                    preco_extra: Number(variant.preco_extra || 0),
                    stock_atual: Number(variant.stock_atual || 0),
                    ativo: variant.ativo,
                })),
            };

            await storeRequest<StoreProduct>(editing ? `/api/admin/loja/produtos/${product?.id}` : '/api/admin/loja/produtos', {
                method: editing ? 'PATCH' : 'POST',
                body: JSON.stringify(payload),
            });

            toast.success(editing ? 'Produto atualizado com sucesso.' : 'Produto criado com sucesso.');
            router.visit('/admin/loja/produtos');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Não foi possível guardar o produto.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <StoreAdminShell
            title={pageTitle}
            description="Produto ligado às categorias existentes do módulo Configurações."
            activeTab="produtos"
            actions={
                <Button type="button" variant="outline" size="sm" onClick={() => router.visit('/admin/loja/produtos')}>
                    Voltar à lista
                </Button>
            }
        >
            <Head title={pageTitle} />

            <div className="space-y-3">
                <div className="grid gap-3 xl:grid-cols-[minmax(0,1.25fr)_360px]">
                    <Card>
                        <CardHeader className="pb-2">
                            <SectionTitle title="Ficha do produto" subtitle="Dados principais, categoria, preço e controlo de stock." />
                        </CardHeader>
                        <CardContent>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Categoria</label>
                                <select value={form.categoria_id} onChange={(event) => setForm((current) => ({ ...current, categoria_id: event.target.value }))} className="mt-2 h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none">
                                    <option value="">Sem categoria</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.nome}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Código</label>
                                <Input value={form.codigo} onChange={(event) => setForm((current) => ({ ...current, codigo: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Nome</label>
                                <Input value={form.nome} onChange={(event) => setForm((current) => ({ ...current, nome: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Slug</label>
                                <Input value={form.slug} onChange={(event) => setForm((current) => ({ ...current, slug: event.target.value }))} placeholder="gerado automaticamente se vazio" className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Preço</label>
                                <Input type="number" min="0" step="0.01" value={form.preco} onChange={(event) => setForm((current) => ({ ...current, preco: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Imagem principal</label>
                                <Input value={form.imagem_principal_path} onChange={(event) => setForm((current) => ({ ...current, imagem_principal_path: event.target.value }))} placeholder="/storage/... ou URL" className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Stock atual</label>
                                <Input type="number" min="0" value={form.variantes.length > 0 ? String(variantStockTotal) : form.stock_atual} disabled={form.variantes.length > 0} onChange={(event) => setForm((current) => ({ ...current, stock_atual: event.target.value }))} className="mt-2" />
                                {form.variantes.length > 0 && <p className="mt-1 text-xs text-slate-500">Calculado pela soma das variantes.</p>}
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Stock mínimo</label>
                                <Input type="number" min="0" value={form.stock_minimo} onChange={(event) => setForm((current) => ({ ...current, stock_minimo: event.target.value }))} className="mt-2" />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ordem</label>
                                <Input type="number" value={form.ordem} onChange={(event) => setForm((current) => ({ ...current, ordem: event.target.value }))} className="mt-2" />
                            </div>
                        </div>

                        <div className="mt-4">
                            <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Descrição</label>
                            <textarea value={form.descricao} onChange={(event) => setForm((current) => ({ ...current, descricao: event.target.value }))} className="mt-2 min-h-[140px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none" />
                        </div>

                        <div className="mt-5 grid gap-3 sm:grid-cols-3">
                            <label className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium text-slate-700">
                                <input type="checkbox" checked={form.ativo} onChange={(event) => setForm((current) => ({ ...current, ativo: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                                Produto ativo
                            </label>
                            <label className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium text-slate-700">
                                <input type="checkbox" checked={form.destaque} onChange={(event) => setForm((current) => ({ ...current, destaque: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                                Destacar na home
                            </label>
                            <label className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium text-slate-700">
                                <input type="checkbox" checked={form.gere_stock} onChange={(event) => setForm((current) => ({ ...current, gere_stock: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                                Gerir stock
                            </label>
                        </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <SectionTitle title="Variantes" subtitle="Tamanho, cor, SKU e stock por variante." />
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between gap-3">
                                <div />
                                <Button type="button" variant="outline" size="sm" onClick={() => setForm((current) => ({ ...current, variantes: [...current.variantes, emptyVariant()] }))}>Adicionar</Button>
                            </div>

                            <div className="mt-4 space-y-3">
                                {form.variantes.length > 0 ? form.variantes.map((variant, index) => (
                                    <div key={variant.id || index} className="rounded-md border border-border bg-slate-50 p-3">
                                        <div className="grid gap-3">
                                            <Input value={variant.nome || ''} onChange={(event) => updateVariant(index, 'nome', event.target.value)} placeholder="Nome da variante" />
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <Input value={variant.tamanho || ''} onChange={(event) => updateVariant(index, 'tamanho', event.target.value)} placeholder="Tamanho" />
                                                <Input value={variant.cor || ''} onChange={(event) => updateVariant(index, 'cor', event.target.value)} placeholder="Cor" />
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-3">
                                                <Input value={variant.sku || ''} onChange={(event) => updateVariant(index, 'sku', event.target.value)} placeholder="SKU" />
                                                <Input type="number" min="0" step="0.01" value={String(variant.preco_extra || 0)} onChange={(event) => updateVariant(index, 'preco_extra', Number(event.target.value))} placeholder="Extra" />
                                                <Input type="number" min="0" value={String(variant.stock_atual || 0)} onChange={(event) => updateVariant(index, 'stock_atual', Number(event.target.value))} placeholder="Stock" />
                                            </div>
                                            <div className="flex items-center justify-between gap-3">
                                                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                                    <input type="checkbox" checked={variant.ativo} onChange={(event) => updateVariant(index, 'ativo', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                                                    Variante ativa
                                                </label>
                                                <Button type="button" variant="outline" size="sm" className="border-rose-200 text-rose-600 hover:bg-rose-50 hover:text-rose-700" onClick={() => removeVariant(index)}>Remover</Button>
                                            </div>
                                        </div>
                                    </div>
                                )) : (
                                    <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">Sem variantes configuradas para este produto.</div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" onClick={() => router.visit('/admin/loja/produtos')}>Cancelar</Button>
                    <Button type="button" disabled={submitting} onClick={submit}>
                        {submitting ? 'A guardar...' : editing ? 'Guardar alterações' : 'Criar produto'}
                    </Button>
                </div>
            </div>
        </StoreAdminShell>
    );
}
