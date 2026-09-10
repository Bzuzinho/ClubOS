import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface ModuleHeaderProps {
    title: string;
    description?: string;
    context?: ReactNode;
    actions?: ReactNode;
    className?: string;
}

export function ModuleHeader({
    title,
    description,
    context,
    actions,
    className,
}: ModuleHeaderProps) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="min-w-0">
                {context && (
                    <div className="mb-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                        {context}
                    </div>
                )}
                <h1 className="text-lg font-semibold tracking-tight sm:text-xl">{title}</h1>
                {description && (
                    <p className="mt-0.5 max-w-3xl text-xs leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
            )}
        </div>
    );
}
