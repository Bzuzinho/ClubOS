import type { StoreCategory } from '@/lib/storeApi';
import { Search } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

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
        <section className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">Categorias</h2>
                    <p className="text-sm text-slate-500">Filtra rapidamente a colecao oficial.</p>
                </div>
            </div>

            <div className="mt-4 flex gap-2">
                <div className="relative min-w-0 flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <Input
                        value={search}
                        onChange={(event) => onSearchChange(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                onSubmitSearch();
                            }
                        }}
                        placeholder="Pesquisar artigos"
                        className="h-11 rounded-2xl border-slate-200 pl-9"
                    />
                </div>
                <Button type="button" className="h-11 rounded-2xl bg-blue-600 px-3 sm:px-4 hover:bg-blue-700" onClick={onSubmitSearch}>
                    <Search className="h-4 w-4 sm:mr-2" />
                    <span className="hidden sm:inline">Pesquisar</span>
                </Button>
            </div>

            <div className="mt-4 flex gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <button
                    type="button"
                    onClick={() => onSelect('all')}
                    className={`rounded-2xl px-4 py-2 text-sm font-semibold transition ${activeCategoryId === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                >
                    Todas
                </button>
                {categories.map((category) => (
                    <button
                        key={category.id}
                        type="button"
                        onClick={() => onSelect(category.id)}
                        className={`whitespace-nowrap rounded-2xl px-4 py-2 text-sm font-semibold transition ${activeCategoryId === category.id ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                    >
                        {category.nome}
                    </button>
                ))}
            </div>
        </section>
    );
}
