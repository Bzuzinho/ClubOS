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
import type { LucideIcon } from 'lucide-react';

export const portalRoutes = {
    dashboard: '/dashboard',
    profile: '/portal/perfil',
    trainings: '/portal/treinos',
    events: '/portal/eventos',
    payments: '/portal/pagamentos',
    results: '/portal/resultados',
    documents: '/portal/documentos',
    communications: '/portal/comunicados',
    shop: '/loja',
    family: '/portal/familia',
    admin: '/dashboard?mode=admin',
} as const;

export type PortalNavKey = keyof Omit<typeof portalRoutes, 'admin'>;

export const portalNavLabels: Record<PortalNavKey, string> = {
    dashboard: 'Início',
    profile: 'Perfil',
    trainings: 'Treinos',
    events: 'Agenda',
    payments: 'Pagamentos',
    results: 'Resultados',
    documents: 'Documentos',
    communications: 'Comunicados',
    shop: 'Loja',
    family: 'Família',
};

export interface PortalBottomNavItem {
    key: 'dashboard' | 'family' | 'events' | 'payments' | 'profile' | 'shop';
    label: string;
    icon: LucideIcon;
    href: string;
}

export function getPortalBottomNavItems(hasFamily: boolean): PortalBottomNavItem[] {
    if (hasFamily) {
        return [
            { key: 'dashboard', label: 'Início', icon: House, href: portalRoutes.dashboard },
            { key: 'family', label: 'Família', icon: Users, href: portalRoutes.family },
            { key: 'events', label: 'Agenda', icon: CalendarDays, href: portalRoutes.events },
            { key: 'shop', label: 'Loja', icon: ShoppingBag, href: portalRoutes.shop },
            { key: 'profile', label: 'Perfil', icon: UserCircle2, href: portalRoutes.profile },
        ];
    }

    return [
        { key: 'dashboard', label: 'Início', icon: House, href: portalRoutes.dashboard },
        { key: 'events', label: 'Agenda', icon: CalendarDays, href: portalRoutes.events },
        { key: 'shop', label: 'Loja', icon: ShoppingBag, href: portalRoutes.shop },
        { key: 'payments', label: 'Pagamentos', icon: CreditCard, href: portalRoutes.payments },
        { key: 'profile', label: 'Perfil', icon: UserCircle2, href: portalRoutes.profile },
    ];
}

export const portalSectionIcons: Record<Exclude<PortalNavKey, 'dashboard' | 'profile' | 'family'>, LucideIcon> = {
    trainings: CalendarDays,
    events: Megaphone,
    payments: CreditCard,
    results: Trophy,
    documents: FileText,
    communications: Megaphone,
    shop: ShoppingBag,
};
