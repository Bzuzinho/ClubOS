import type { LucideIcon } from 'lucide-react';

interface PortalBottomNavItem {
    key: string;
    label: string;
    icon: LucideIcon;
    href: string;
}

interface PortalBottomNavProps {
    items: PortalBottomNavItem[];
    onNavigate: (href: string) => void;
    activeKey?: string;
}

export default function PortalBottomNav({ items, onNavigate, activeKey }: PortalBottomNavProps) {
    const gridColumnsClass = items.length >= 5 ? 'grid-cols-5' : 'grid-cols-4';

    return (
        <nav className="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/96 px-2 pb-[calc(env(safe-area-inset-bottom)+0.45rem)] pt-1.5 shadow-[0_-6px_18px_rgba(15,23,42,0.07)] backdrop-blur lg:hidden">
            <div className={`mx-auto grid max-w-xl gap-1 ${gridColumnsClass}`}>
                {items.map((item) => {
                    const isActive = activeKey === item.key;

                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onNavigate(item.href)}
                            aria-current={isActive ? 'page' : undefined}
                            className={`flex min-w-0 flex-col items-center justify-center gap-1 rounded-[14px] px-1 py-2 text-[9px] font-medium transition sm:text-[10px] ${
                                isActive
                                    ? 'bg-blue-50 text-blue-700'
                                    : 'text-slate-500 hover:bg-slate-100 hover:text-blue-700'
                            }`}
                        >
                            <item.icon className="h-[18px] w-[18px]" strokeWidth={isActive ? 2.25 : 1.8} />
                            <span className="truncate">{item.label}</span>
                        </button>
                    );
                })}
            </div>
        </nav>
    );
}
