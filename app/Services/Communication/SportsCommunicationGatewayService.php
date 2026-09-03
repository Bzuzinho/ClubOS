<?php

namespace App\Services\Communication;

use App\Contracts\Communication\SportsCommunicationGateway;
use App\Contracts\Communication\SportsCommunicationIntentRequest;
use App\Contracts\Communication\SportsCommunicationIntentResult;
use App\Models\CommunicationTemplate;
use App\Models\NotificationPreference;
use App\Models\SportsCommunicationIntent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SportsCommunicationGatewayService implements SportsCommunicationGateway
{
    public function __construct(private readonly CommunicationCampaignService $campaignService)
    {
    }

    public function publish(SportsCommunicationIntentRequest $request): SportsCommunicationIntentResult
    {
        $intent = SportsCommunicationIntent::query()->firstOrCreate(
            ['idempotency_key' => $request->idempotencyKey()],
            [
                'club_id' => $request->clubId,
                'source_type' => $request->sourceType,
                'source_id' => $request->sourceId,
                'source_version' => $request->sourceVersion,
                'intent_type' => $request->intentType,
                'status' => 'pending',
                'payload_json' => [
                    'recipient_user_ids' => $request->recipientUserIds,
                    'context' => $request->context,
                ],
                'requested_by' => $request->requestedBy,
                'requested_at' => now(),
            ],
        );

        if ((string) $intent->status === 'dispatched') {
            return $this->resultFor($intent);
        }

        if (! $this->canRun() || ! $this->sportsConvocationAutomationEnabled()) {
            $intent->update([
                'status' => 'skipped',
                'failure_reason' => null,
            ]);

            return $this->resultFor($intent->fresh());
        }

        $recipientIds = collect($request->recipientUserIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($recipientIds === []) {
            $intent->update([
                'status' => 'skipped',
                'failure_reason' => 'no_recipients',
            ]);

            return $this->resultFor($intent->fresh());
        }

        $title = trim((string) ($request->context['event_title'] ?? 'Convocatória')) ?: 'Convocatória';
        $date = trim((string) ($request->context['event_date'] ?? '')) ?: 'data por definir';
        $location = trim((string) ($request->context['event_location'] ?? ''));
        $meetingLocation = trim((string) ($request->context['meeting_location'] ?? ''));
        $meetingTime = trim((string) ($request->context['meeting_time'] ?? ''));

        $subject = 'Nova convocatória - '.$title;
        $message = sprintf(
            'Foi publicada uma convocatória para %s em %s%s%s.',
            $title,
            $date,
            $location !== '' ? ' no local '.$location : '',
            $meetingLocation !== '' || $meetingTime !== ''
                ? sprintf(
                    '. Encontro%s%s',
                    $meetingLocation !== '' ? ' em '.$meetingLocation : '',
                    $meetingTime !== '' ? ' às '.$meetingTime : '',
                )
                : '',
        );

        $channels = $this->buildChannels($subject, $message);
        if ($channels === []) {
            $intent->update([
                'status' => 'skipped',
                'failure_reason' => 'no_enabled_channels',
            ]);

            return $this->resultFor($intent->fresh());
        }

        try {
            $campaign = $this->campaignService->queueIndividualCommunication([
                'title' => $subject,
                'alert_category' => 'geral',
                'alert_title' => 'Nova convocatória',
                'alert_message' => $message,
                'alert_type' => 'info',
                'recipient_user_ids' => $recipientIds,
                'context_event_id' => $request->context['event_id'] ?? null,
                'channels' => $channels,
                'source_type' => 'sports_intent',
                'source_id' => $request->idempotencyKey(),
                'idempotency_key' => hash('sha256', 'sports_intent:'.$request->idempotencyKey()),
            ], $request->requestedBy);

            $campaign->update([
                'notes' => trim(($campaign->notes ? $campaign->notes.' | ' : '').'origem: sports_intent:'.$request->idempotencyKey()),
            ]);

            $intent->update([
                'status' => 'dispatched',
                'campaign_id' => $campaign->id,
                'dispatched_at' => now(),
                'failure_reason' => null,
            ]);
        } catch (\Throwable $exception) {
            $intent->update([
                'status' => 'failed',
                'failure_reason' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::error('Sports communication intent dispatch failed', [
                'intent_id' => $intent->id,
                'source_type' => $request->sourceType,
                'source_id' => $request->sourceId,
                'source_version' => $request->sourceVersion,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->resultFor($intent->fresh());
    }

    /** @return list<array<string,mixed>> */
    private function buildChannels(string $subject, string $message): array
    {
        $channels = [];

        if ($this->automationEnabled('email_notificacoes')) {
            $channels[] = [
                'channel' => 'email',
                'is_enabled' => true,
                'template_id' => $this->resolveTemplateId('email', [
                    'Automação Desportivo - Convocatória Email',
                    'Automação Eventos - Convocatória Email',
                ]),
                'subject' => $subject,
                'message_body' => $message,
            ];
        }

        if ($this->automationEnabled('alertas_aplicacao')) {
            $channels[] = [
                'channel' => 'alert_app',
                'is_enabled' => true,
                'template_id' => $this->resolveTemplateId('alert_app', [
                    'Automação Desportivo - Convocatória App',
                    'Automação Eventos - Convocatória App',
                ]),
                'subject' => $subject,
                'message_body' => $message,
            ];
        }

        return $channels;
    }

    private function resolveTemplateId(string $channel, array $preferredNames): ?string
    {
        $preferred = CommunicationTemplate::query()
            ->where('status', 'ativo')
            ->where('channel', $channel)
            ->whereIn('name', $preferredNames)
            ->value('id');

        if ($preferred) {
            return $preferred;
        }

        return CommunicationTemplate::query()
            ->where('status', 'ativo')
            ->where('channel', $channel)
            ->where('category', 'geral')
            ->value('id');
    }

    private function canRun(): bool
    {
        return Schema::hasTable('communication_campaigns')
            && Schema::hasTable('communication_segments')
            && Schema::hasTable('communication_templates')
            && Schema::hasTable('sports_communication_intents');
    }

    private function sportsConvocationAutomationEnabled(): bool
    {
        return $this->automationEnabled('alertas_atividade')
            && $this->automationEnabled('automacoes_eventos')
            && $this->automationEnabled('automacoes_convocatorias_eventos');
    }

    private function automationEnabled(string $field): bool
    {
        if (! Schema::hasTable('notification_preferences')) {
            return false;
        }

        $prefs = NotificationPreference::query()->first();
        if (! $prefs) {
            return false;
        }

        $attributes = $prefs->getAttributes();
        if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
            return false;
        }

        return filter_var($attributes[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private function resultFor(SportsCommunicationIntent $intent): SportsCommunicationIntentResult
    {
        return new SportsCommunicationIntentResult(
            intentId: (string) $intent->id,
            status: (string) $intent->status,
            campaignId: $intent->campaign_id ? (string) $intent->campaign_id : null,
            failureReason: $intent->failure_reason,
        );
    }
}
