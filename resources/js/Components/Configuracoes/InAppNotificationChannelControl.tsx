import { router, usePage } from '@inertiajs/react';
import { Bell } from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';

import { Switch } from '@/Components/ui/switch';

type NotificationPreferences = {
    email_notificacoes?: boolean;
    alertas_aplicacao?: boolean;
    alertas_pagamento?: boolean;
    alertas_atividade?: boolean;
    automacoes_financeiro?: boolean;
    automacoes_eventos?: boolean;
    automacoes_logistica?: boolean;
    automacoes_faturas_financeiras?: boolean;
    automacoes_movimentos_financeiros?: boolean;
    automacoes_convocatorias_eventos?: boolean;
    automacoes_requisicoes_logistica?: boolean;
    automacoes_alertas_operacionais?: boolean;
};

type PageProps = {
    notificationPrefs?: NotificationPreferences | null;
};

const booleanPayload = (preferences: NotificationPreferences, enabled: boolean) => ({
    email_notificacoes: Boolean(preferences.email_notificacoes),
    alertas_aplicacao: enabled,
    alertas_pagamento: Boolean(preferences.alertas_pagamento),
    alertas_atividade: Boolean(preferences.alertas_atividade),
    automacoes_financeiro: Boolean(preferences.automacoes_financeiro),
    automacoes_eventos: Boolean(preferences.automacoes_eventos),
    automacoes_logistica: Boolean(preferences.automacoes_logistica),
    automacoes_faturas_financeiras: Boolean(preferences.automacoes_faturas_financeiras),
    automacoes_movimentos_financeiros: Boolean(preferences.automacoes_movimentos_financeiros),
    automacoes_convocatorias_eventos: Boolean(preferences.automacoes_convocatorias_eventos),
    automacoes_requisicoes_logistica: Boolean(preferences.automacoes_requisicoes_logistica),
    automacoes_alertas_operacionais: Boolean(preferences.automacoes_alertas_operacionais),
});

const findNativeGrid = (): HTMLElement | null => {
    const headings = Array.from(document.querySelectorAll('h1, h2, h3, h4, div'));
    const heading = headings.find((element) => element.textContent?.trim() === 'Automações da Comunicação');
    const card = heading?.closest('[class*="rounded"], [class*="border"]');

    return card?.querySelector<HTMLElement>('.grid') ?? null;
};

export function InAppNotificationChannelControl() {
    const { props, url } = usePage<PageProps>();
    const preferences = props.notificationPrefs;
    const isConfigurationsPage = url.startsWith('/configuracoes');
    const [processing, setProcessing] = useState(false);
    const [target, setTarget] = useState<HTMLElement | null>(null);

    const enabled = useMemo(() => Boolean(preferences?.alertas_aplicacao ?? true), [preferences]);

    useEffect(() => {
        if (!isConfigurationsPage || !preferences) {
            setTarget(null);
            return;
        }

        const resolveTarget = () => setTarget(findNativeGrid());
        resolveTarget();

        const observer = new MutationObserver(resolveTarget);
        observer.observe(document.body, { childList: true, subtree: true });

        return () => observer.disconnect();
    }, [isConfigurationsPage, preferences]);

    if (!isConfigurationsPage || !preferences || !target) {
        return null;
    }

    const save = (nextValue: boolean) => {
        setProcessing(true);

        router.put(route('configuracoes.notificacoes.update'), booleanPayload(preferences, nextValue), {
            preserveScroll: true,
            preserveState: true,
            only: ['notificationPrefs'],
            onFinish: () => setProcessing(false),
        });
    };

    return createPortal(
        <div className="flex items-center justify-between gap-3 rounded-lg border bg-card p-3">
            <div className="flex min-w-0 items-start gap-3">
                <div className="mt-0.5 rounded-full bg-primary/10 p-2 text-primary">
                    <Bell size={18} weight="duotone" />
                </div>
                <div>
                    <p className="text-sm font-medium">Alertas na aplicação</p>
                    <p className="text-xs text-muted-foreground">
                        Canal global para mensalidades, eventos, logística, loja e website.
                    </p>
                </div>
            </div>
            <Switch
                checked={enabled}
                disabled={processing}
                onCheckedChange={save}
                aria-label="Ativar alertas na aplicação"
            />
        </div>,
        target,
    );
}
