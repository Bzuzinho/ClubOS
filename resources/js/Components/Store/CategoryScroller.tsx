import type { StoreCategory } from '@/lib/storeApi';
import { Search } from 'lucide-react';

interface CategoryScrollerProps {
    categories: StoreCategory[];
    activeCategoryId: string;
    onSelect: (categoryId: string) => void;
    search: string;
    onSearchChange: (value: string) => void;
    onSubmitSearch: () => void;
}

export default function CategoryScroller({ categories, activeCategoryId, onSelect, search, onSearchChange, onSubmitSearch }: CategoryScrollerProps) {
    return (
        <section className="rounded-[22px] border border-slate-200 bg-white p-3.5 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-4">
            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                    value={search}
                    onChange={(event) => onSearchChange(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            onSubmitSearch();
                        }
                    }}
                    placeholder="Pesquisar artigos"
                    className="h-10 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-10 text-sm text-slate-900 outline-none transition focus:border-blue-300"
                />
                <button
                    type="button"
                    onClick={onSubmitSearch}
                    className="absolute right-1 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg bg-blue-600 text-white transition hover:bg-blue-700"
                    aria-label="Pesquisar artigos"
                >
                    <Search className="h-3.5 w-3.5" />
                </button>
            </div>

            <div className="mt-3 flex gap-2 overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <button
                    type="button"
                    onClick={() => onSelect('all')}
                    className={`shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold transition ${activeCategoryId === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                >
                    Todas
                </button>
                {categories.map((category) => (
                    <button
                        key={category.id}
                        type="button"
                        onClick={() => onSelect(category.id)}
                        className={`shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold transition ${activeCategoryId === category.id ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                    >
                        {category.nome}
                    </button>
                ))}
            </div>
        </section>
    );
}
