import { ShoppingCart } from 'lucide-react';
import { Button } from '@/Components/ui/button';

interface StoreHeaderProps {
    cartCount: number;
    onOpenCart: () => void;
}

export default function StoreHeader({
    cartCount,
    onOpenCart,
}: StoreHeaderProps) {
    return (
        <section className="overflow-hidden rounded-[24px] border border-blue-900/10 bg-[linear-gradient(180deg,#0f57b3_0%,#114c98_100%)] p-4 text-white shadow-[0_14px_28px_rgba(15,76,152,0.18)]">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-blue-100">Clube</p>
                    <h1 className="mt-1 text-xl font-semibold text-white">Loja Oficial</h1>
                </div>

                <Button type="button" className="relative h-10 rounded-2xl border border-white/20 bg-white/10 px-3 text-white hover:bg-white/20" onClick={onOpenCart}>
                    <ShoppingCart className="h-4 w-4 sm:mr-2" />
                    <span className="hidden sm:inline">Carrinho</span>
                    {cartCount > 0 ? (
                        <span className="ml-2 inline-flex min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 py-0.5 text-[11px] font-semibold text-white">
                            {cartCount}
                        </span>
                    ) : null}
                </Button>
            </div>
        </section>
    );
}
