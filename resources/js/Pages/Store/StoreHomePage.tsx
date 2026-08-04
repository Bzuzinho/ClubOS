import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import CategoryScroller from '@/Components/Store/CategoryScroller';
import ProductCard from '@/Components/Store/ProductCard';
import StoreHeader from '@/Components/Store/StoreHeader';
import PortalLayout from '@/Layouts/PortalLayout';
import type { PageProps as SharedPageProps } from '@/types';
import {
    type StoreCart,
    type StoreCategory,
    type StoreProduct,
    type StoreProfileOption,
    storeRequest,
    visitStoreProduct,
} from '@/lib/storeApi';

interface StoreHomePageProps extends Record<string, unknown> {
    categories: StoreCategory[];
    featuredProducts: StoreProduct[];
    products: StoreProduct[];
    filters: {
        search?: string;
        categoria?: string | null;
    };
    cart: StoreCart;
    profiles: StoreProfileOption[];
}

type PageProps = SharedPageProps<StoreHomePageProps> & {
    accessControl?: {
        visibleMenuModules?: string[];
    };
};

export default function StoreHomePage() {
    const { props } = usePage<PageProps>();
    const { auth, clubSettings, accessControl, categories, featuredProducts, products, filters, cart, profiles } = props;
    const [search, setSearch] = useState(filters.search || '');
    const [activeCategoryId, setActiveCategoryId] = useState(filters.categoria || 'all');
    const [localCart, setLocalCart] = useState<StoreCart>(cart);
    const [busyProductId, setBusyProductId] = useState<string | null>(null);

    const isAlsoAdmin = Boolean(accessControl?.visibleMenuModules?.includes('loja'));
    const hasFamily = profiles.length > 1;

    const visibleFeatured = useMemo(() => {
        if (activeCategoryId === 'all') {
            return featuredProducts;
        }

        return featuredProducts.filter((product) => product.categoria_id === activeCategoryId);
    }, [activeCategoryId, featuredProducts]);

    const visibleProducts = useMemo(() => {
        if (activeCategoryId === 'all') {
            return products;
        }

        return products.filter((product) => product.categoria_id === activeCategoryId);
    }, [activeCategoryId, products]);

    const applyFilters = () => {
        router.get('/loja', {
            search: search || undefined,
            categoria: activeCategoryId === 'all' ? undefined : activeCategoryId,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleAddToCart = async (product: StoreProduct) => {
        try {
            setBusyProductId(product.id);
            const nextCart = await storeRequest<StoreCart>('/api/loja/carrinho/itens', {
                method: 'POST',
                body: JSON.stringify({
                    article_id: product.id,
                    quantidade: 1,
                }),
            });

            setLocalCart(nextCart);
            toast.success('Artigo adicionado ao carrinho.');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Nao foi possivel adicionar ao carrinho.');
        } finally {
            setBusyProductId(null);
        }
    };

    return (
        <>
            <Head title="Loja" />
            <PortalLayout
                user={auth.user}
                clubSettings={clubSettings}
                isAlsoAdmin={isAlsoAdmin}
                activeNav="shop"
                hasFamily={hasFamily}
            >
                <StoreHeader
                    cartCount={localCart.count}
                    onOpenCart={() => router.visit('/loja/carrinho')}
                />

                <CategoryScroller search={search} onSearchChange={setSearch} onSubmitSearch={applyFilters} categories={categories} activeCategoryId={activeCategoryId} onSelect={(categoryId) => {
                    setActiveCategoryId(categoryId);
                    router.get('/loja', {
                        search: search || undefined,
                        categoria: categoryId === 'all' ? undefined : categoryId,
                    }, {
                        preserveState: true,
                        replace: true,
                    });
                }} />

                <section>
                    <div className="space-y-4">
                        <section className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">Destaques da semana</h2>
                                    <p className="text-sm text-slate-500">Produtos em evidencia para o portal pessoal.</p>
                                </div>
                            </div>

                            <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {visibleFeatured.length > 0 ? visibleFeatured.map((product) => (
                                    <div key={`featured-${product.id}`} className={busyProductId === product.id ? 'opacity-70' : ''}>
                                        <ProductCard product={product} onView={(item) => visitStoreProduct(item.slug)} onAdd={handleAddToCart} />
                                    </div>
                                )) : (
                                    <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-sm text-slate-500 sm:col-span-2 xl:col-span-3">
                                        Sem destaques ativos para os filtros atuais.
                                    </div>
                                )}
                            </div>
                        </section>

                        <section className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-900">Colecao completa</h2>
                                    <p className="text-sm text-slate-500">Explora todos os artigos ativos da Loja do Clube.</p>
                                </div>
                                <span className="text-sm font-semibold text-blue-700">{visibleProducts.length} artigo(s)</span>
                            </div>

                            <div className="mt-4 grid gap-4 grid-cols-2 xl:grid-cols-4">
                                {visibleProducts.length > 0 ? visibleProducts.map((product) => (
                                    <div key={product.id} className={busyProductId === product.id ? 'opacity-70' : ''}>
                                        <ProductCard product={product} onView={(item) => visitStoreProduct(item.slug)} onAdd={handleAddToCart} />
                                    </div>
                                )) : (
                                    <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-sm text-slate-500 col-span-2 xl:col-span-4">
                                        Nao existem produtos para a pesquisa ou categoria selecionada.
                                    </div>
                                )}
                            </div>
                        </section>
                    </div>

                </section>
            </PortalLayout>
        </>
    );
}
