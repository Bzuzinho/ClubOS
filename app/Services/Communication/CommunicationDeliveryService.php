<?php

namespace App\Services\Communication;

use App\Models\AgeGroup;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationCampaignChannel;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationDeliveryAttempt;
use App\Models\CommunicationDeliveryRecipient;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\InAppAlert;
use App\Models\SocialNetworkAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CommunicationDeliveryService
{
    private ?bool $usersHaveAgeGroupColumn = null;

    public function __construct(
        private readonly SegmentResolverService $segmentResolverService,
        private readonly TemplateRenderService $templateRenderService,
        private readonly InAppAlertService $inAppAlertService,
        private readonly CommunicationChannelAdapterRegistry $adapterRegistry,
    ) {
    }

    public function createAndExecuteDelivery(CommunicationCampaign $campaign, CommunicationCampaignChannel $channel, ?string $executedBy = null): CommunicationDelivery
    {
        $deliveryKey = hash('sha256', sprintf('campaign:%s:channel:%s', $campaign->id, $channel->channel));

        $delivery = DB::transaction(function () use ($campaign, $channel, $executedBy, $deliveryKey): CommunicationDelivery {
            CommunicationCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            return CommunicationDelivery::query()->firstOrCreate(
                ['idempotency_key' => $deliveryKey],
                [
                    'campaign_id' => $campaign->id,
                    'channel' => $channel->channel,
                    'segment_id' => $campaign->segment_id,
                    'status' => 'processing',
                    'scheduled_at' => $campaign->scheduled_at,
                    'executed_by' => $executedBy,
                ],
            );
        });

        if ($delivery->wasRecentlyCreated) {
            $recipients = in_array($channel->channel, ['facebook', 'instagram'], true)
                ? $this->resolveSocialRecipients($channel)
                : $this->segmentResolverService->resolveRecipients($campaign->segment, $channel->channel);
            $this->snapshotRecipients($delivery, $recipients);

            $delivery->update([
                'total_recipients' => $recipients->count(),
                'pending_count' => $recipients->count(),
            ]);
        } else {
            $recipients = $this->recipientPayloads($delivery);
        }

        if ($delivery->status === 'completed') {
            return $delivery;
        }

        if ($recipients->isEmpty()) {
            $delivery->update([
                'status' => 'failed',
                'error_message' => 'Sem destinatarios validos para o canal selecionado.',
                'pending_count' => 0,
                'result_summary' => 'Nenhum destinatario resolvido.',
                'completed_at' => now(),
            ]);

            return $delivery;
        }

        foreach ($recipients as $recipient) {
            $recipientRow = CommunicationDeliveryRecipient::query()
                ->where('idempotency_key', $this->recipientKey($delivery, $recipient))
                ->first();

            if (! $recipientRow) {
                continue;
            }

            $attempt = $this->claimAttempt($recipientRow);
            if (! $attempt) {
                continue;
            }

            $rendered = $this->templateRenderService->renderChannelContent(
                $channel,
                $this->buildTemplateVariables($campaign, $channel, $recipient)
            );

            $outcome = $this->sendByChannel(
                $channel->channel,
                $recipient,
                $rendered['subject'],
                $rendered['body'],
                $campaign,
                $delivery,
                (string) $recipientRow->idempotency_key,
            );

            $this->completeAttempt($recipientRow, $attempt, $outcome, $channel->channel);
        }

        if ($this->shouldCreateInAppAlerts($campaign, $channel)) {
            $this->inAppAlertService->createAlerts([
                'campaign_id' => $campaign->id,
                'delivery_id' => $delivery->id,
                'title' => $campaign->alert_title ?: $campaign->title,
                'message' => $campaign->alert_message ?: ($channel->message_body ?: $campaign->description ?: 'Nova comunicacao disponivel.'),
                'link' => $campaign->alert_link,
                'type' => $campaign->alert_type ?: 'info',
            ], $recipients);
        }

        return $this->refreshDeliveryStatus($delivery);
    }

    /** @return array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} */
    private function sendByChannel(
        string $channel,
        array $recipient,
        ?string $subject,
        ?string $body,
        CommunicationCampaign $campaign,
        CommunicationDelivery $delivery,
        string $idempotencyKey,
    ): array
    {
        try {
            $outcome = match ($channel) {
                'interno', 'alert_app' => $this->sendInApp($recipient, $subject, $body, $campaign, $delivery, $channel),
                default => $this->adapterRegistry->resolve($channel)?->send(
                    $recipient,
                    $subject,
                    $body,
                    $idempotencyKey,
                )->toArray(),
            };

            return $outcome ?? $this->failure($channel, 'unsupported_channel', 'Canal de comunicação não suportado.');
        } catch (\Throwable $exception) {
            Log::error('CommunicationDeliveryService::sendByChannel', [
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            return $this->failure($channel, 'provider_exception', $exception->getMessage());
        }
    }

    private function shouldCreateInAppAlerts(CommunicationCampaign $campaign, CommunicationCampaignChannel $channel): bool
    {
        if (in_array($channel->channel, ['interno', 'alert_app'], true)) {
            return false;
        }

        if (!$campaign->create_in_app_alert) {
            return false;
        }

        if ($campaign->channels()->where('channel', 'alert_app')->where('is_enabled', true)->exists()) {
            return false;
        }

        return !$campaign->inAppAlerts()->exists();
    }

    private function snapshotRecipients(CommunicationDelivery $delivery, Collection $recipients): void
    {
        foreach ($recipients as $recipient) {
            CommunicationDeliveryRecipient::query()->firstOrCreate(
                ['idempotency_key' => $this->recipientKey($delivery, $recipient)],
                [
                    'delivery_id' => $delivery->id,
                    'user_id' => $recipient['user_id'] ?? null,
                    'member_id' => $recipient['member_id'] ?? null,
                    'contact_email' => $recipient['email'] ?? null,
                    'contact_phone' => $recipient['phone'] ?? null,
                    'push_token' => $recipient['push_token'] ?? null,
                    'social_network_account_id' => $recipient['social_network_account_id'] ?? null,
                    'status' => 'pending',
                ],
            );
        }
    }

    private function recipientPayloads(CommunicationDelivery $delivery): Collection
    {
        $channel = $delivery->campaign
            ? $delivery->campaign->channels()->where('channel', $delivery->channel)->first()
            : null;

        return $delivery->recipients()->with('socialNetworkAccount')->get()->map(static fn (CommunicationDeliveryRecipient $recipient): array => [
            'user_id' => $recipient->user_id,
            'member_id' => $recipient->member_id,
            'email' => $recipient->contact_email,
            'phone' => $recipient->contact_phone,
            'push_token' => $recipient->push_token,
            'social_network_account_id' => $recipient->social_network_account_id,
            'social_network_account' => $recipient->socialNetworkAccount,
            'link_url' => $channel?->link_url,
            'media_url' => $channel?->media_url,
        ]);
    }

    private function recipientKey(CommunicationDelivery $delivery, array $recipient): string
    {
        $identity = $recipient['user_id']
            ?? $recipient['member_id']
            ?? $recipient['email']
            ?? $recipient['phone']
            ?? $recipient['push_token']
            ?? $recipient['social_network_account_id']
            ?? hash('sha256', json_encode($recipient, JSON_THROW_ON_ERROR));

        return hash('sha256', sprintf('delivery:%s:recipient:%s', $delivery->id, $identity));
    }

    private function claimAttempt(CommunicationDeliveryRecipient $recipient): ?CommunicationDeliveryAttempt
    {
        return DB::transaction(function () use ($recipient): ?CommunicationDeliveryAttempt {
            $locked = CommunicationDeliveryRecipient::query()->lockForUpdate()->findOrFail($recipient->id);

            if (in_array($locked->status, ['sent', 'delivered', 'read'], true)) {
                return null;
            }

            if ($locked->attempt_count >= $locked->max_attempts) {
                return null;
            }

            if ($locked->next_attempt_at?->isFuture()) {
                return null;
            }

            if ($locked->processing_at?->isAfter(now()->subMinutes(10))) {
                return null;
            }

            $attemptNumber = $locked->attempt_count + 1;
            $startedAt = now();
            $locked->update([
                'attempt_count' => $attemptNumber,
                'processing_at' => $startedAt,
                'last_attempt_at' => $startedAt,
                'next_attempt_at' => null,
            ]);

            return CommunicationDeliveryAttempt::query()->create([
                'recipient_id' => $locked->id,
                'attempt_number' => $attemptNumber,
                'status' => 'processing',
                'started_at' => $startedAt,
            ]);
        });
    }

    /** @param array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} $outcome */
    private function completeAttempt(CommunicationDeliveryRecipient $recipient, CommunicationDeliveryAttempt $attempt, array $outcome, string $channel): void
    {
        DB::transaction(function () use ($recipient, $attempt, $outcome, $channel): void {
            $locked = CommunicationDeliveryRecipient::query()->lockForUpdate()->findOrFail($recipient->id);
            $completedAt = now();
            $nextRetryAt = ! $outcome['success'] && $locked->attempt_count < $locked->max_attempts
                ? $completedAt->copy()->addSeconds($this->retryDelaySeconds($locked->attempt_count))
                : null;

            $attempt->update([
                'status' => $outcome['success'] ? 'sent' : 'failed',
                'provider' => $outcome['provider'],
                'provider_message_id' => $outcome['provider_message_id'],
                'error_code' => $outcome['error_code'],
                'error_message' => $outcome['error_message'] ? mb_substr($outcome['error_message'], 0, 2000) : null,
                'completed_at' => $completedAt,
                'next_retry_at' => $nextRetryAt,
            ]);

            $locked->update([
                'status' => $outcome['success']
                    ? (in_array($channel, ['interno', 'alert_app'], true) ? 'delivered' : 'sent')
                    : 'failed',
                'provider' => $outcome['provider'],
                'provider_message_id' => $outcome['provider_message_id'],
                'error_message' => $outcome['error_message'] ? mb_substr($outcome['error_message'], 0, 2000) : null,
                'sent_at' => $outcome['success'] ? $completedAt : null,
                'delivered_at' => $outcome['success'] && in_array($channel, ['interno', 'alert_app'], true) ? $completedAt : null,
                'processing_at' => null,
                'next_attempt_at' => $nextRetryAt,
            ]);
        });
    }

    public function refreshDeliveryStatus(CommunicationDelivery $delivery): CommunicationDelivery
    {
        $recipients = $delivery->recipients()->get();
        $successCount = $recipients->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $retryableCount = $recipients->filter(static fn (CommunicationDeliveryRecipient $recipient): bool =>
            $recipient->status === 'failed'
            && $recipient->attempt_count < $recipient->max_attempts
            && $recipient->next_attempt_at !== null
        )->count();
        $pendingCount = $recipients->where('status', 'pending')->count() + $retryableCount;
        $terminalFailedCount = $recipients->where('status', 'failed')->count() - $retryableCount;

        $status = $pendingCount > 0
            ? 'processing'
            : ($terminalFailedCount === 0 ? 'completed' : ($successCount > 0 ? 'partial' : 'failed'));

        $delivery->update([
            'status' => $status,
            'sent_at' => $pendingCount === 0 ? now() : null,
            'completed_at' => $pendingCount === 0 ? now() : null,
            'success_count' => $successCount,
            'failed_count' => $terminalFailedCount,
            'pending_count' => $pendingCount,
            'error_message' => $terminalFailedCount > 0 ? 'Existem destinatários sem entrega após esgotar as tentativas.' : null,
            'result_summary' => sprintf('Sucesso: %d | Retentativas: %d | Falhas terminais: %d', $successCount, $retryableCount, $terminalFailedCount),
        ]);

        return $delivery->refresh();
    }

    /** @return array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} */
    private function sendInApp(array $recipient, ?string $subject, ?string $body, CommunicationCampaign $campaign, CommunicationDelivery $delivery, string $channel): array
    {
        $userId = (string) ($recipient['user_id'] ?? '');
        if ($userId === '') {
            return $this->failure('clubos_database', 'missing_user', 'Destinatário interno sem utilizador associado.');
        }

        $existingAlert = InAppAlert::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingAlert) {
            return $this->success($channel === 'interno' ? 'clubos_internal' : 'clubos_alert_app', (string) $existingAlert->id);
        }

        $alert = $this->inAppAlertService->createAlert([
            'campaign_id' => $campaign->id,
            'delivery_id' => $delivery->id,
            'title' => $campaign->alert_title ?: $subject ?: $campaign->title,
            'message' => $campaign->alert_message ?: $body ?: $campaign->description ?: 'Nova comunicação disponível.',
            'link' => $campaign->alert_link,
            'type' => $campaign->alert_type ?: 'info',
            'visible_from' => now(),
        ], $userId);

        if (! $alert) {
            return $this->failure('clubos_database', 'in_app_disabled', 'Alertas internos desativados nas preferências.');
        }

        return $this->success($channel === 'interno' ? 'clubos_internal' : 'clubos_alert_app', (string) $alert->id);
    }

    private function retryDelaySeconds(int $attemptNumber): int
    {
        return match ($attemptNumber) {
            1 => 60,
            2 => 300,
            default => 900,
        };
    }

    private function resolveSocialRecipients(CommunicationCampaignChannel $channel): Collection
    {
        return SocialNetworkAccount::query()
            ->where('provider', $channel->channel)
            ->get()
            ->map(static fn (SocialNetworkAccount $account): array => [
                'social_network_account_id' => (string) $account->id,
                'social_network_account' => $account,
                'link_url' => $channel->link_url,
                'media_url' => $channel->media_url,
            ]);
    }

    /** @return array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} */
    private function success(string $provider, ?string $providerMessageId): array
    {
        return [
            'success' => true,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /** @return array{success:bool,provider:string,provider_message_id:?string,error_code:?string,error_message:?string} */
    private function failure(string $provider, string $errorCode, string $errorMessage): array
    {
        return [
            'success' => false,
            'provider' => $provider,
            'provider_message_id' => null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    private function buildTemplateVariables(CommunicationCampaign $campaign, CommunicationCampaignChannel $channel, array $recipient): array
    {
        $user = !empty($recipient['user_id'])
            ? User::query()->find($recipient['user_id'])
            : null;
        $latestInvoice = $user
            ? Invoice::query()
                ->where('user_id', $user->id)
                ->latest('data_vencimento')
                ->latest('created_at')
                ->first()
            : null;
        $latestEvent = $this->resolveContextEvent($campaign, $user);
        $ageGroupName = $this->resolvePrimaryAgeGroupName($user);
        $userTypes = collect($user?->tipo_membro ?? [])->filter()->implode(', ');
        $phone = $user?->telemovel ?: $user?->contacto_telefonico ?: $user?->contacto;

        return [
            'nome' => $user?->nome_completo ?: $recipient['name'] ?? 'Utilizador',
            'nome_atleta' => $user?->nome_completo ?: $recipient['name'] ?? 'Utilizador',
            'nome_utilizador' => $user?->name ?: $user?->nome_completo ?: $recipient['name'] ?? 'Utilizador',
            'numero_socio' => $user?->numero_socio ?: '',
            'email' => $user?->email ?: $recipient['email'] ?? '',
            'telemovel' => $phone ?: $recipient['phone'] ?? '',
            'tipos_utilizador' => $userTypes,
            'escalao' => $ageGroupName,
            'titulo_comunicacao' => $channel->subject ?: $campaign->title,
            'titulo_alerta' => $campaign->alert_title ?: '',
            'mensagem_alerta' => $campaign->alert_message ?: ($channel->message_body ?: $campaign->description ?: ''),
            'mes' => $latestInvoice?->mes ?: '',
            'valor' => $latestInvoice?->valor_total !== null ? (string) $latestInvoice->valor_total : '',
            'valor_em_divida' => $latestInvoice && $latestInvoice->estado_pagamento !== 'pago' ? (string) $latestInvoice->valor_total : '',
            'data_vencimento' => $latestInvoice?->data_vencimento?->format('Y-m-d') ?: '',
            'evento_nome' => $latestEvent?->titulo ?: '',
            'evento_data' => $latestEvent?->data_inicio?->format('Y-m-d') ?: '',
            'evento_local' => $latestEvent?->local ?: '',
        ];
    }

    private function resolvePrimaryAgeGroupName(?User $user): string
    {
        if (!$user) {
            return '';
        }

        if ($this->usersHaveAgeGroupColumn()) {
            $ageGroupId = $user->getAttribute('age_group_id');

            if ($ageGroupId) {
                return (string) optional(AgeGroup::query()->find($ageGroupId))->nome;
            }
        }

        $rawAgeGroupId = is_array($user->escalao ?? null) ? ($user->escalao[0] ?? null) : null;
        if (!$rawAgeGroupId) {
            return '';
        }

        return (string) optional(AgeGroup::query()->find($rawAgeGroupId))->nome;
    }

    private function usersHaveAgeGroupColumn(): bool
    {
        return $this->usersHaveAgeGroupColumn ??= Schema::hasColumn('users', 'age_group_id');
    }

    private function resolveContextEvent(CommunicationCampaign $campaign, ?User $user): ?Event
    {
        $eventId = $campaign->segment?->rules_json['event_id'] ?? null;
        if ($eventId) {
            return Event::query()->find($eventId);
        }

        if (!$user) {
            return null;
        }

        return $user->eventAttendances()->latest('created_at')->first()?->event;
    }
}
