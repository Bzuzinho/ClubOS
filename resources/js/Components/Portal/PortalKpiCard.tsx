import type { LucideIcon } from 'lucide-react';

interface PortalKpiCardProps {
    label: string;
    value: string;
    helper: string;
    icon: LucideIcon;
    valueClassName?: string;
    onClick?: () => void;
}

export default function PortalKpiCard({ label, value, helper, icon: Icon, valueClassName, onClick }: PortalKpiCardProps) {
    const Component = onClick ? 'button' : 'div';

    return (
        <Component
            {...(onClick ? { type: 'button' as const, onClick } : {})}
            className={`w-full rounded-[20px] border border-slate-200 bg-white p-3.5 text-left shadow-[0_6px_18px_rgba(15,23,42,0.04)] ${
                onClick
                    ? 'transition hover:border-blue-200 hover:bg-blue-50/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500'
                    : ''
            }`}
        >
            <div className="flex items-start justify-between gap-2.5">
                <div className="min-w-0">
                    <p className="truncate text-[11px] font-medium text-slate-500">{label}</p>
                    <p className={["mt-1.5 truncate text-xl font-semibold", valueClassName || 'text-slate-900'].join(' ')}>{value}</p>
                    <p className="mt-1 truncate text-[9px] font-medium uppercase tracking-[0.13em] text-slate-400">{helper}</p>
                </div>
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                    <Icon className="h-4 w-4" />
                </div>
            </div>
        </Component>
    );
}
