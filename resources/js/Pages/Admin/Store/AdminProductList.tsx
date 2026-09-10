import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Card, CardContent, CardHeader } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { SectionTitle } from '@/components/sports/shared';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, type StoreCategory, type StoreProduct } from '@/lib/storeApi';
import { StoreAdminShell } from './StoreAdminShell';

interface AdminProductListProps {
    products: StoreProduct[];
    categories: StoreCategory[];
    filters: {
        search?: string;
        categoria_id?: string;
        publicado?: string;
        stock_baixo?: string;
    };
}

type PageProps = SharedPageProps<AdminProductListProps>;

export default function AdminProductList() {
    const { props } = usePage<PageProps>();
    const { products, categories, filters } = props;
    const [search, setSearch] = useState(filters.search || '');
    const [categoriaId, setCategoriaId] = useState(filters.categoria_id || 'all');
    const [publicado, setPublicado] = useState(filters.publicado || 'all');
    const [stockBaixo, setStockBaixo] = useState(filters.stock_baixo === '1');

    const filteredCount = useMemo(() => products.length, [products]);

    const applyFilters = () => {
        router.get('/admin/loja/produtos', {
            search: search || undefined,
            categoria_id: categoriaId === 'all' ? undefined : categoriaId,
            publicado: publicado === 'all' ? undefined : publicado,
            stock_baixo: stockBaixo ? 1 : undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <StoreAdminShell
            title="Produtos da Loja"
            description="Publicação, preço comercial e apresentação dos artigos do catálogo canónico."
            activeTab="produtos"
            actions={
                <Button type="button" size="sm" onClick={() => router.visit('/admin/loja/produtos/criar')}>
                    Novo produto
                </Button>
            }
        >
            <Head title="Produtos da Loja" />

            <div className="space-y-3">
                <Card>
                    <CardHeader className="pb-2">
                        <SectionTitle title="Filtros" subtitle="Catálogo, categoria, publicação e alertas de stock." />
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 xl:flex-1">
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Pesquisar</label>
                                <Input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Nome, código ou descrição"
                                    className="mt-2"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Categoria</label>
                                <select
                                    value={categoriaId}
                                    onChange={(event) => setCategoriaId(event.target.value)}
                                    className="mt-2 h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none"
                                >
                                    <option value="all">Todas</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>{category.nome}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Estado</label>
                                <select
                                    value={publicado}
                                    onChange={(event) => setPublicado(event.target.value)}
                                    className="mt-2 h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none"
                                >
                                    <option value="all">Todos</option>
                                    <option value="1">Publicados</option>
                                    <option value="0">Não publicados</option>
                                </select>
                            </div>
                            <label className="flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium text-slate-700 xl:mt-7">
                                <input type="checkbox" checked={stockBaixo} onChange={(event) => setStockBaixo(event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-blue-600" />
                                Só stock baixo
                            </label>
                        </div>

                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={applyFilters}>
                                Aplicar filtros
                                </Button>
                                <Button type="button" variant="outline" size="sm" onClick={() => router.visit('/configuracoes?tab=logistica&subtab=logistica-categorias')}>
                                    Gerir categorias
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <SectionTitle title="Catálogo" subtitle={`${filteredCount} produto(s) encontrados.`} />
                    </CardHeader>
                    <CardContent>
                        <div className="min-w-0 rounded-md border border-border">
                            <Table responsive className="divide-y divide-border text-sm">
                            <TableHeader className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <TableRow>
                                    <TableHead className="px-5 py-3">Produto</TableHead>
                                    <TableHead className="px-5 py-3">Categoria</TableHead>
                                    <TableHead className="px-5 py-3">Preço</TableHead>
                                    <TableHead className="px-5 py-3">Stock</TableHead>
                                    <TableHead className="px-5 py-3">Estado</TableHead>
                                    <TableHead className="px-5 py-3">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-border bg-white">
                                {products.length > 0 ? products.map((product) => (
                                    <TableRow key={product.id} className="hover:bg-slate-50">
                                        <TableCell label="Produto" className="px-5 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-100 text-xs text-slate-400">
                                                    {product.imagem_principal_path ? <img src={product.imagem_principal_path} alt={product.nome} className="h-full w-full object-cover" /> : 'IMG'}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="font-semibold text-slate-900">{product.nome}</p>
                                                    <p className="text-xs text-slate-500">{product.codigo || product.slug}</p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell label="Categoria" className="px-5 py-4 text-slate-600">{product.categoria?.nome || 'Sem categoria'}</TableCell>
                                        <TableCell label="Preço" className="px-5 py-4 font-semibold text-blue-700">{formatStoreCurrency(product.preco)}</TableCell>
                                        <TableCell label="Stock" className="px-5 py-4 text-slate-600">
                                            {product.gere_stock ? `${product.stock_atual} un.` : 'Sem gestão'}
                                            {product.tem_stock_baixo ? <span className="ml-2 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">Baixo</span> : null}
                                        </TableCell>
                                        <TableCell label="Estado" className="px-5 py-4">
                                            <div className="flex flex-wrap gap-2">
                                                <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${product.publicado ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-700'}`}>
                                                    {product.publicado ? 'Publicado' : 'Não publicado'}
                                                </span>
                                                {!product.ativo ? <span className="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700">Artigo inativo</span> : null}
                                            </div>
                                        </TableCell>
                                        <TableCell label="Ações" className="px-5 py-4">
                                            <Button type="button" variant="outline" size="sm" onClick={() => router.visit(`/admin/loja/produtos/${product.id}/editar`)}>
                                                Editar
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                )) : (
                                    <TableRow>
                                        <TableCell colSpan={6} className="px-5 py-10 text-center text-sm text-slate-500">Ainda não existem produtos no novo catálogo da Loja.</TableCell>
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
