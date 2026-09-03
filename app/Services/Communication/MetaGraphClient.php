<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\SocialNetworkAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class MetaGraphClient
{
    /** @return array{id:string,name:?string,username:?string} */
    public function verify(SocialNetworkAccount $account): array
    {
        $response = $this->request()->get($this->url($account), [
            'fields' => $account->provider === 'instagram' ? 'id,name,username' : 'id,name',
            'access_token' => $account->access_token,
            ...$this->proof($account),
        ]);

        $this->throwIfRejected($response);

        return [
            'id' => (string) $response->json('id'),
            'name' => $response->json('name'),
            'username' => $response->json('username'),
        ];
    }

    public function publishFacebook(SocialNetworkAccount $account, string $message, ?string $link): string
    {
        $response = $this->request()->asForm()->post($this->url($account, 'feed'), [
            'message' => $message,
            'link' => filled($link) ? $link : null,
            'access_token' => $account->access_token,
            ...$this->proof($account),
        ]);

        $this->throwIfRejected($response);

        $id = trim((string) $response->json('id'));
        if ($id === '') {
            throw new \RuntimeException('A Meta não devolveu o identificador da publicação Facebook.');
        }

        return $id;
    }

    public function publishInstagram(SocialNetworkAccount $account, string $caption, string $mediaUrl): string
    {
        $container = $this->request()->asForm()->post($this->url($account, 'media'), [
            'image_url' => $mediaUrl,
            'caption' => $caption,
            'access_token' => $account->access_token,
            ...$this->proof($account),
        ]);

        $this->throwIfRejected($container);
        $creationId = trim((string) $container->json('id'));
        if ($creationId === '') {
            throw new \RuntimeException('A Meta não devolveu o contentor de publicação Instagram.');
        }

        $published = $this->request()->asForm()->post($this->url($account, 'media_publish'), [
            'creation_id' => $creationId,
            'access_token' => $account->access_token,
            ...$this->proof($account),
        ]);

        $this->throwIfRejected($published);
        $id = trim((string) $published->json('id'));
        if ($id === '') {
            throw new \RuntimeException('A Meta não devolveu o identificador da publicação Instagram.');
        }

        return $id;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()->timeout(20)->connectTimeout(5);
    }

    private function url(SocialNetworkAccount $account, ?string $edge = null): string
    {
        $base = sprintf(
            'https://graph.facebook.com/%s/%s',
            rawurlencode($account->graph_api_version ?: 'v24.0'),
            rawurlencode((string) $account->external_account_id),
        );

        return $edge ? $base.'/'.rawurlencode($edge) : $base;
    }

    /** @return array<string,string> */
    private function proof(SocialNetworkAccount $account): array
    {
        if (blank($account->app_secret) || blank($account->access_token)) {
            return [];
        }

        return ['appsecret_proof' => hash_hmac('sha256', (string) $account->access_token, (string) $account->app_secret)];
    }

    private function throwIfRejected(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $code = (string) ($response->json('error.code') ?? $response->status());
        $message = (string) ($response->json('error.message') ?? 'Pedido recusado pela Meta.');

        throw new \RuntimeException(sprintf('Meta Graph API (%s): %s', $code, mb_substr($message, 0, 500)));
    }
}
