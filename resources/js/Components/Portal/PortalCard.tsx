import type { LucideIcon } from 'lucide-react';
import { ChevronRight } from 'lucide-react';

interface PortalCardProps {
    title: string;
    description: string;
    icon: LucideIcon;
    accentClass: string;
    onClick: () => void;
    compact?: boolean;
}

export default function PortalCard({ title, description, icon: Icon, accentClass, onClick, compact = false }: PortalCardProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`group flex flex-col rounded-2xl border border-slate-200 bg-slate-50/70 text-left transition hover:border-blue-200 hover:bg-white ${compact ? 'min-h-[96px] p-3' : 'min-h-[116px] p-4'}`}
        >
            <div className={`flex h-9 w-9 items-center justify-center rounded-xl shadow-sm ${accentClass}`}>
                <Icon className="h-4 w-4" />
            </div>
            <div className={compact ? 'mt-2 flex-1' : 'mt-3 flex-1'}>
                <p className="text-sm font-semibold text-slate-900">{title}</p>
                <p className="mt-1 text-xs leading-5 text-slate-500">{description}</p>
            </div>
            <div className={`${compact ? 'mt-2' : 'mt-3'} inline-flex items-center gap-1 text-xs font-semibold text-blue-700`}>
                Abrir
                <ChevronRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
            </div>
        </button>
    );
}
