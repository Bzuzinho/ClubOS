import type { ReactNode } from 'react';
import { ArrowLeft } from 'lucide-react';
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
            <div className="mx-auto w-full max-w-screen-2xl px-4 pb-28 pt-4 sm:px-6 lg:px-8 lg:pb-10 xl:px-10">
                {resolvedHeader}
                <div className="mt-5 lg:grid lg:grid-cols-[240px_minmax(0,1fr)] lg:items-start lg:gap-6">
                    <PortalSidebarNav
                        activeKey={activeNav}
                        hasFamily={hasFamily}
                        onNavigate={(href) => router.visit(href)}
                    />
                    <div className="min-w-0">
                        {activeNav !== 'dashboard' ? (
                            <div className="mb-4 hidden md:flex lg:hidden">
                                <button
                                    type="button"
                                    onClick={() => router.visit(portalRoutes.dashboard)}
                                    className="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    <ArrowLeft className="h-4 w-4" />
                                    Voltar ao dashboard
                                </button>
                            </div>
                        ) : null}
                        <main className="space-y-5">{children}</main>
                    </div>
                </div>
            </div>

            {resolvedBottomNav}
        </div>
    );
}
