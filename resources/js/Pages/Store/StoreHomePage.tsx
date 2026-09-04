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
        const featuredIds = new Set(visibleFeatured.map((product) => product.id));
        const categoryProducts = activeCategoryId === 'all'
            ? products
            : products.filter((product) => product.categoria_id === activeCategoryId);

        return categoryProducts.filter((product) => !featuredIds.has(product.id));
    }, [activeCategoryId, products, visibleFeatured]);

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
            toast.error(error instanceof Error ? error.message : 'Não foi possível adicionar ao carrinho.');
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

                <CategoryScroller
                    search={search}
                    onSearchChange={setSearch}
                    onSubmitSearch={applyFilters}
                    categories={categories}
                    activeCategoryId={activeCategoryId}
                    onSelect={(categoryId) => {
                        setActiveCategoryId(categoryId);
                        router.get('/loja', {
                            search: search || undefined,
                            categoria: categoryId === 'all' ? undefined : categoryId,
                        }, {
                            preserveState: true,
                            replace: true,
                        });
                    }}
                />

                {visibleFeatured.length > 0 ? (
                    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)]">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold text-slate-900">Destaques</h2>
                                <p className="mt-1 text-xs text-slate-500">Seleção em evidência na loja do clube.</p>
                            </div>
                        </div>

                        <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                            {visibleFeatured.map((product) => (
                                <div key={`featured-${product.id}`} className={busyProductId === product.id ? 'opacity-70' : ''}>
                                    <ProductCard product={product} onView={(item) => visitStoreProduct(item.slug)} onAdd={handleAddToCart} />
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-slate-900">Coleção</h2>
                            <p className="mt-1 text-xs text-slate-500">Todos os restantes artigos para os filtros atuais.</p>
                        </div>
                        <span className="text-xs font-semibold text-blue-700">{visibleProducts.length}</span>
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                        {visibleProducts.length > 0 ? visibleProducts.map((product) => (
                            <div key={product.id} className={busyProductId === product.id ? 'opacity-70' : ''}>
                                <ProductCard product={product} onView={(item) => visitStoreProduct(item.slug)} onAdd={handleAddToCart} />
                            </div>
                        )) : (
                            <div className="col-span-2 rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-7 text-center text-sm text-slate-500 md:col-span-3 xl:col-span-4">
                                {visibleFeatured.length > 0
                                    ? 'Os artigos disponíveis para estes filtros já estão nos destaques acima.'
                                    : 'Não existem produtos para a pesquisa ou categoria selecionada.'}
                            </div>
                        )}
                    </div>
                </section>
            </PortalLayout>
        </>
    );
}
