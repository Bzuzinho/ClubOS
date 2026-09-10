import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export type SportsNavigationItem = { label: string; href: string; active: boolean };

export function SportsNavigation() {
    const { sportsNavigation = [] } = usePage<{ sportsNavigation?: SportsNavigationItem[] }>().props;
    if (sportsNavigation.length === 0) return null;

    return (
        <div className="mb-3 flex min-w-0 flex-wrap items-start gap-2">
            <nav aria-label="Áreas do Desportivo" className="flex min-w-0 flex-1 flex-wrap gap-1 rounded-lg bg-muted p-1">
                {sportsNavigation.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={item.active ? 'page' : undefined}
                        className={cn(
                            'flex min-h-8 min-w-0 flex-[1_1_7rem] items-center justify-center whitespace-normal break-words rounded-md px-2 py-1 text-center text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring',
                            item.active ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:bg-background/60',
                        )}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
