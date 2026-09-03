<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\CommunicationCampaign;
use App\Models\CommunicationDeliveryRecipient;
use App\Models\CommunicationProviderEvent;
use App\Models\CommunicationSegment;
use App\Models\User;
use App\Services\Communication\Adapters\PushChannelAdapter;
use App\Services\Communication\CommunicationAsyncPipelineAuditService;
use App\Services\Communication\CommunicationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class CommunicationProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_provider_events_progress_recipient_status_idempotently(): void
    {
        config()->set('services.communication_webhooks.secrets.sms', 'webhook-secret');
        $recipient = $this->sentSmsRecipient('provider-message-42');

        $delivered = [
            'event_id' => 'evt-delivered-42',
            'message_id' => 'provider-message-42',
            'status' => 'delivered',
            'occurred_at' => now()->subSecond()->toIso8601String(),
        ];

        $this->postSignedWebhook('sms', $delivered)
            ->assertOk()
            ->assertExactJson(['status' => 'applied']);
        $this->assertSame('delivered', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->delivered_at);

        $this->postSignedWebhook('sms', $delivered)
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate']);

        $this->postSignedWebhook('sms', [
            'event_id' => 'evt-read-42',
            'message_id' => 'provider-message-42',
            'status' => 'read',
            'occurred_at' => now()->toIso8601String(),
        ])->assertOk()->assertExactJson(['status' => 'applied']);
        $this->assertSame('read', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->read_at);

        $this->postSignedWebhook('sms', [
            'event_id' => 'evt-late-failure-42',
            'message_id' => 'provider-message-42',
            'status' => 'failed',
            'reason' => 'late provider failure',
        ])->assertOk()->assertExactJson(['status' => 'ignored']);

        $this->assertSame('read', $recipient->fresh()->status);
        $this->assertSame(3, CommunicationProviderEvent::query()->count());
        $this->assertSame(2, CommunicationProviderEvent::query()->where('status', 'applied')->count());
        $this->assertSame(1, CommunicationProviderEvent::query()->where('status', 'ignored')->count());
        $this->assertFalse(CommunicationProviderEvent::query()->firstOrFail()->getConnection()->getSchemaBuilder()->hasColumn(
            'communication_provider_events',
            'payload',
        ));
    }

    public function test_signature_is_fail_closed_and_rejects_replay(): void
    {
        $payload = [
            'event_id' => 'evt-auth',
            'message_id' => 'provider-message-auth',
            'status' => 'delivered',
        ];

        $this->postSignedWebhook('sms', $payload, 'missing-secret')
            ->assertStatus(503);

        config()->set('services.communication_webhooks.secrets.sms', 'webhook-secret');
        $this->postSignedWebhook('sms', $payload, 'wrong-secret')
            ->assertUnauthorized();
        $this->postSignedWebhook('sms', $payload, 'webhook-secret', now()->subMinutes(6)->timestamp)
            ->assertUnauthorized();

        $this->assertSame(0, CommunicationProviderEvent::query()->count());
    }

    public function test_event_identity_conflict_is_rejected_without_mutating_original_event(): void
    {
        config()->set('services.communication_webhooks.secrets.sms', 'webhook-secret');
        $this->sentSmsRecipient('provider-message-conflict');

        $this->postSignedWebhook('sms', [
            'event_id' => 'evt-conflict',
            'message_id' => 'provider-message-conflict',
            'status' => 'delivered',
        ])->assertOk();

        $this->postSignedWebhook('sms', [
            'event_id' => 'evt-conflict',
            'message_id' => 'provider-message-conflict',
            'status' => 'failed',
        ])->assertStatus(409)->assertExactJson(['status' => 'conflict']);

        $event = CommunicationProviderEvent::query()->sole();
        $this->assertSame('delivered', $event->event_type);
        $this->assertSame('applied', $event->status);
    }

    public function test_unmatched_authenticated_event_is_retained_for_audit(): void
    {
        config()->set('services.communication_webhooks.secrets.email', 'email-webhook-secret');

        $this->postSignedWebhook('email', [
            'event_id' => 'evt-unmatched',
            'message_id' => 'unknown-message',
            'status' => 'failed',
            'reason' => 'bounce',
        ], 'email-webhook-secret')->assertStatus(202)->assertExactJson(['status' => 'unmatched']);

        $event = CommunicationProviderEvent::query()->sole();
        $this->assertSame('unmatched', $event->status);
        $this->assertNull($event->recipient_id);

        $audit = app(CommunicationAsyncPipelineAuditService::class)->audit();
        $this->assertSame(1, $audit['summary']['provider_event_count']);
        $this->assertSame(1, $audit['summary']['provider_event_unmatched_count']);
        $this->assertSame(1, $audit['summary']['warning_count']);
        $this->assertSame(1, $audit['summary']['actionable_count']);
    }

    public function test_push_adapter_requires_configuration_and_preserves_provider_message_id(): void
    {
        $adapter = app(PushChannelAdapter::class);

        $missing = $adapter->send(['push_token' => 'device-token'], 'Título', 'Mensagem', 'push-key');
        $this->assertFalse($missing->success);
        $this->assertSame('provider_not_configured', $missing->errorCode);

        config()->set('services.push.enabled', true);
        config()->set('services.push.api_url', 'https://push.example.test/messages');
        config()->set('services.push.token', 'push-token');
        config()->set('services.push.provider', 'push-test');
        Http::fake([
            'https://push.example.test/messages' => Http::response(['data' => ['id' => 'push-message-1']], 202),
        ]);

        $sent = $adapter->send(['push_token' => 'device-token'], 'Título', 'Mensagem', 'push-key');

        $this->assertTrue($sent->success);
        $this->assertSame('push-test', $sent->provider);
        $this->assertSame('push-message-1', $sent->providerMessageId);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', 'push-key'));
    }

    private function sentSmsRecipient(string $providerMessageId): CommunicationDeliveryRecipient
    {
        config()->set('services.sms.enabled', true);
        config()->set('services.sms.api_url', 'https://sms.example.test/messages');
        config()->set('services.sms.token', 'test-token');
        config()->set('services.sms.sender', 'ClubOS');
        config()->set('services.sms.provider', 'sms-test');
        Http::fake([
            'https://sms.example.test/messages' => Http::response(['message_id' => $providerMessageId], 202),
        ]);

        $author = User::factory()->create();
        $user = User::factory()->create(['contacto_telefonico' => '351910000042']);
        $segment = CommunicationSegment::query()->create([
            'name' => 'Segmento H6c '.uniqid('', true),
            'type' => 'manual',
            'rules_json' => ['source' => 'manual', 'user_ids' => [$user->id]],
            'is_active' => true,
            'created_by' => $author->id,
        ]);
        $campaign = CommunicationCampaign::query()->create([
            'codigo' => 'H6C-'.strtoupper(substr(str_replace('.', '', uniqid('', true)), -10)),
            'title' => 'Comunicação H6c',
            'segment_id' => $segment->id,
            'author_id' => $author->id,
            'status' => 'rascunho',
            'idempotency_key' => hash('sha256', 'h6c-campaign:'.uniqid('', true)),
        ]);
        $channel = $campaign->channels()->create([
            'channel' => 'sms',
            'subject' => 'Assunto H6c',
            'message_body' => 'Mensagem H6c',
            'is_enabled' => true,
        ]);

        $delivery = app(CommunicationDeliveryService::class)->createAndExecuteDelivery(
            $campaign->load('segment'),
            $channel,
        );

        return $delivery->recipients()->sole();
    }

    /** @param array<string,mixed> $payload */
    private function postSignedWebhook(
        string $provider,
        array $payload,
        string $secret = 'webhook-secret',
        ?int $timestamp = null,
    ): TestResponse {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call(
            'POST',
            '/api/webhooks/communication/'.$provider,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CLUBOS_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_CLUBOS_SIGNATURE' => 'sha256='.$signature,
            ],
            $body,
        );
    }
}
