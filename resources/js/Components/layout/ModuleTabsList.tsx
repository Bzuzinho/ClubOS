import type { ComponentProps } from 'react';
import { TabsList } from '@/Components/ui/tabs';
import { cn } from '@/lib/utils';

interface ModuleTabsListProps extends ComponentProps<typeof TabsList> {
    label?: string;
    containerClassName?: string;
}

export function ModuleTabsList({
    label = 'Navegação do módulo',
    className,
    containerClassName,
    children,
    ...props
}: ModuleTabsListProps) {
    return (
        <nav
            aria-label={label}
            className={cn(
                'w-full overflow-x-auto overscroll-x-contain pb-1',
                containerClassName,
            )}
        >
            <TabsList
                className={cn(
                    'h-10 min-w-max justify-start gap-1 p-1',
                    '[&_[data-slot=tabs-trigger]]:h-8',
                    '[&_[data-slot=tabs-trigger]]:flex-none',
                    '[&_[data-slot=tabs-trigger]]:px-3',
                    '[&_[data-slot=tabs-trigger]]:text-xs',
                    className,
                )}
                {...props}
            >
                {children}
            </TabsList>
        </nav>
    );
}
