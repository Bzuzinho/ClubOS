import type { ReactNode } from 'react';

interface PortalSectionProps {
    id?: string;
    title: string;
    description?: string;
    actionLabel?: string;
    onAction?: () => void;
    children: ReactNode;
}

export default function PortalSection({ id, title, description = '', actionLabel, onAction, children }: PortalSectionProps) {
    return (
        <section id={id} className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-[0_8px_22px_rgba(15,23,42,0.045)] sm:p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold leading-tight text-slate-900 sm:text-base">{title}</h2>
                    {description ? (
                        <p className="mt-1 text-xs leading-5 text-slate-500 sm:text-sm">{description}</p>
                    ) : null}
                </div>
                {actionLabel && onAction ? (
                    <button
                        type="button"
                        onClick={onAction}
                        className="shrink-0 rounded-lg px-1 py-0.5 text-xs font-semibold text-blue-700 transition hover:text-blue-900"
                    >
                        {actionLabel}
                    </button>
                ) : null}
            </div>

            <div className="mt-3.5">{children}</div>
        </section>
    );
}
