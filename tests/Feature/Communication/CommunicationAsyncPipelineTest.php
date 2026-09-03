<?php

namespace Tests\Feature\Communication;

use App\Jobs\ProcessCommunicationCampaignJob;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationDeliveryAttempt;
use App\Models\CommunicationDeliveryRecipient;
use App\Models\CommunicationSegment;
use App\Models\InAppAlert;
use App\Models\User;
use App\Services\Communication\CommunicationAsyncPipelineAuditService;
use App\Services\Communication\CommunicationCampaignService;
use App\Services\Communication\CommunicationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommunicationAsyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_sms_is_retried_without_duplicating_delivery_and_preserves_provider_id(): void
    {
        config()->set('services.sms.enabled', true);
        config()->set('services.sms.api_url', 'https://sms.example.test/messages');
        config()->set('services.sms.token', 'test-token');
        config()->set('services.sms.sender', 'ClubOS');
        config()->set('services.sms.provider', 'sms-test');

        Http::fakeSequence()
            ->push(['error' => 'temporary'], 503)
            ->push(['message_id' => 'provider-message-42'], 202);

        [$campaign, $channel] = $this->campaignWithRecipient('sms', [
            'contacto_telefonico' => '351910000000',
        ]);

        $service = app(CommunicationDeliveryService::class);
        $first = $service->createAndExecuteDelivery($campaign, $channel);
        $recipient = $first->recipients()->firstOrFail();

        $this->assertSame('processing', $first->status);
        $this->assertSame('failed', $recipient->status);
        $this->assertSame(1, $recipient->attempt_count);
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertSame('failed', $recipient->attempts()->firstOrFail()->status);

        $recipient->update(['next_attempt_at' => now()->subSecond()]);

        $second = $service->createAndExecuteDelivery($campaign->fresh(['segment']), $channel->fresh());
        $recipient->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('completed', $second->status);
        $this->assertSame('sent', $recipient->status);
        $this->assertSame(2, $recipient->attempt_count);
        $this->assertSame('sms-test', $recipient->provider);
        $this->assertSame('provider-message-42', $recipient->provider_message_id);
        $this->assertSame(2, CommunicationDeliveryAttempt::query()->count());
        $this->assertSame(1, CommunicationDelivery::query()->count());

        $service->createAndExecuteDelivery($campaign->fresh(['segment']), $channel->fresh());

        $this->assertSame(2, CommunicationDeliveryAttempt::query()->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key'));
    }

    public function test_sms_http_success_without_provider_id_is_not_recorded_as_sent(): void
    {
        config()->set('services.sms.enabled', true);
        config()->set('services.sms.api_url', 'https://sms.example.test/messages');
        config()->set('services.sms.token', 'test-token');
        config()->set('services.sms.provider', 'sms-test');
        Http::fake([
            'https://sms.example.test/messages' => Http::response(['accepted' => true], 202),
        ]);

        [$campaign, $channel] = $this->campaignWithRecipient('sms', [
            'contacto_telefonico' => '351910000099',
        ]);

        $delivery = app(CommunicationDeliveryService::class)->createAndExecuteDelivery($campaign, $channel);
        $recipient = $delivery->recipients()->sole();
        $attempt = $recipient->attempts()->sole();

        $this->assertSame('processing', $delivery->status);
        $this->assertSame('failed', $recipient->status);
        $this->assertNull($recipient->provider_message_id);
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertSame('missing_provider_message_id', $attempt->error_code);
    }

    public function test_in_app_retry_is_idempotent_and_persists_internal_provider_reference(): void
    {
        [$campaign, $channel] = $this->campaignWithRecipient('alert_app');
        $service = app(CommunicationDeliveryService::class);

        $first = $service->createAndExecuteDelivery($campaign, $channel);
        $second = $service->createAndExecuteDelivery($campaign->fresh(['segment']), $channel->fresh());
        $recipient = $first->recipients()->firstOrFail();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('completed', $second->status);
        $this->assertSame(1, InAppAlert::query()->where('delivery_id', $first->id)->count());
        $this->assertSame(1, $recipient->attempts()->count());
        $this->assertSame('clubos_alert_app', $recipient->provider);
        $this->assertSame(
            InAppAlert::query()->where('delivery_id', $first->id)->value('id'),
            $recipient->provider_message_id,
        );
    }

    public function test_due_dispatcher_enqueues_scheduled_and_retry_campaigns_once(): void
    {
        Queue::fake();

        [$scheduled] = $this->campaignWithRecipient('alert_app');
        $scheduled->update([
            'status' => 'agendada',
            'scheduled_at' => now()->subMinute(),
        ]);

        [$legacyScheduled] = $this->campaignWithRecipient('alert_app');
        $legacyScheduled->update([
            'status' => 'agendada',
            'scheduled_at' => now()->subMinute(),
            'idempotency_key' => null,
        ]);

        [$retryCampaign, $retryChannel] = $this->campaignWithRecipient('sms', [
            'contacto_telefonico' => '351910000001',
        ]);
        $retryCampaign->update(['status' => 'falhada']);
        $retryDelivery = CommunicationDelivery::create([
            'campaign_id' => $retryCampaign->id,
            'channel' => 'sms',
            'segment_id' => $retryCampaign->segment_id,
            'status' => 'processing',
            'idempotency_key' => hash('sha256', 'retry-delivery'),
            'total_recipients' => 1,
            'pending_count' => 1,
        ]);
        CommunicationDeliveryRecipient::create([
            'delivery_id' => $retryDelivery->id,
            'user_id' => $retryCampaign->segment->rules_json['user_ids'][0],
            'contact_phone' => '351910000001',
            'status' => 'failed',
            'idempotency_key' => hash('sha256', 'retry-recipient'),
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_attempt_at' => now()->subSecond(),
        ]);

        $this->assertSame(0, Artisan::call('communication:dispatch-due'));
        $this->assertSame('em_processamento', $scheduled->fresh()->status);
        $this->assertSame('agendada', $legacyScheduled->fresh()->status);
        $this->assertSame('em_processamento', $retryCampaign->fresh()->status);

        Queue::assertPushed(ProcessCommunicationCampaignJob::class, 2);

        Artisan::call('communication:dispatch-due');
        Queue::assertPushed(ProcessCommunicationCampaignJob::class, 2);
        $this->assertNotNull($retryChannel);
    }

    public function test_due_dispatcher_recovers_pending_and_stale_automation_outbox_once(): void
    {
        Queue::fake();

        [$pending] = $this->campaignWithRecipient('alert_app');
        $pending->update([
            'source_type' => 'invoice',
            'source_id' => 'invoice-pending',
            'status' => 'rascunho',
            'dispatch_requested_at' => null,
        ]);

        [$stale] = $this->campaignWithRecipient('alert_app');
        $stale->update([
            'source_type' => 'logistics_request_created',
            'source_id' => 'request-stale',
            'status' => 'em_processamento',
            'dispatch_requested_at' => now()->subMinutes(11),
        ]);

        $this->assertSame(0, Artisan::call('communication:dispatch-due'));
        $this->assertSame('em_processamento', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->dispatch_requested_at);
        $this->assertTrue($stale->fresh()->dispatch_requested_at->gt(now()->subMinute()));
        Queue::assertPushed(ProcessCommunicationCampaignJob::class, 2);

        Artisan::call('communication:dispatch-due');
        Queue::assertPushed(ProcessCommunicationCampaignJob::class, 2);
    }

    public function test_structured_origin_is_idempotent_at_campaign_level(): void
    {
        $recipient = User::factory()->create();
        $payload = [
            'title' => 'Convocatória H6a',
            'alert_category' => 'geral',
            'alert_title' => 'Nova convocatória',
            'alert_message' => 'Mensagem única',
            'alert_type' => 'info',
            'recipient_user_ids' => [$recipient->id],
            'source_type' => 'sports_intent',
            'source_id' => 'convocation:42:v3',
            'idempotency_key' => hash('sha256', 'sports_intent:convocation:42:v3'),
            'channels' => [[
                'channel' => 'alert_app',
                'is_enabled' => true,
                'message_body' => 'Mensagem única',
            ]],
        ];

        $service = app(CommunicationCampaignService::class);
        $first = $service->sendIndividualCommunication($payload);
        $second = $service->sendIndividualCommunication($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('sports_intent', $first->source_type);
        $this->assertSame('convocation:42:v3', $first->source_id);
        $this->assertSame(1, CommunicationCampaign::query()->count());
        $this->assertSame(1, CommunicationSegment::query()->count());
        $this->assertSame(1, CommunicationDelivery::query()->count());
        $this->assertSame(1, CommunicationDeliveryAttempt::query()->count());
        $this->assertSame(1, InAppAlert::query()->count());
    }

    public function test_async_pipeline_audit_is_read_only_and_reports_complete_schema(): void
    {
        [$campaign, $channel] = $this->campaignWithRecipient('alert_app');
        app(CommunicationDeliveryService::class)->createAndExecuteDelivery($campaign, $channel);

        $before = [
            CommunicationCampaign::query()->count(),
            CommunicationDelivery::query()->count(),
            CommunicationDeliveryRecipient::query()->count(),
            CommunicationDeliveryAttempt::query()->count(),
        ];

        $payload = app(CommunicationAsyncPipelineAuditService::class)->audit();

        $this->assertSame('h6d-social-network-publishing-audit-v4', $payload['version']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['summary']['schema_ready']);
        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['managed_delivery_count']);
        $this->assertSame(1, $payload['summary']['attempt_count']);
        $this->assertSame(0, $payload['summary']['provider_event_count']);
        $this->assertTrue($payload['interpretation']['external_channels_use_explicit_adapters']);
        $this->assertTrue($payload['interpretation']['provider_callbacks_require_hmac_and_fresh_timestamp']);
        $this->assertTrue($payload['interpretation']['future_social_network_providers_must_reuse_this_pipeline']);
        $this->assertTrue($payload['interpretation']['social_networks_reuse_canonical_campaigns_deliveries_and_attempts']);
        $this->assertTrue($payload['interpretation']['social_credentials_are_encrypted_and_never_exposed_by_settings_payloads']);
        $this->assertTrue($payload['interpretation']['no_data_changed']);
        $this->assertSame($before, [
            CommunicationCampaign::query()->count(),
            CommunicationDelivery::query()->count(),
            CommunicationDeliveryRecipient::query()->count(),
            CommunicationDeliveryAttempt::query()->count(),
        ]);
    }

    public function test_async_pipeline_audit_reports_recoverable_automation_outbox(): void
    {
        [$campaign] = $this->campaignWithRecipient('alert_app');
        $campaign->update([
            'source_type' => 'invoice',
            'source_id' => 'invoice-orphan',
            'status' => 'rascunho',
            'dispatch_requested_at' => null,
        ]);

        $payload = app(CommunicationAsyncPipelineAuditService::class)->audit();

        $this->assertSame(1, $payload['summary']['automation_campaign_count']);
        $this->assertSame(1, $payload['summary']['automation_without_dispatch_request_count']);
        $this->assertSame(1, $payload['summary']['outbox_recovery_due_count']);
        $this->assertSame(1, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['actionable_count']);
        $this->assertTrue($payload['interpretation']['automatic_sources_dispatch_via_persistent_outbox']);
        $this->assertTrue($payload['interpretation']['stale_automatic_outbox_campaigns_are_recoverable']);
    }

    /** @return array{CommunicationCampaign,\App\Models\CommunicationCampaignChannel} */
    private function campaignWithRecipient(string $channel, array $userOverrides = []): array
    {
        $author = User::factory()->create();
        $recipient = User::factory()->create($userOverrides);
        $segment = CommunicationSegment::create([
            'name' => 'Segmento H6a '.uniqid('', true),
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$recipient->id]],
            'is_active' => true,
            'created_by' => $author->id,
        ]);
        $campaign = CommunicationCampaign::create([
            'codigo' => 'H6A-'.strtoupper(substr(str_replace('.', '', uniqid('', true)), -10)),
            'title' => 'Comunicação H6a',
            'segment_id' => $segment->id,
            'author_id' => $author->id,
            'status' => 'rascunho',
            'alert_title' => 'Alerta H6a',
            'alert_message' => 'Mensagem persistente',
            'alert_type' => 'info',
            'idempotency_key' => hash('sha256', 'test-campaign:'.uniqid('', true)),
        ]);
        $campaignChannel = $campaign->channels()->create([
            'channel' => $channel,
            'subject' => 'Assunto H6a',
            'message_body' => 'Mensagem H6a',
            'is_enabled' => true,
        ]);

        return [$campaign->load('segment'), $campaignChannel];
    }
}
