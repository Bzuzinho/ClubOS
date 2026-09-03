<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\SocialNetworkEvent;
use Illuminate\Support\Arr;

final class MetaWebhookService
{
    public function __construct(private readonly CommunicationProviderEventService $providerEvents)
    {
    }

    /** @param array<string,mixed> $payload @return array{accepted:int,applied:int} */
    public function handle(string $provider, array $payload): array
    {
        $accepted = 0;
        $applied = 0;

        foreach (Arr::wrap($payload['entry'] ?? []) as $entry) {
            foreach (Arr::wrap($entry['changes'] ?? []) as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $messageId = (string) ($value['post_id'] ?? $value['media_id'] ?? $value['id'] ?? '');
                $eventType = (string) ($change['field'] ?? $value['status'] ?? 'change');
                $occurredAt = isset($entry['time']) ? date(DATE_ATOM, (int) $entry['time']) : null;
                $canonical = [
                    'object' => $payload['object'] ?? null,
                    'entry_id' => $entry['id'] ?? null,
                    'time' => $entry['time'] ?? null,
                    'field' => $change['field'] ?? null,
                    'message_id' => $messageId,
                    'status' => $value['status'] ?? null,
                ];
                $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $externalEventId = (string) ($value['event_id'] ?? hash('sha256', $provider.':'.$hash));

                $event = SocialNetworkEvent::query()->firstOrCreate(
                    ['provider' => $provider, 'external_event_id' => $externalEventId],
                    [
                        'event_type' => $eventType,
                        'provider_message_id' => $messageId ?: null,
                        'payload_hash' => $hash,
                        'status' => 'received',
                        'occurred_at' => $occurredAt,
                        'received_at' => now(),
                    ],
                );
                $accepted++;

                if (! $event->wasRecentlyCreated) {
                    continue;
                }

                $normalizedStatus = strtolower((string) ($value['status'] ?? ''));
                $pipelineStatus = in_array($normalizedStatus, ['failed', 'error', 'rejected'], true)
                    ? 'failed'
                    : (in_array($normalizedStatus, ['published', 'delivered'], true) ? 'delivered' : null);

                if ($pipelineStatus && $messageId !== '') {
                    $result = $this->providerEvents->handle($provider, [
                        'event_id' => $externalEventId,
                        'message_id' => $messageId,
                        'status' => $pipelineStatus,
                        'occurred_at' => $occurredAt,
                        'reason' => $value['error_message'] ?? null,
                    ]);
                    $event->update([
                        'status' => $result['status'],
                        'processed_at' => now(),
                    ]);
                    $applied += $result['status'] === 'applied' ? 1 : 0;
                } else {
                    $event->update(['status' => 'ignored', 'processed_at' => now()]);
                }
            }
        }

        return ['accepted' => $accepted, 'applied' => $applied];
    }
}
