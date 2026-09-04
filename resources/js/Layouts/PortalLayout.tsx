import type { ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import PortalBottomNav from '@/Components/Portal/PortalBottomNav';
import PortalHeader from '@/Components/Portal/PortalHeader';
import PortalSidebarNav from '@/Components/Portal/PortalSidebarNav';
import { getPortalBottomNavItems, portalNavLabels, portalRoutes, type PortalNavKey } from '@/lib/portalRoutes';
import type { ClubSettingsProps, PageProps, User } from '@/types';

interface PortalLayoutProps {
    children: ReactNode;
    header?: ReactNode;
    bottomNav?: ReactNode;
    user?: User;
    clubSettings?: ClubSettingsProps;
    isAlsoAdmin?: boolean;
    activeNav?: PortalNavKey;
    hasFamily?: boolean;
}

function resolveUserName(user?: User): string {
    return [user?.nome_completo, user?.full_name, user?.name]
        .map((value) => value?.trim())
        .find(Boolean) || 'Utilizador';
}

function resolveUserPhoto(user?: User): string | null {
    return user?.foto_perfil || user?.photo || null;
}

export default function PortalLayout({
    header,
    children,
    bottomNav,
    user,
    clubSettings,
    isAlsoAdmin = false,
    activeNav = 'dashboard',
    hasFamily = false,
}: PortalLayoutProps) {
    const { props } = usePage<PageProps>();
    const clubName = clubSettings?.nome_clube?.trim() || 'ClubOS';
    const clubShortName = clubSettings?.sigla?.trim() || 'BSCN';
    const clubLogoUrl = clubSettings?.logo_url || null;
    const userName = resolveUserName(user);
    const unreadNotifications = props.communicationAlerts?.unreadCount ?? 0;
    const recentAlerts = props.communicationAlerts?.recent ?? [];
    const resolvedHeader = header ?? (
        <PortalHeader
            clubName={clubName}
            clubShortName={clubShortName}
            clubLogoUrl={clubLogoUrl}
            unreadNotifications={unreadNotifications}
            alerts={recentAlerts}
            canAccessAdmin={isAlsoAdmin}
            currentUserName={userName}
            currentUserSubtitle={portalNavLabels[activeNav]}
            currentUserAvatarUrl={resolveUserPhoto(user)}
            onNotifications={() => router.visit(portalRoutes.communications)}
            onAdmin={() => router.visit(portalRoutes.admin)}
            onLogout={() => router.post('/logout')}
        />
    );
    const resolvedBottomNav = bottomNav ?? (
        <PortalBottomNav
            items={getPortalBottomNavItems(hasFamily)}
            activeKey={hasFamily && activeNav === 'family' ? 'family' : activeNav}
            onNavigate={(href) => router.visit(href)}
        />
    );

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <div className="mx-auto w-full max-w-7xl px-3 pb-28 pt-3 sm:px-4 sm:pt-4 lg:px-6 lg:pb-8 xl:px-8">
                {resolvedHeader}

                <div className="mt-4 lg:grid lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start lg:gap-5">
                    <PortalSidebarNav
                        activeKey={activeNav}
                        hasFamily={hasFamily}
                        onNavigate={(href) => router.visit(href)}
                    />
                    <main className="min-w-0 space-y-4">{children}</main>
                </div>
            </div>

            {resolvedBottomNav}
        </div>
    );
}
