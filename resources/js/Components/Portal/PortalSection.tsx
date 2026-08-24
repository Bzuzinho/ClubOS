import type { ReactNode } from 'react';

interface PortalSectionProps {
    id?: string;
    title: string;
    description: string;
    actionLabel?: string;
    onAction?: () => void;
    children: ReactNode;
}

export default function PortalSection({ id, title, description, actionLabel, onAction, children }: PortalSectionProps) {
    return (
        <section id={id} className="rounded-[28px] border border-slate-200 bg-white p-4 shadow-[0_10px_24px_rgba(15,23,42,0.05)]">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <p className="text-sm text-slate-500">{description}</p>
                </div>
                {actionLabel && onAction ? (
                    <button type="button" onClick={onAction} className="text-xs font-semibold text-blue-700">
                        {actionLabel}
                    </button>
                ) : null}
            </div>

            <div className="mt-4">{children}</div>
        </section>
    );
}
