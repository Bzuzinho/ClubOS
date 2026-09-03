<?php

declare(strict_types=1);

namespace App\Services\Communication\Adapters;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Models\SocialNetworkAccount;
use App\Services\Communication\MetaGraphClient;
use App\Support\Communication\CommunicationSendResult;

final class FacebookChannelAdapter implements CommunicationChannelAdapter
{
    public function __construct(private readonly MetaGraphClient $client)
    {
    }

    public function channel(): string
    {
        return 'facebook';
    }

    public function send(array $recipient, ?string $subject, ?string $body, string $idempotencyKey): CommunicationSendResult
    {
        $account = $this->account($recipient);
        if (! $account?->isPublishReady()) {
            return CommunicationSendResult::failure('meta_facebook', 'provider_not_configured', 'Conta Facebook não configurada ou inativa.');
        }
        if (blank($body)) {
            return CommunicationSendResult::failure('meta_facebook', 'missing_content', 'A publicação Facebook não tem conteúdo.');
        }

        try {
            $id = $this->client->publishFacebook($account, (string) $body, $recipient['link_url'] ?? null);
            return CommunicationSendResult::success('meta_facebook', $id);
        } catch (\Throwable $exception) {
            return CommunicationSendResult::failure('meta_facebook', 'meta_api_error', $exception->getMessage());
        }
    }

    private function account(array $recipient): ?SocialNetworkAccount
    {
        $account = $recipient['social_network_account'] ?? null;

        return $account instanceof SocialNetworkAccount ? $account : null;
    }
}
