import { Eye, ShoppingBag } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import type { StoreProduct } from '@/lib/storeApi';
import { formatStoreCurrency } from '@/lib/storeApi';

interface ProductCardProps {
    product: StoreProduct;
    onView: (product: StoreProduct) => void;
    onAdd: (product: StoreProduct) => void;
}

function stockLabel(product: StoreProduct): { label: string; className: string } {
    if (product.stock_atual <= 0) {
        return { label: 'Sem stock', className: 'bg-rose-50 text-rose-700' };
    }

    return { label: `${product.stock_atual} em stock`, className: 'bg-sky-50 text-sky-700' };
}

export default function ProductCard({ product, onView, onAdd }: ProductCardProps) {
    const badge = stockLabel(product);

    return (
        <article className="overflow-hidden rounded-[20px] border border-slate-200 bg-white shadow-[0_6px_18px_rgba(15,23,42,0.04)]">
            <button type="button" onClick={() => onView(product)} className="block w-full text-left">
                <div className="aspect-square bg-slate-100 sm:aspect-[4/3]">
                    {product.imagem_principal_path ? (
                        <img src={product.imagem_principal_path} alt={product.nome} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full items-center justify-center text-xs text-slate-400">Sem imagem</div>
                    )}
                </div>
            </button>

            <div className="p-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="line-clamp-2 text-sm font-semibold leading-5 text-slate-900">{product.nome}</p>
                        <p className="mt-0.5 truncate text-[10px] text-slate-500">{product.categoria?.nome || 'Coleção oficial'}</p>
                    </div>
                    <span className={`shrink-0 rounded-full px-2 py-1 text-[9px] font-semibold ${badge.className}`}>
                        {badge.label}
                    </span>
                </div>

                <div className="mt-3 flex items-center justify-between gap-2">
                    <p className="text-base font-semibold text-blue-700">{formatStoreCurrency(product.preco)}</p>
                    <div className="flex gap-1.5">
                        <Button type="button" size="icon" variant="outline" className="h-8 w-8 rounded-xl" onClick={() => onView(product)} aria-label={`Ver ${product.nome}`} title="Ver artigo">
                            <Eye className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            className="h-8 w-8 rounded-xl bg-blue-600 hover:bg-blue-700"
                            disabled={product.gere_stock && product.stock_atual <= 0}
                            onClick={() => onAdd(product)}
                            aria-label={`Adicionar ${product.nome} ao carrinho`}
                            title="Adicionar ao carrinho"
                        >
                            <ShoppingBag className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
            </div>
        </article>
    );
}
