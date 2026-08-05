import { router, usePage } from '@inertiajs/react';
import { Bell } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
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

export function InAppNotificationChannelControl() {
    const { props, url } = usePage<PageProps>();
    const preferences = props.notificationPrefs;
    const isConfigurationsPage = url.startsWith('/configuracoes');
    const [processing, setProcessing] = useState(false);

    const enabled = useMemo(() => Boolean(preferences?.alertas_aplicacao ?? true), [preferences]);

    if (!isConfigurationsPage || !preferences) {
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

    return (
        <div className="pointer-events-none fixed bottom-5 right-5 z-50 w-[min(24rem,calc(100vw-2rem))]">
            <Card className="pointer-events-auto border-primary/20 shadow-lg">
                <CardContent className="flex items-center gap-3 p-4">
                    <div className="rounded-full bg-primary/10 p-2 text-primary">
                        <Bell size={20} weight="duotone" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="font-medium">Alertas na aplicação</p>
                        <p className="text-xs text-muted-foreground">
                            Canal global para mensalidades, eventos, logística e website.
                        </p>
                    </div>
                    <Switch
                        checked={enabled}
                        disabled={processing}
                        onCheckedChange={save}
                        aria-label="Ativar alertas na aplicação"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                        onClick={() => save(!enabled)}
                        className="sr-only"
                    >
                        Alterar
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}
