<?php

declare(strict_types=1);

namespace App\Services\Communication\Adapters;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Support\Communication\CommunicationSendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PushChannelAdapter implements CommunicationChannelAdapter
{
    public function channel(): string
    {
        return 'push';
    }

    public function send(array $recipient, ?string $subject, ?string $body, string $idempotencyKey): CommunicationSendResult
    {
        $pushToken = trim((string) ($recipient['push_token'] ?? ''));
        if ($pushToken === '' || blank($body)) {
            return CommunicationSendResult::failure('push', 'missing_push_destination', 'Destinatário ou mensagem push em falta.');
        }

        $enabled = (bool) config('services.push.enabled', false);
        $apiUrl = trim((string) config('services.push.api_url', ''));
        $token = trim((string) config('services.push.token', ''));
        $provider = (string) config('services.push.provider', 'push_http');

        if (! $enabled || $apiUrl === '' || $token === '') {
            Log::warning('Push provider not configured. Set PUSH_ENABLED, PUSH_API_URL and PUSH_API_TOKEN.');

            return CommunicationSendResult::failure($provider, 'provider_not_configured', 'Provider push não configurado.');
        }

        $response = Http::withToken($token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->asJson()
            ->post($apiUrl, [
                'token' => $pushToken,
                'title' => $subject ?: 'ClubOS',
                'message' => $body,
            ]);

        if (! $response->successful()) {
            Log::error('Push delivery failed', ['status' => $response->status()]);

            return CommunicationSendResult::failure($provider, 'provider_http_'.$response->status(), 'O provider push recusou o envio.');
        }

        $providerMessageId = data_get($response->json(), 'id')
            ?? data_get($response->json(), 'message_id')
            ?? data_get($response->json(), 'data.id');

        if (blank($providerMessageId)) {
            return CommunicationSendResult::failure($provider, 'missing_provider_message_id', 'O provider push não devolveu um identificador da mensagem.');
        }

        return CommunicationSendResult::success($provider, (string) $providerMessageId);
    }
}
