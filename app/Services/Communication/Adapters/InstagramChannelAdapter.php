<?php

declare(strict_types=1);

namespace App\Services\Communication\Adapters;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Models\SocialNetworkAccount;
use App\Services\Communication\MetaGraphClient;
use App\Support\Communication\CommunicationSendResult;

final class InstagramChannelAdapter implements CommunicationChannelAdapter
{
    public function __construct(private readonly MetaGraphClient $client)
    {
    }

    public function channel(): string
    {
        return 'instagram';
    }

    public function send(array $recipient, ?string $subject, ?string $body, string $idempotencyKey): CommunicationSendResult
    {
        $account = $this->account($recipient);
        if (! $account?->isPublishReady()) {
            return CommunicationSendResult::failure('meta_instagram', 'provider_not_configured', 'Conta Instagram não configurada ou inativa.');
        }

        $mediaUrl = trim((string) ($recipient['media_url'] ?? ''));
        if (blank($body) || $mediaUrl === '') {
            return CommunicationSendResult::failure('meta_instagram', 'missing_content', 'Instagram exige legenda e URL pública da imagem.');
        }

        try {
            $id = $this->client->publishInstagram($account, (string) $body, $mediaUrl);
            return CommunicationSendResult::success('meta_instagram', $id);
        } catch (\Throwable $exception) {
            return CommunicationSendResult::failure('meta_instagram', 'meta_api_error', $exception->getMessage());
        }
    }

    private function account(array $recipient): ?SocialNetworkAccount
    {
        $account = $recipient['social_network_account'] ?? null;

        return $account instanceof SocialNetworkAccount ? $account : null;
    }
}
