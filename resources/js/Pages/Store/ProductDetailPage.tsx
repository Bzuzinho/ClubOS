import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, ShoppingBag } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps as SharedPageProps } from '@/types';
import { formatStoreCurrency, type StoreCart, type StoreProduct, storeRequest } from '@/lib/storeApi';

interface ProductDetailPageProps {
    product: StoreProduct;
}

type PageProps = SharedPageProps<ProductDetailPageProps> & {
    accessControl?: {
        visibleMenuModules?: string[];
    };
};

export default function ProductDetailPage() {
    const { props } = usePage<PageProps>();
    const { auth, clubSettings, accessControl, product } = props;
    const [selectedVariantId, setSelectedVariantId] = useState<string>(product.variantes[0]?.id || '');
    const [quantity, setQuantity] = useState(1);
    const [loading, setLoading] = useState(false);

    const selectedVariant = useMemo(
        () => product.variantes.find((variant) => variant.id === selectedVariantId) || null,
        [product.variantes, selectedVariantId],
    );

    const unitPrice = product.preco + (selectedVariant?.preco_extra || 0);
    const availableStock = selectedVariant?.stock_atual ?? product.stock_atual;
    const requiresVariant = product.variantes.length > 0;
    const canAddToCart = (!requiresVariant || Boolean(selectedVariant))
        && (!product.gere_stock || availableStock >= quantity);
    const isAlsoAdmin = Boolean(accessControl?.visibleMenuModules?.includes('loja'));

    useEffect(() => {
        if (product.gere_stock && quantity > availableStock) {
            setQuantity(Math.max(1, availableStock));
        }
    }, [availableStock, product.gere_stock, quantity]);

    const addToCart = async () => {
        try {
            setLoading(true);
            await storeRequest<StoreCart>('/api/loja/carrinho/itens', {
                method: 'POST',
                body: JSON.stringify({
                    article_id: product.id,
                    product_variant_id: selectedVariantId || null,
                    quantidade: quantity,
                }),
            });

            toast.success('Produto adicionado ao carrinho.');
            router.visit('/loja/carrinho');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Nao foi possivel atualizar o carrinho.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title={product.nome} />
            <PortalLayout user={auth.user} clubSettings={clubSettings} isAlsoAdmin={isAlsoAdmin} activeNav="shop" hasFamily={false}>
                <button
                    type="button"
                    onClick={() => router.visit('/loja')}
                    className="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-blue-200 hover:text-blue-700"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Voltar a Loja
                </button>

                <section className="grid gap-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.95fr)]">
                    <article className="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_12px_28px_rgba(15,23,42,0.06)]">
                        <div className="aspect-[4/3] bg-slate-100">
                            {product.imagem_principal_path ? (
                                <img src={product.imagem_principal_path} alt={product.nome} className="h-full w-full object-cover" />
                            ) : (
                                <div className="flex h-full items-center justify-center text-sm text-slate-400">Sem imagem</div>
                            )}
                        </div>
                    </article>

                    <article className="rounded-[28px] border border-slate-200 bg-white p-5 shadow-[0_12px_28px_rgba(15,23,42,0.06)]">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-600">{product.categoria?.nome || 'Loja do Clube'}</p>
                        <h1 className="mt-2 text-3xl font-semibold text-slate-900">{product.nome}</h1>
                        <p className="mt-3 text-base font-semibold text-blue-700">{formatStoreCurrency(unitPrice)}</p>
                        <p className="mt-4 text-sm leading-6 text-slate-600">{product.descricao || 'Artigo oficial do clube preparado para pedido e acompanhamento dentro do portal pessoal.'}</p>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Stock</p>
                                <p className="mt-2 text-sm font-semibold text-slate-900">{product.gere_stock ? `${availableStock} unidades` : 'Disponível sob consulta'}</p>
                            </div>
                            <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Codigo</p>
                                <p className="mt-2 text-sm font-semibold text-slate-900">{product.codigo || 'Sem codigo'}</p>
                            </div>
                        </div>

                        {product.variantes.length > 0 ? (
                            <div className="mt-5">
                                <label className="text-sm font-semibold text-slate-900">Variante</label>
                                <Select value={selectedVariantId} onValueChange={setSelectedVariantId}>
                                    <SelectTrigger className="mt-2 h-11 rounded-2xl">
                                        <SelectValue placeholder="Escolher variante" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {product.variantes.map((variant) => (
                                            <SelectItem key={variant.id} value={variant.id}>
                                                {variant.etiqueta || variant.nome || 'Variante'} · {variant.stock_atual} disponíveis
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : null}

                        <div className="mt-5 flex items-center gap-2">
                            <Button type="button" variant="outline" size="icon" className="h-10 w-10 rounded-xl" disabled={quantity <= 1} onClick={() => setQuantity((current) => Math.max(1, current - 1))}>-</Button>
                            <div className="flex h-10 min-w-14 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-900">{quantity}</div>
                            <Button type="button" variant="outline" size="icon" className="h-10 w-10 rounded-xl" disabled={product.gere_stock && quantity >= availableStock} onClick={() => setQuantity((current) => current + 1)}>+</Button>
                        </div>

                        <div className="mt-6 grid gap-2">
                            <Button type="button" className="h-11 rounded-2xl bg-blue-600 hover:bg-blue-700" disabled={loading || !canAddToCart} onClick={addToCart}>
                                <ShoppingBag className="mr-2 h-4 w-4" />
                                {loading ? 'A adicionar...' : canAddToCart ? 'Adicionar ao carrinho' : 'Sem stock disponível'}
                            </Button>
                            <Button type="button" variant="outline" className="h-11 rounded-2xl" onClick={() => router.visit('/loja/carrinho')}>
                                Abrir carrinho
                            </Button>
                        </div>
                    </article>
                </section>
            </PortalLayout>
        </>
    );
}
