<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\SocialNetworkAccount;
use Illuminate\Support\Facades\Cache;

final class SocialNetworkAccountService
{
    public function __construct(private readonly MetaGraphClient $client)
    {
    }

    /** @return list<array<string,mixed>> */
    public function safeConfigurations(): array
    {
        $accounts = SocialNetworkAccount::query()->get()->keyBy('provider');

        return collect(['facebook', 'instagram'])->map(function (string $provider) use ($accounts): array {
            $account = $accounts->get($provider);

            return $account?->safeConfiguration() ?? [
                'id' => null,
                'provider' => $provider,
                'display_name' => null,
                'username' => null,
                'external_account_id' => null,
                'graph_api_version' => 'v24.0',
                'app_id' => null,
                'is_enabled' => false,
                'verification_status' => 'not_configured',
                'verification_message' => null,
                'last_verified_at' => null,
                'has_app_secret' => false,
                'has_access_token' => false,
                'has_webhook_verify_token' => false,
                'publish_ready' => false,
            ];
        })->all();
    }

    /** @param array<string,mixed> $payload */
    public function save(string $provider, array $payload, ?string $userId): SocialNetworkAccount
    {
        $account = SocialNetworkAccount::query()->firstOrNew(['provider' => $provider]);
        if (! $account->exists) {
            $account->created_by = $userId;
        }

        foreach (['app_secret', 'access_token', 'webhook_verify_token'] as $secret) {
            $clearKey = 'clear_'.$secret;
            if ((bool) ($payload[$clearKey] ?? false)) {
                $payload[$secret] = null;
            } elseif (blank($payload[$secret] ?? null)) {
                unset($payload[$secret]);
            }
            unset($payload[$clearKey]);
        }

        unset($payload['provider']);
        $account->fill($payload);
        $account->provider = $provider;
        $account->updated_by = $userId;
        $account->verification_status = 'not_verified';
        $account->verification_message = 'Valide a ligação depois de guardar as credenciais.';
        $account->save();
        $this->forgetCaches();

        return $account->refresh();
    }

    public function verify(SocialNetworkAccount $account): SocialNetworkAccount
    {
        try {
            if (! $account->isPublishReady()) {
                throw new \RuntimeException('Ative a conta e configure o identificador e o access token.');
            }

            $identity = $this->client->verify($account);
            if (! hash_equals((string) $account->external_account_id, $identity['id'])) {
                throw new \RuntimeException('O identificador devolvido pela Meta não corresponde à conta configurada.');
            }

            $account->update([
                'display_name' => $identity['name'] ?: $account->display_name,
                'username' => $identity['username'] ?: $account->username,
                'verification_status' => 'verified',
                'verification_message' => 'Ligação validada através da Meta Graph API.',
                'last_verified_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $account->update([
                'verification_status' => 'failed',
                'verification_message' => mb_substr($exception->getMessage(), 0, 1000),
                'last_verified_at' => now(),
            ]);
        }

        $this->forgetCaches();

        return $account->refresh();
    }

    public function forgetCaches(): void
    {
        Cache::forget('configuracoes:notificacoes');
        Cache::forget('configuracoes:index:eager');
        Cache::forget('comunicacao:dashboard');
    }
}
