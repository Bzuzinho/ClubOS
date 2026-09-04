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
            className={`group flex w-full items-start gap-3 rounded-[18px] border border-slate-200 bg-slate-50/70 text-left transition hover:border-blue-200 hover:bg-white ${
                compact ? 'min-h-[82px] p-3' : 'min-h-[92px] p-3.5'
            }`}
        >
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${accentClass}`}>
                <Icon className="h-4 w-4" />
            </div>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-slate-900">{title}</p>
                <p className="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{description}</p>
            </div>

            <ChevronRight className="mt-2 h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-blue-500" />
        </button>
    );
}
