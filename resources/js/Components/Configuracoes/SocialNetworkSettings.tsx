import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';

export interface SocialAccountConfiguration {
    id: string | null;
    provider: 'facebook' | 'instagram';
    display_name?: string | null;
    username?: string | null;
    external_account_id?: string | null;
    graph_api_version: string;
    app_id?: string | null;
    is_enabled: boolean;
    verification_status: string;
    verification_message?: string | null;
    last_verified_at?: string | null;
    has_app_secret: boolean;
    has_access_token: boolean;
    has_webhook_verify_token: boolean;
    publish_ready: boolean;
}

function AccountCard({ account }: { account: SocialAccountConfiguration }) {
    const label = account.provider === 'facebook' ? 'Facebook' : 'Instagram';
    const form = useForm({
        external_account_id: account.external_account_id || '',
        graph_api_version: account.graph_api_version || 'v24.0',
        app_id: account.app_id || '',
        app_secret: '',
        access_token: '',
        webhook_verify_token: '',
        is_enabled: account.is_enabled,
        clear_app_secret: false,
        clear_access_token: false,
        clear_webhook_verify_token: false,
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('configuracoes.redes.update', account.provider), { preserveScroll: true });
    };

    const remove = () => {
        if (window.confirm(`Desligar ${label} e remover todas as credenciais guardadas?`)) {
            router.delete(route('configuracoes.redes.destroy', account.provider), { preserveScroll: true });
        }
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>{label}</CardTitle>
                        <CardDescription>{account.display_name || account.username || 'Conta ainda não identificada pela Meta'}</CardDescription>
                    </div>
                    <Badge variant={account.publish_ready ? 'secondary' : 'outline'}>
                        {account.verification_status === 'verified' ? 'Ligação validada' : account.publish_ready ? 'Pronta para validar' : 'Por configurar'}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={save} className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-account-id`}>{account.provider === 'facebook' ? 'ID da Página Facebook' : 'ID da conta profissional Instagram'}</Label>
                            <Input id={`${account.provider}-account-id`} value={form.data.external_account_id} onChange={(event) => form.setData('external_account_id', event.target.value)} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-version`}>Versão da Graph API</Label>
                            <Input id={`${account.provider}-version`} value={form.data.graph_api_version} onChange={(event) => form.setData('graph_api_version', event.target.value)} placeholder="v24.0" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-app-id`}>App ID</Label>
                            <Input id={`${account.provider}-app-id`} value={form.data.app_id} onChange={(event) => form.setData('app_id', event.target.value)} autoComplete="off" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-token`}>Access token {account.has_access_token ? '(guardado)' : ''}</Label>
                            <Input id={`${account.provider}-token`} type="password" value={form.data.access_token} onChange={(event) => form.setData('access_token', event.target.value)} placeholder={account.has_access_token ? 'Deixar vazio para manter' : 'Introduzir token'} autoComplete="new-password" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-secret`}>App secret {account.has_app_secret ? '(guardado)' : ''}</Label>
                            <Input id={`${account.provider}-secret`} type="password" value={form.data.app_secret} onChange={(event) => form.setData('app_secret', event.target.value)} placeholder={account.has_app_secret ? 'Deixar vazio para manter' : 'Introduzir secret'} autoComplete="new-password" />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor={`${account.provider}-verify-token`}>Webhook verify token {account.has_webhook_verify_token ? '(guardado)' : ''}</Label>
                            <Input id={`${account.provider}-verify-token`} type="password" value={form.data.webhook_verify_token} onChange={(event) => form.setData('webhook_verify_token', event.target.value)} placeholder={account.has_webhook_verify_token ? 'Deixar vazio para manter' : 'Definir token de verificação'} autoComplete="new-password" />
                        </div>
                    </div>
                    <div className="flex items-center justify-between rounded-md border px-3 py-3">
                        <div><Label>Ativar publicação</Label><p className="text-xs text-muted-foreground">O adapter continuará bloqueado até existir ID e access token.</p></div>
                        <Switch checked={form.data.is_enabled} onCheckedChange={(checked) => form.setData('is_enabled', checked)} />
                    </div>
                    {account.verification_message ? <p className="text-xs text-muted-foreground">{account.verification_message}</p> : null}
                    <p className="text-xs text-muted-foreground">Callback: <code>/api/webhooks/meta/{account.provider}</code>. Os segredos são cifrados e nunca regressam ao browser.</p>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" size="sm" disabled={form.processing}>{form.processing ? 'A guardar...' : 'Guardar'}</Button>
                        <Button type="button" size="sm" variant="outline" disabled={!account.id || !account.publish_ready} onClick={() => router.post(route('configuracoes.redes.verify', account.provider), {}, { preserveScroll: true })}>Validar ligação</Button>
                        {account.id ? <Button type="button" size="sm" variant="destructive" onClick={remove}>Desligar</Button> : null}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

export default function SocialNetworkSettings({ accounts }: { accounts: SocialAccountConfiguration[] }) {
    return (
        <div className="space-y-4">
            <div><h3 className="text-lg font-semibold">Credenciais das redes sociais</h3><p className="text-sm text-muted-foreground">Configure as contas Meta quando tiver as credenciais. Nenhum token é incluído no código ou no deploy.</p></div>
            <div className="grid gap-4 xl:grid-cols-2">{accounts.map((account) => <AccountCard key={account.provider} account={account} />)}</div>
        </div>
    );
}
