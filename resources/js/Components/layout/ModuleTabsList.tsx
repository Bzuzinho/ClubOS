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
                'w-full min-w-0 shrink-0 pb-1',
                containerClassName,
            )}
        >
            <TabsList
                className={cn(
                    'flex h-auto w-full min-w-0 flex-wrap justify-start gap-1 p-1',
                    '[&_[data-slot=tabs-trigger]]:h-auto',
                    '[&_[data-slot=tabs-trigger]]:min-h-8',
                    '[@media(pointer:coarse)]:[&_[data-slot=tabs-trigger]]:min-h-11',
                    '[&_[data-slot=tabs-trigger]]:min-w-0',
                    '[&_[data-slot=tabs-trigger]]:whitespace-normal',
                    '[&_[data-slot=tabs-trigger]]:break-words',
                    '[&_[data-slot=tabs-trigger]]:py-2',
                    '[&_[data-slot=tabs-trigger]]:leading-snug',
                    '[&_[data-slot=tabs-trigger]>span]:min-w-0',
                    '[&_[data-slot=tabs-trigger]>span]:[overflow-wrap:anywhere]',
                    '[&_[data-slot=tabs-trigger]]:flex-[1_1_7rem]',
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
