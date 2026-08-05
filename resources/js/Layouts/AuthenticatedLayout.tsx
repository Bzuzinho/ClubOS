import { ReactNode, useEffect, useMemo, useRef, useState, PropsWithChildren } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Users,
    CalendarBlank,
    Trophy,
    Package,
    ShoppingCart,
    CurrencyCircleDollar,
    Handshake,
    MegaphoneSimple,
    House,
    List,
    X,
    Gear,
    SignOut,
    Envelope,
    Bell,
    GlobeHemisphereWest
} from '@phosphor-icons/react';
import { cn } from '@/lib/utils';
import { ClubMark } from '@/Components/ClubMark';
import { useClubSettings } from '@/hooks/useClubSettings';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Separator } from '@/Components/ui/separator';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

interface User {
    id: number;
    name: string;
    email: string;
}

interface AlertItem {
    id: string;
    title: string;
    message: string;
    type: 'info' | 'warning' | 'success' | 'error';
    link?: string | null;
    is_read: boolean;
    created_at: string;
}

interface PageProps {
    auth: {
        user: User;
    };
    accessControl?: {
        currentUserType?: {
            id: string;
            nome: string;
        } | null;
        visibleMenuModules?: string[];
        landingPage?: {
            route: string;
        };
    };
    clubSettings?: {
        nome_clube?: string | null;
        sigla?: string | null;
        logo_url?: string | null;
    };
    communicationAlerts?: {
        unreadCount: number;
        recent: AlertItem[];
    };
}

const mainMenuItems = [
    { id: 'dashboard', moduleKey: 'inicio', label: 'Início', icon: House, route: '/dashboard' },
    { id: 'membros', moduleKey: 'membros', label: 'Membros', icon: Users, route: '/membros' },
    { id: 'desportivo', moduleKey: 'desportivo', label: 'Desportivo', icon: Trophy, route: '/desportivo' },
    { id: 'eventos', moduleKey: 'eventos', label: 'Eventos', icon: CalendarBlank, route: '/eventos' },
    { id: 'financeiro', moduleKey: 'financeiro', label: 'Financeiro', icon: CurrencyCircleDollar, route: '/financeiro' },
    { id: 'logistica', moduleKey: 'logistica', label: 'Logística', icon: Package, route: '/logistica' },
    { id: 'loja', moduleKey: 'loja', label: 'Loja', icon: ShoppingCart, route: '/admin/loja' },
    { id: 'patrocinios', moduleKey: 'patrocinios', label: 'Patrocínios', icon: Handshake, route: '/patrocinios' },
    { id: 'comunicacao', moduleKey: 'comunicacao', label: 'Comunicação', icon: Envelope, route: '/comunicacao' },
    { id: 'marketing', moduleKey: 'marketing', label: 'Marketing', icon: MegaphoneSimple, route: '/campanhas-marketing' },
    { id: 'website', moduleKey: 'website', label: 'Website', icon: GlobeHemisphereWest, route: '/website' },
];

const settingsMenuItems = [
    { id: 'configuracoes', moduleKey: 'configuracoes', label: 'Configurações', icon: Gear, route: '/configuracoes' },
];

const defaultVisibleMenuModules = [...mainMenuItems, ...settingsMenuItems].map((item) => item.moduleKey);
const modulePrefetchPriority = ['eventos', 'financeiro', 'comunicacao', 'configuracoes', 'desportivo'];

export default function AuthenticatedLayout({ 
    header,
    children,
    fullWidth = false,
    collapseSidebarDesktop = false,
    showSidebarPopupButton = false,
    hideMobileHeader = false,
}: PropsWithChildren<{
    header?: ReactNode;
    fullWidth?: boolean;
    collapseSidebarDesktop?: boolean;
    showSidebarPopupButton?: boolean;
    hideMobileHeader?: boolean;
}>) {
    const page = usePage<PageProps>();
    const { auth, accessControl, communicationAlerts } = page.props;
    const { clubDisplayName, clubLogoUrl, clubName, clubShortName } = useClubSettings();
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [selectedAlertId, setSelectedAlertId] = useState<string | null>(null);
    const [isAlertDialogOpen, setIsAlertDialogOpen] = useState(false);
    const [alertAction, setAlertAction] = useState<'read' | 'unread' | 'delete' | null>(null);
    const currentRoute = route().current();
    const alerts = communicationAlerts?.recent ?? [];
    const prefetchedRoutesRef = useRef<Set<string>>(new Set());
    const visibleMenuModuleKeys = accessControl?.visibleMenuModules ?? defaultVisibleMenuModules;
    const visibleMenuModulesKey = visibleMenuModuleKeys.join('|');
    const visibleMenuModules = useMemo(
        () => new Set(visibleMenuModuleKeys),
        [visibleMenuModulesKey]
    );
    const filteredMainMenuItems = useMemo(
        () => mainMenuItems.filter((item) => visibleMenuModules.has(item.moduleKey)),
        [visibleMenuModules]
    );
    const filteredSettingsMenuItems = useMemo(
        () => settingsMenuItems.filter((item) => visibleMenuModules.has(item.moduleKey)),
        [visibleMenuModules]
    );
    const unreadAlerts = alerts.filter((alert) => !alert.is_read);
    const selectedAlert = alerts.find((alert) => alert.id === selectedAlertId) ?? null;

    useEffect(() => {
        const handleOpenSidebar = () => setMobileMenuOpen(true);
        window.addEventListener('spark:open-sidebar', handleOpenSidebar);

        return () => {
            window.removeEventListener('spark:open-sidebar', handleOpenSidebar);
        };
    }, []);

    useEffect(() => {
        if (!isAlertDialogOpen || selectedAlertId === null || selectedAlert !== null) {
            return;
        }

        setIsAlertDialogOpen(false);
        setSelectedAlertId(null);
    }, [isAlertDialogOpen, selectedAlert, selectedAlertId]);

    const prefetchModuleRoute = (routePath: string) => {
        if (prefetchedRoutesRef.current.has(routePath) || page.url === routePath) {
            return;
        }

        prefetchedRoutesRef.current.add(routePath);
        router.prefetch(routePath, {}, { cacheFor: '2m' });
    };

    useEffect(() => {
        if (currentRoute !== 'dashboard') {
            return;
        }

        const visibleItems = [...filteredMainMenuItems, ...filteredSettingsMenuItems];
        const prioritizedRoutes = modulePrefetchPriority
            .map((moduleKey) => visibleItems.find((item) => item.moduleKey === moduleKey))
            .filter((item): item is (typeof visibleItems)[number] => !!item)
            .map((item) => item.route);

        if (prioritizedRoutes.length === 0) {
            return;
        }

        const idleCallback = window.requestIdleCallback;
        const handle = idleCallback
            ? idleCallback(() => {
                prioritizedRoutes.forEach((routePath) => prefetchModuleRoute(routePath));
            }, { timeout: 1500 })
            : window.setTimeout(() => {
                prioritizedRoutes.forEach((routePath) => prefetchModuleRoute(routePath));
            }, 400);

        return () => {
            if (typeof handle === 'number') {
                window.clearTimeout(handle);
                return;
            }

            window.cancelIdleCallback?.(handle);
        };
    }, [currentRoute, filteredMainMenuItems, filteredSettingsMenuItems]);

    const getUserInitials = () => {
        if (!auth.user) return 'U';
        const names = auth.user.name.split(' ');
        if (names.length >= 2) {
            return `${names[0][0]}${names[names.length - 1][0]}`.toUpperCase();
        }
        return auth.user.name[0].toUpperCase();
    };

    const handleNavigate = (routePath: string) => {
        router.visit(routePath);
        setMobileMenuOpen(false);
    };

    const handleLogout = () => {
        router.post(route('logout'));
    };

    const handleMarkRead = (alertId: string, onSuccess?: () => void) => {
        setAlertAction('read');

        router.post(route('comunicacao.alerts.markRead'), {
            alert_id: alertId,
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['communicationAlerts', 'flash'],
            onSuccess: () => {
                onSuccess?.();
            },
            onFinish: () => {
                setAlertAction(null);
            },
        });
    };

    const handleMarkUnread = (alertId: string) => {
        setAlertAction('unread');

        router.post(route('comunicacao.alerts.markUnread'), {
            alert_id: alertId,
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['communicationAlerts', 'flash'],
            onFinish: () => {
                setAlertAction(null);
            },
        });
    };

    const handleMarkAllRead = () => {
        router.post(route('comunicacao.alerts.markAllRead'), {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['communicationAlerts', 'flash'],
        });
    };

    const handleDeleteAlert = (alertId: string) => {
        setAlertAction('delete');

        router.delete(route('comunicacao.alerts.destroy', alertId), {
            preserveScroll: true,
            preserveState: true,
            only: ['communicationAlerts', 'flash'],
            onSuccess: () => {
                setIsAlertDialogOpen(false);
                setSelectedAlertId(null);
            },
            onFinish: () => {
                setAlertAction(null);
            },
        });
    };

    const handleOpenAlert = (alert: AlertItem) => {
        setSelectedAlertId(alert.id);
        setIsAlertDialogOpen(true);

        if (!alert.is_read) {
            handleMarkRead(alert.id);
        }
    };

    const handleAlertDialogChange = (open: boolean) => {
        setIsAlertDialogOpen(open);

        if (!open) {
            setSelectedAlertId(null);
        }
    };

    const formatAlertDate = (value: string) => {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('pt-PT', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(date);
    };

    const alertTypeLabel: Record<AlertItem['type'], string> = {
        info: 'Informação',
        warning: 'Aviso',
        success: 'Sucesso',
        error: 'Erro',
    };

    const alertTypeClassName: Record<AlertItem['type'], string> = {
        info: 'bg-sky-100 text-sky-800 border-sky-200',
        warning: 'bg-amber-100 text-amber-800 border-amber-200',
        success: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        error: 'bg-rose-100 text-rose-800 border-rose-200',
    };

    const isActive = (itemId: string) => {
        if (itemId === 'dashboard') return currentRoute === 'dashboard';
        return currentRoute?.startsWith(itemId) || false;
    };

    return (
        <div className="min-h-screen bg-background flex">
            {/* Sidebar */}
            <aside className={cn(
                "fixed inset-y-0 left-0 z-50 w-64 bg-sidebar border-r border-sidebar-border",
                "transform transition-transform duration-200 ease-in-out",
                !collapseSidebarDesktop && "lg:translate-x-0 lg:static",
                mobileMenuOpen ? "translate-x-0" : "-translate-x-full"
            )}>
                <div className="h-full flex flex-col">
                    {/* Logo Header */}
                    <div className="p-6 border-b border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div className="flex min-w-0 items-center gap-3">
                                <ClubMark
                                    logoUrl={clubLogoUrl}
                                    clubName={clubName}
                                    clubShortName={clubShortName}
                                    className="h-12 w-12 shrink-0 bg-transparent text-sm"
                                    imageClassName="h-12 w-12 object-contain"
                                />
                                <div className="min-w-0 flex-1 overflow-hidden">
                                    <h2 className="truncate text-xl font-semibold text-sidebar-foreground">
                                        {clubShortName}
                                    </h2>
                                    <p className="mt-1 whitespace-normal break-words text-[clamp(0.68rem,1.2vw,0.9rem)] leading-tight text-muted-foreground">
                                        {clubDisplayName}
                                    </p>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                className={cn(!collapseSidebarDesktop && "lg:hidden")}
                                onClick={() => setMobileMenuOpen(false)}
                            >
                                <X />
                            </Button>
                        </div>
                    </div>

                    {/* Navigation */}
                    <nav className="flex-1 p-4 overflow-y-auto flex flex-col">
                        {/* Main Menu */}
                        <div className="space-y-1 flex-1">
                            {filteredMainMenuItems.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.id);

                                return (
                                    <Button
                                        key={item.id}
                                        variant="ghost"
                                        className={cn(
                                            "w-full justify-start",
                                            active && "bg-sidebar-primary text-sidebar-primary-foreground hover:bg-sidebar-primary/90",
                                            !active && "hover:bg-sidebar-accent text-sidebar-foreground"
                                        )}
                                        onMouseEnter={() => prefetchModuleRoute(item.route)}
                                        onFocus={() => prefetchModuleRoute(item.route)}
                                        onClick={() => handleNavigate(item.route)}
                                    >
                                        <Icon className="mr-3" size={18} weight={active ? "fill" : "regular"} />
                                        {item.label}
                                    </Button>
                                );
                            })}
                        </div>

                        {/* Separator */}
                        <Separator className="my-4" />

                        {/* Settings Menu */}
                        <div className="space-y-1">
                            {filteredSettingsMenuItems.map((item) => {
                                const Icon = item.icon;
                                const active = isActive(item.id);

                                return (
                                    <Button
                                        key={item.id}
                                        variant="ghost"
                                        className={cn(
                                            "w-full justify-start",
                                            active && "bg-sidebar-primary text-sidebar-primary-foreground hover:bg-sidebar-primary/90",
                                            !active && "hover:bg-sidebar-accent text-sidebar-foreground"
                                        )}
                                        onMouseEnter={() => prefetchModuleRoute(item.route)}
                                        onFocus={() => prefetchModuleRoute(item.route)}
                                        onClick={() => handleNavigate(item.route)}
                                    >
                                        <Icon className="mr-3" size={18} weight={active ? "fill" : "regular"} />
                                        {item.label}
                                    </Button>
                                );
                            })}
                        </div>
                    </nav>

                    {/* User Profile Footer */}
                    {auth.user && (
                        <div className="p-4 border-t border-sidebar-border">
                            <div className="flex items-center gap-3 mb-3">
                                <Avatar className="h-10 w-10">
                                    <AvatarFallback className="bg-sidebar-primary text-sidebar-primary-foreground">
                                        {getUserInitials()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm font-medium truncate text-sidebar-foreground">{auth.user.name}</p>
                                    <p className="text-xs text-muted-foreground truncate">{auth.user.email}</p>
                                </div>
                            </div>
                            <Button
                                variant="outline"
                                className="w-full justify-start"
                                onClick={handleLogout}
                            >
                                <SignOut className="mr-3" />
                                Sair
                            </Button>
                        </div>
                    )}
                </div>
            </aside>

            {/* Mobile overlay */}
            {mobileMenuOpen && (
                <div
                    className={cn(
                        "fixed inset-0 bg-black/50 z-40",
                        !collapseSidebarDesktop && "lg:hidden"
                    )}
                    onClick={() => setMobileMenuOpen(false)}
                />
            )}

            {collapseSidebarDesktop && showSidebarPopupButton && !mobileMenuOpen && (
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="fixed top-3 left-3 z-30 hidden lg:inline-flex"
                    onClick={() => setMobileMenuOpen(true)}
                >
                    <List />
                </Button>
            )}

            {/* Main Content Area */}
            <div className="flex-1 flex flex-col min-w-0">
                {/* Mobile Header */}
                {!hideMobileHeader && (
                    <header className="bg-card border-b lg:hidden">
                        <div className="p-4">
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => setMobileMenuOpen(true)}
                            >
                                <List />
                            </Button>
                        </div>
                    </header>
                )}

                {/* Page Header (if provided) - SEM border-b */}
                {header && (
                    <header className="bg-background">
                        <div
                            className={cn(
                                fullWidth ? 'w-full px-[10px] pt-[10px] pb-4' : 'spark-container py-4'
                            )}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0 flex-1">{header}</div>
                                {auth.user && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline" size="icon" className="relative h-8 w-8">
                                                <Bell size={16} />
                                                {(communicationAlerts?.unreadCount ?? 0) > 0 && (
                                                    <span className="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] text-white">
                                                        {communicationAlerts?.unreadCount}
                                                    </span>
                                                )}
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="w-[360px]">
                                            <DropdownMenuLabel className="flex items-center justify-between">
                                                <span>Notificações</span>
                                                <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={handleMarkAllRead}>
                                                    Marcar todas lidas
                                                </Button>
                                            </DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            {unreadAlerts.length === 0 && (
                                                <DropdownMenuItem disabled>Sem notificações.</DropdownMenuItem>
                                            )}
                                            {unreadAlerts.map((alert) => (
                                                <DropdownMenuItem
                                                    key={alert.id}
                                                    className="cursor-pointer flex flex-col items-start gap-0.5 py-2"
                                                    onSelect={() => handleOpenAlert(alert)}
                                                >
                                                    <span className="text-xs font-medium text-primary">
                                                        {alert.title}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground line-clamp-2">{alert.message}</span>
                                                    <span className="text-[11px] text-muted-foreground/80">
                                                        {formatAlertDate(alert.created_at)}
                                                    </span>
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                        </div>
                    </header>
                )}

                {/* Page Content */}
                <main className="flex-1 overflow-auto">
                    <div
                        className={cn(
                            fullWidth ? 'w-full px-[10px] py-[10px] sm:py-4' : 'spark-container py-3 sm:py-4'
                        )}
                    >
                        {children}
                    </div>
                </main>
            </div>

            <Dialog open={isAlertDialogOpen} onOpenChange={handleAlertDialogChange}>
                <DialogContent className="sm:max-w-lg">
                    {selectedAlert && (
                        <>
                            <DialogHeader className="space-y-3 text-left">
                                <div className="flex flex-wrap items-center gap-2 pr-8">
                                    <Badge className={alertTypeClassName[selectedAlert.type]} variant="outline">
                                        {alertTypeLabel[selectedAlert.type]}
                                    </Badge>
                                    <Badge variant={selectedAlert.is_read ? 'secondary' : 'default'}>
                                        {selectedAlert.is_read ? 'Lida' : 'Não lida'}
                                    </Badge>
                                </div>
                                <DialogTitle className="pr-8 leading-snug">{selectedAlert.title}</DialogTitle>
                                <DialogDescription>
                                    Recebida em {formatAlertDate(selectedAlert.created_at)}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4">
                                <div className="rounded-lg border bg-muted/30 p-4">
                                    <p className="whitespace-pre-wrap text-sm leading-6 text-foreground">
                                        {selectedAlert.message}
                                    </p>
                                </div>

                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <Button
                                        variant="destructive"
                                        onClick={() => handleDeleteAlert(selectedAlert.id)}
                                        disabled={alertAction !== null}
                                    >
                                        Apagar comunicação
                                    </Button>

                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        {selectedAlert.link && (
                                            <Button
                                                variant="outline"
                                                onClick={() => router.visit(selectedAlert.link as string)}
                                                disabled={alertAction !== null}
                                            >
                                                Abrir ligação
                                            </Button>
                                        )}
                                        <Button
                                            variant="secondary"
                                            onClick={() => {
                                                if (selectedAlert.is_read) {
                                                    handleMarkUnread(selectedAlert.id);
                                                    return;
                                                }

                                                handleMarkRead(selectedAlert.id);
                                            }}
                                            disabled={alertAction !== null}
                                        >
                                            {selectedAlert.is_read ? 'Marcar como não lida' : 'Marcar como lida'}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
