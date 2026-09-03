<?php

namespace App\Services\Communication;

use App\Jobs\ProcessCommunicationCampaignJob;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationSegment;
use App\Support\Communication\AlertCategoryRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunicationCampaignService
{
    private const TECHNICAL_INDIVIDUAL_SEGMENT_DESCRIPTION = 'Segmento tecnico criado automaticamente para envio individual.';

    public function __construct(private readonly CommunicationDeliveryService $deliveryService)
    {
    }

    public function createCampaign(array $payload, ?string $authorId = null): CommunicationCampaign
    {
        return DB::transaction(function () use ($payload, $authorId) {
            $campaign = CommunicationCampaign::create([
                'codigo' => $payload['codigo'] ?? $this->generateCode(),
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'segment_id' => $payload['segment_id'] ?? null,
                'author_id' => $authorId,
                'status' => $payload['status'] ?? 'rascunho',
                'scheduled_at' => $payload['scheduled_at'] ?? null,
                'create_in_app_alert' => (bool) ($payload['create_in_app_alert'] ?? false),
                'alert_title' => $payload['alert_title'] ?? null,
                'alert_message' => $payload['alert_message'] ?? null,
                'alert_link' => $payload['alert_link'] ?? null,
                'alert_type' => $payload['alert_type'] ?? 'info',
                'notes' => $payload['notes'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? $this->generateIdempotencyKey(),
            ]);

            foreach ($payload['channels'] as $channel) {
                $campaign->channels()->create([
                    'channel' => $channel['channel'],
                    'template_id' => $channel['template_id'] ?? null,
                    'subject' => $channel['subject'] ?? null,
                    'message_body' => $channel['message_body'] ?? null,
                    'is_enabled' => (bool) ($channel['is_enabled'] ?? true),
                ]);
            }

            return $campaign->load(['channels', 'segment']);
        });
    }

    public function updateCampaign(CommunicationCampaign $campaign, array $payload): CommunicationCampaign
    {
        return DB::transaction(function () use ($campaign, $payload) {
            $campaign->loadMissing('segment');

            $idempotencyKey = $campaign->idempotency_key;
            if (! $idempotencyKey && (
                ($payload['status'] ?? null) === 'agendada'
                || filled($payload['scheduled_at'] ?? null)
            )) {
                $idempotencyKey = $this->generateIdempotencyKey();
            }

            $campaign->update([
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'segment_id' => $payload['segment_id'] ?? null,
                'status' => $payload['status'] ?? $campaign->status,
                'scheduled_at' => $payload['scheduled_at'] ?? $campaign->scheduled_at,
                'create_in_app_alert' => (bool) ($payload['create_in_app_alert'] ?? false),
                'alert_title' => $payload['alert_title'] ?? null,
                'alert_message' => $payload['alert_message'] ?? null,
                'alert_link' => $payload['alert_link'] ?? null,
                'alert_type' => $payload['alert_type'] ?? 'info',
                'notes' => $payload['notes'] ?? $campaign->notes,
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($campaign->segment && (
                array_key_exists('recipient_user_ids', $payload)
                || array_key_exists('recipient_age_group_ids', $payload)
                || array_key_exists('recipient_user_types', $payload)
            )) {
                $existingRules = $campaign->segment->rules_json ?? [];

                $campaign->segment->update([
                    'rules_json' => [
                        ...$existingRules,
                        'source' => $existingRules['source'] ?? 'manual',
                        'user_ids' => $payload['recipient_user_ids'] ?? ($existingRules['user_ids'] ?? []),
                        'age_group_ids' => $payload['recipient_age_group_ids'] ?? ($existingRules['age_group_ids'] ?? []),
                        'user_types' => $payload['recipient_user_types'] ?? ($existingRules['user_types'] ?? []),
                    ],
                    'is_active' => filled($payload['scheduled_at'] ?? $campaign->scheduled_at),
                ]);
            }

            if (isset($payload['channels']) && is_array($payload['channels'])) {
                $campaign->channels()->delete();

                foreach ($payload['channels'] as $channel) {
                    $campaign->channels()->create([
                        'channel' => $channel['channel'],
                        'template_id' => $channel['template_id'] ?? null,
                        'subject' => $channel['subject'] ?? null,
                        'message_body' => $channel['message_body'] ?? null,
                        'is_enabled' => (bool) ($channel['is_enabled'] ?? true),
                    ]);
                }
            }

            return $campaign->load(['channels', 'segment']);
        });
    }

    public function duplicateCampaign(CommunicationCampaign $campaign, ?string $authorId = null): CommunicationCampaign
    {
        return DB::transaction(function () use ($campaign, $authorId) {
            $duplicate = CommunicationCampaign::create([
                'codigo' => $this->generateCode(),
                'title' => $campaign->title . ' (Copia)',
                'description' => $campaign->description,
                'segment_id' => $campaign->segment_id,
                'author_id' => $authorId,
                'status' => 'rascunho',
                'create_in_app_alert' => $campaign->create_in_app_alert,
                'alert_title' => $campaign->alert_title,
                'alert_message' => $campaign->alert_message,
                'alert_link' => $campaign->alert_link,
                'alert_type' => $campaign->alert_type,
                'notes' => $campaign->notes,
                'idempotency_key' => $this->generateIdempotencyKey(),
            ]);

            foreach ($campaign->channels as $channel) {
                $duplicate->channels()->create([
                    'channel' => $channel->channel,
                    'template_id' => $channel->template_id,
                    'subject' => $channel->subject,
                    'message_body' => $channel->message_body,
                    'is_enabled' => $channel->is_enabled,
                ]);
            }

            return $duplicate->load(['channels', 'segment']);
        });
    }

    public function scheduleCampaign(CommunicationCampaign $campaign, string $scheduledAt): CommunicationCampaign
    {
        $campaign->update([
            'scheduled_at' => $scheduledAt,
            'status' => 'agendada',
            'idempotency_key' => $campaign->idempotency_key ?? $this->generateIdempotencyKey(),
        ]);

        return $campaign->refresh();
    }

    public function sendCampaign(CommunicationCampaign $campaign, ?string $executedBy = null, bool $dispatchToQueue = true): CommunicationCampaign
    {
        if (!$campaign->segment_id) {
            throw new \RuntimeException('Campanha sem segmento definido.');
        }

        if ($campaign->channels()->where('is_enabled', true)->count() === 0) {
            throw new \RuntimeException('Campanha sem canais ativos.');
        }

        $dispatchRequired = true;
        $campaign = DB::transaction(function () use ($campaign, &$dispatchRequired): CommunicationCampaign {
            $locked = CommunicationCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (in_array($locked->status, ['enviada', 'cancelada'], true)) {
                $dispatchRequired = false;
                return $locked;
            }

            if ($locked->status === 'em_processamento' && $locked->dispatch_requested_at !== null) {
                $dispatchRequired = false;
                return $locked;
            }

            $locked->update([
                'status' => 'em_processamento',
                'dispatch_requested_at' => now(),
            ]);

            return $locked->refresh();
        });

        if (! $dispatchRequired) {
            return $campaign;
        }

        if ($dispatchToQueue) {
            ProcessCommunicationCampaignJob::dispatch($campaign->id, $executedBy)->afterCommit();
            return $campaign->refresh();
        }

        $campaign->load(['channels', 'segment']);
        foreach ($campaign->channels->where('is_enabled', true) as $channel) {
            $this->deliveryService->createAndExecuteDelivery($campaign, $channel, $executedBy);
        }

        return $this->consolidateStatus($campaign->refresh());
    }

    public function cancelCampaign(CommunicationCampaign $campaign): CommunicationCampaign
    {
        $campaign->update([
            'status' => 'cancelada',
        ]);

        return $campaign->refresh();
    }

    public function sendIndividualCommunication(array $payload, ?string $authorId = null): CommunicationCampaign
    {
        $campaign = $this->createOrReuseIndividualCampaign($payload, $authorId);

        if ($campaign->status === 'agendada') {
            return $campaign->fresh(['channels', 'segment']);
        }

        return $this->sendCampaign($campaign->load(['channels', 'segment']), $authorId, false);
    }

    public function queueIndividualCommunication(array $payload, ?string $authorId = null): CommunicationCampaign
    {
        return DB::transaction(function () use ($payload, $authorId): CommunicationCampaign {
            $campaign = $this->createOrReuseIndividualCampaign($payload, $authorId);

            if ($campaign->status === 'agendada') {
                return $campaign->fresh(['channels', 'segment']);
            }

            return $this->sendCampaign($campaign->load(['channels', 'segment']), $authorId, true);
        });
    }

    private function createOrReuseIndividualCampaign(array $payload, ?string $authorId): CommunicationCampaign
    {
        if (! empty($payload['idempotency_key'])) {
            $existing = CommunicationCampaign::query()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing->load(['channels', 'segment']);
            }
        }

        $campaign = DB::transaction(function () use ($payload, $authorId) {
            $scheduledAt = $payload['scheduled_at'] ?? null;
            $isScheduled = filled($scheduledAt);

            $segment = CommunicationSegment::create([
                'name' => sprintf('Envio individual %s', now()->format('d/m/Y H:i')),
                'slug' => null,
                'type' => 'system',
                'description' => self::TECHNICAL_INDIVIDUAL_SEGMENT_DESCRIPTION,
                'rules_json' => [
                    'source' => 'manual',
                    'user_ids' => $payload['recipient_user_ids'],
                    'age_group_ids' => $payload['recipient_age_group_ids'] ?? [],
                    'user_types' => $payload['recipient_user_types'] ?? [],
                    'event_id' => $payload['context_event_id'] ?? null,
                ],
                'is_active' => $isScheduled,
                'created_by' => $authorId,
            ]);

            $enabledChannels = collect($payload['channels'])
                ->filter(fn (array $channel) => (bool) ($channel['is_enabled'] ?? true))
                ->values();

            $categoryLabel = AlertCategoryRegistry::find($payload['alert_category'] ?? null, false)['name'] ?? 'Geral';

            return $this->createCampaign([
                'title' => filled($payload['title'] ?? null)
                    ? trim((string) $payload['title'])
                    : sprintf('Alerta Individual - %s', $categoryLabel),
                'description' => $payload['alert_message'] ?? null,
                'segment_id' => $segment->id,
                'status' => $isScheduled ? 'agendada' : 'rascunho',
                'scheduled_at' => $scheduledAt,
                'create_in_app_alert' => $enabledChannels->contains(fn (array $channel) => ($channel['channel'] ?? null) === 'alert_app'),
                'alert_title' => $payload['alert_title'] ?? null,
                'alert_message' => $payload['alert_message'] ?? null,
                'alert_type' => $payload['alert_type'] ?? 'info',
                'notes' => sprintf('Envio individual | categoria: %s | modo: %s', $payload['alert_category'], $isScheduled ? 'agendado' : 'imediato'),
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'channels' => $enabledChannels->all(),
            ], $authorId);
        });

        return $campaign;
    }

    public function consolidateStatus(CommunicationCampaign $campaign): CommunicationCampaign
    {
        $campaign->load('deliveries.recipients');
        $deliveries = $campaign->deliveries;

        if ($deliveries->isEmpty()) {
            return $campaign;
        }

        $hasPendingRetries = $deliveries->contains(fn ($delivery): bool =>
            $delivery->status === 'processing'
            || $delivery->recipients->contains(fn ($recipient): bool =>
                $recipient->status === 'failed'
                && $recipient->attempt_count < $recipient->max_attempts
                && $recipient->next_attempt_at !== null
            )
        );

        if ($hasPendingRetries) {
            $campaign->update(['status' => 'em_processamento']);

            return $campaign->refresh();
        }

        $hasFailures = $deliveries->contains(fn ($delivery) => in_array($delivery->status, ['failed', 'partial'], true));
        $hasSuccess = $deliveries->contains(fn ($delivery) => in_array($delivery->status, ['completed', 'partial'], true));

        $status = $hasFailures && !$hasSuccess ? 'falhada' : 'enviada';

        $campaign->update([
            'status' => $status,
            'sent_at' => now(),
        ]);

        return $campaign->refresh();
    }

    private function generateCode(): string
    {
        return sprintf('CMP-%s-%s', now()->format('Ymd'), Str::upper(Str::random(5)));
    }

    private function generateIdempotencyKey(): string
    {
        return hash('sha256', 'campaign:'.Str::uuid()->toString());
    }
}
