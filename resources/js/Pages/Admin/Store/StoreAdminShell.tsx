import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { LayoutDashboard, Package, ShoppingBag, Image as ImageIcon } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';

type StoreAdminTab = 'dashboard' | 'produtos' | 'hero' | 'encomendas';

interface StoreAdminShellProps {
    title: string;
    description: string;
    activeTab: StoreAdminTab;
    actions?: ReactNode;
    children: ReactNode;
}

const tabs: Array<{
    value: StoreAdminTab;
    label: string;
    href: string;
    icon: typeof LayoutDashboard;
}> = [
    { value: 'dashboard', label: 'Dashboard', href: '/admin/loja', icon: LayoutDashboard },
    { value: 'produtos', label: 'Produtos', href: '/admin/loja/produtos', icon: Package },
    { value: 'hero', label: 'Destaques', href: '/admin/loja/hero', icon: ImageIcon },
    { value: 'encomendas', label: 'Encomendas', href: '/admin/loja/encomendas', icon: ShoppingBag },
];

export function StoreAdminShell({
    title,
    description,
    activeTab,
    actions,
    children,
}: StoreAdminShellProps) {
    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div>
                    <h1 className="text-lg sm:text-xl font-semibold tracking-tight text-slate-900">{title}</h1>
                    <p className="mt-0.5 text-xs text-slate-500">{description}</p>
                </div>
            }
        >
            <div className={moduleViewportClass}>
                <div className={moduleTabsClass}>
                    <div className="rounded-lg border border-border bg-card p-2 sm:p-3 shadow-sm">
                        <div className="flex flex-col gap-2 sm:gap-3 xl:flex-row xl:items-start xl:justify-between">
                            <Tabs
                                value={activeTab}
                                onValueChange={(value) => {
                                    const target = tabs.find((tab) => tab.value === value);
                                    if (target) {
                                        router.visit(target.href);
                                    }
                                }}
                                className="w-full"
                            >
                                <TabsList className="grid h-auto w-full grid-cols-2 gap-1 rounded-md bg-muted/60 p-1 sm:grid-cols-4 xl:w-auto xl:min-w-[42rem] xl:grid-cols-4">
                                    {tabs.map((tab) => {
                                        const Icon = tab.icon;

                                        return (
                                            <TabsTrigger
                                                key={tab.value}
                                                value={tab.value}
                                                className="gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium data-[state=active]:bg-background"
                                            >
                                                <Icon className="h-3.5 w-3.5" />
                                                {tab.label}
                                            </TabsTrigger>
                                        );
                                    })}
                                </TabsList>
                            </Tabs>

                            {actions ? <div className="flex w-full flex-wrap gap-2 xl:w-auto xl:shrink-0 xl:justify-end">{actions}</div> : null}
                        </div>
                    </div>

                    <div className={moduleTabbedContentClass}>
                        {children}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
