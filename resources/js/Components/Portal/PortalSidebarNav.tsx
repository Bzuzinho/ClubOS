import {
    CalendarDays,
    CreditCard,
    FileText,
    House,
    Megaphone,
    ShoppingBag,
    Trophy,
    UserCircle2,
    Users,
} from 'lucide-react';
import { portalNavLabels, portalRoutes, type PortalNavKey } from '@/lib/portalRoutes';

interface PortalSidebarNavProps {
    activeKey: PortalNavKey;
    hasFamily: boolean;
    onNavigate: (href: string) => void;
}

const primaryItems: Array<{ key: PortalNavKey; icon: typeof House }> = [
    { key: 'dashboard', icon: House },
    { key: 'trainings', icon: CalendarDays },
    { key: 'events', icon: Megaphone },
    { key: 'payments', icon: CreditCard },
    { key: 'results', icon: Trophy },
    { key: 'documents', icon: FileText },
    { key: 'communications', icon: Megaphone },
    { key: 'shop', icon: ShoppingBag },
    { key: 'profile', icon: UserCircle2 },
];

export default function PortalSidebarNav({ activeKey, hasFamily, onNavigate }: PortalSidebarNavProps) {
    const items = hasFamily
        ? [...primaryItems.slice(0, 1), { key: 'family' as const, icon: Users }, ...primaryItems.slice(1)]
        : primaryItems;

    return (
        <aside className="hidden lg:block">
            <nav className="sticky top-5 rounded-[24px] border border-slate-200 bg-white p-3 shadow-[0_10px_24px_rgba(15,23,42,0.05)]" aria-label="Menu do portal">
                <p className="px-3 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">
                    Área de utilizador
                </p>
                <div className="space-y-1">
                    {items.map(({ key, icon: Icon }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onNavigate(portalRoutes[key])}
                            className={`flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm font-semibold transition ${activeKey === key ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-slate-50 hover:text-blue-700'}`}
                        >
                            <Icon className="h-4.5 w-4.5 shrink-0" />
                            <span>{portalNavLabels[key]}</span>
                        </button>
                    ))}
                </div>
            </nav>
        </aside>
    );
}
