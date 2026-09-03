<?php

declare(strict_types=1);

namespace App\Services\Communication\Adapters;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Support\Communication\CommunicationSendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SmsChannelAdapter implements CommunicationChannelAdapter
{
    public function channel(): string
    {
        return 'sms';
    }

    public function send(array $recipient, ?string $subject, ?string $body, string $idempotencyKey): CommunicationSendResult
    {
        $phone = trim((string) ($recipient['phone'] ?? ''));
        if ($phone === '' || blank($body)) {
            return CommunicationSendResult::failure('sms', 'missing_sms_destination', 'Destinatário ou mensagem SMS em falta.');
        }

        $enabled = (bool) config('services.sms.enabled', false);
        $apiUrl = trim((string) config('services.sms.api_url', ''));
        $token = trim((string) config('services.sms.token', ''));
        $sender = trim((string) config('services.sms.sender', ''));
        $provider = (string) config('services.sms.provider', 'sms_http');

        if (! $enabled || $apiUrl === '' || $token === '') {
            Log::warning('SMS provider not configured. Set SMS_ENABLED, SMS_API_URL and SMS_API_TOKEN.');

            return CommunicationSendResult::failure($provider, 'provider_not_configured', 'Provider SMS não configurado.');
        }

        $response = Http::withToken($token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->acceptJson()
            ->asJson()
            ->post($apiUrl, [
                'to' => $phone,
                'message' => $body,
                'from' => $sender,
            ]);

        if (! $response->successful()) {
            Log::error('SMS delivery failed', ['status' => $response->status()]);

            return CommunicationSendResult::failure($provider, 'provider_http_'.$response->status(), 'O provider SMS recusou o envio.');
        }

        $providerMessageId = data_get($response->json(), 'id')
            ?? data_get($response->json(), 'message_id')
            ?? data_get($response->json(), 'data.id');

        if (blank($providerMessageId)) {
            return CommunicationSendResult::failure($provider, 'missing_provider_message_id', 'O provider SMS não devolveu um identificador da mensagem.');
        }

        return CommunicationSendResult::success($provider, (string) $providerMessageId);
    }
}
