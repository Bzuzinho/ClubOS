import { getPortalBottomNavItems, type PortalNavKey } from '@/lib/portalRoutes';

interface PortalSidebarNavProps {
    activeKey: PortalNavKey;
    hasFamily: boolean;
    onNavigate: (href: string) => void;
}

export default function PortalSidebarNav({ activeKey, hasFamily, onNavigate }: PortalSidebarNavProps) {
    const items = getPortalBottomNavItems(hasFamily);

    return (
        <aside className="hidden lg:block">
            <nav
                className="sticky top-5 rounded-[22px] border border-slate-200 bg-white p-2.5 shadow-[0_8px_22px_rgba(15,23,42,0.045)]"
                aria-label="Menu do portal"
            >
                <p className="px-3 pb-2 pt-1.5 text-[10px] font-semibold uppercase tracking-[0.15em] text-slate-400">
                    Portal
                </p>
                <div className="space-y-1">
                    {items.map(({ key, label, icon: Icon, href }) => {
                        const isActive = activeKey === key;

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => onNavigate(href)}
                                aria-current={isActive ? 'page' : undefined}
                                className={`flex w-full items-center gap-3 rounded-[14px] px-3 py-2.5 text-left text-sm font-semibold transition ${
                                    isActive
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'text-slate-600 hover:bg-slate-50 hover:text-blue-700'
                                }`}
                            >
                                <Icon className="h-[18px] w-[18px] shrink-0" strokeWidth={isActive ? 2.25 : 1.8} />
                                <span>{label}</span>
                            </button>
                        );
                    })}
                </div>
            </nav>
        </aside>
    );
}
