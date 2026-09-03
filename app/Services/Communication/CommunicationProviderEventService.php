<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\CommunicationDeliveryRecipient;
use App\Models\CommunicationProviderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CommunicationProviderEventService
{
    public function __construct(
        private readonly CommunicationDeliveryService $deliveryService,
        private readonly CommunicationCampaignService $campaignService,
    ) {
    }

    /**
     * @param array{event_id:string,message_id:string,status:string,occurred_at?:string|null,reason?:string|null} $payload
     * @return array{status:string}
     */
    public function handle(string $provider, array $payload): array
    {
        $payloadHash = hash('sha256', json_encode([
            'event_id' => $payload['event_id'],
            'message_id' => $payload['message_id'],
            'status' => $payload['status'],
            'occurred_at' => $payload['occurred_at'] ?? null,
            'reason' => $payload['reason'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $result = DB::transaction(function () use ($provider, $payload, $payloadHash): array {
            $event = CommunicationProviderEvent::query()->firstOrCreate(
                [
                    'provider' => $provider,
                    'external_event_id' => $payload['event_id'],
                ],
                [
                    'provider_message_id' => $payload['message_id'],
                    'event_type' => $payload['status'],
                    'occurred_at' => filled($payload['occurred_at'] ?? null)
                        ? CarbonImmutable::parse((string) $payload['occurred_at'])
                        : null,
                    'received_at' => now(),
                    'payload_hash' => $payloadHash,
                    'status' => 'pending',
                ],
            );

            if (! $event->wasRecentlyCreated && ! hash_equals((string) $event->payload_hash, $payloadHash)) {
                return ['status' => 'conflict'];
            }

            if (! $event->wasRecentlyCreated && in_array($event->status, ['applied', 'ignored'], true)) {
                return ['status' => 'duplicate'];
            }

            $recipient = CommunicationDeliveryRecipient::query()
                ->where('provider_message_id', $payload['message_id'])
                ->whereHas('delivery', static fn ($query) => $query->where('channel', $provider))
                ->lockForUpdate()
                ->first();

            if (! $recipient) {
                $event->update([
                    'status' => 'unmatched',
                    'processed_at' => now(),
                    'error_message' => 'Mensagem externa ainda não correlacionada com um destinatário.',
                ]);

                return ['status' => 'unmatched'];
            }

            $transition = $this->applyTransition($recipient, $payload);
            $event->update([
                'status' => $transition,
                'recipient_id' => $recipient->id,
                'processed_at' => now(),
                'error_message' => $transition === 'ignored'
                    ? 'Evento válido sem progressão de estado.'
                    : null,
            ]);

            return [
                'status' => $transition,
                'recipient_id' => (string) $recipient->id,
            ];
        });

        if (isset($result['recipient_id']) && $result['status'] === 'applied') {
            $recipient = CommunicationDeliveryRecipient::query()
                ->with('delivery.campaign')
                ->findOrFail($result['recipient_id']);
            $delivery = $this->deliveryService->refreshDeliveryStatus($recipient->delivery);
            $this->campaignService->consolidateStatus($delivery->campaign);
        }

        return ['status' => $result['status']];
    }

    /** @param array{status:string,occurred_at?:string|null,reason?:string|null} $payload */
    private function applyTransition(CommunicationDeliveryRecipient $recipient, array $payload): string
    {
        $eventType = $payload['status'];
        $occurredAt = filled($payload['occurred_at'] ?? null)
            ? CarbonImmutable::parse((string) $payload['occurred_at'])
            : now();

        if ($eventType === 'read') {
            if ($recipient->status === 'read') {
                return 'ignored';
            }

            $recipient->update([
                'status' => 'read',
                'delivered_at' => $recipient->delivered_at ?? $occurredAt,
                'read_at' => $recipient->read_at ?? $occurredAt,
                'processing_at' => null,
                'next_attempt_at' => null,
                'error_message' => null,
            ]);

            return 'applied';
        }

        if ($eventType === 'delivered') {
            if (in_array($recipient->status, ['delivered', 'read'], true)) {
                return 'ignored';
            }

            $recipient->update([
                'status' => 'delivered',
                'delivered_at' => $recipient->delivered_at ?? $occurredAt,
                'processing_at' => null,
                'next_attempt_at' => null,
                'error_message' => null,
            ]);

            return 'applied';
        }

        if (in_array($recipient->status, ['delivered', 'read', 'failed'], true)) {
            return 'ignored';
        }

        $recipient->update([
            'status' => 'failed',
            'processing_at' => null,
            'next_attempt_at' => null,
            'error_message' => mb_substr((string) ($payload['reason'] ?? 'Falha comunicada pelo provider.'), 0, 2000),
        ]);

        return 'applied';
    }
}
