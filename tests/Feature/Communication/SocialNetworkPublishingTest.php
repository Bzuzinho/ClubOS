<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\CommunicationCampaign;
use App\Models\CommunicationDeliveryRecipient;
use App\Models\CommunicationSegment;
use App\Models\SocialNetworkAccount;
use App\Models\SocialNetworkEvent;
use App\Models\User;
use App\Services\Communication\CommunicationDeliveryService;
use App\Services\Communication\SocialNetworkAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SocialNetworkPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_encrypt_secrets_and_only_return_safe_configuration(): void
    {
        $user = User::factory()->create();
        $account = app(SocialNetworkAccountService::class)->save('facebook', [
            'external_account_id' => 'page-123',
            'graph_api_version' => 'v24.0',
            'app_id' => 'app-123',
            'app_secret' => 'plain-app-secret',
            'access_token' => 'plain-access-token',
            'webhook_verify_token' => 'plain-verify-token',
            'is_enabled' => true,
        ], $user->id);

        $stored = DB::table('social_network_accounts')->where('id', $account->id)->first();
        $this->assertNotSame('plain-app-secret', $stored->app_secret);
        $this->assertNotSame('plain-access-token', $stored->access_token);
        $this->assertNotSame('plain-verify-token', $stored->webhook_verify_token);

        $safe = $account->safeConfiguration();
        $this->assertTrue($safe['has_app_secret']);
        $this->assertTrue($safe['has_access_token']);
        $this->assertTrue($safe['publish_ready']);
        $this->assertArrayNotHasKey('access_token', $safe);
        $this->assertArrayNotHasKey('app_secret', $safe);
        $this->assertArrayNotHasKey('webhook_verify_token', $safe);
    }

    public function test_social_publication_is_a_canonical_scheduled_campaign(): void
    {
        $user = User::factory()->create();
        $this->account('facebook', 'page-scheduled');
        $this->account('instagram', 'ig-scheduled');
        $scheduledAt = now()->addHour()->format('Y-m-d H:i:s');

        $this->actingAs($user)->post(route('comunicacao.redes.publicacoes.store'), [
            'title' => 'Prova do fim de semana',
            'message' => 'A equipa está pronta.',
            'providers' => ['facebook', 'instagram'],
            'link_url' => 'https://club.example.test/noticias/prova',
            'media_url' => 'https://club.example.test/media/prova.jpg',
            'scheduled_at' => $scheduledAt,
            'submission_mode' => 'schedule',
        ])->assertRedirect();

        $campaign = CommunicationCampaign::query()->where('source_type', 'social_publication')->sole();
        $this->assertSame('agendada', $campaign->status);
        $this->assertNotNull($campaign->idempotency_key);
        $this->assertSame(['facebook', 'instagram'], $campaign->channels()->orderBy('channel')->pluck('channel')->all());
        $this->assertSame('social-network-accounts', $campaign->segment?->slug);
        $this->assertSame('https://club.example.test/media/prova.jpg', $campaign->channels()->where('channel', 'instagram')->value('media_url'));
    }

    public function test_immediate_social_publication_is_dispatched_to_the_existing_queue_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->account('facebook', 'page-immediate');

        $this->actingAs($user)->post(route('comunicacao.redes.publicacoes.store'), [
            'title' => 'Notícia Facebook',
            'message' => 'Publicação em fila.',
            'providers' => ['facebook'],
            'submission_mode' => 'send',
        ])->assertRedirect();

        $campaign = CommunicationCampaign::query()->where('source_type', 'social_publication')->sole();
        $this->assertSame('em_processamento', $campaign->status);
        Queue::assertPushed(\App\Jobs\ProcessCommunicationCampaignJob::class, fn ($job): bool => $job->campaignId === $campaign->id);
    }

    public function test_facebook_delivery_uses_graph_api_and_preserves_post_id(): void
    {
        Http::fake(['https://graph.facebook.com/*' => Http::response(['id' => 'page-123_post-456'], 200)]);
        $account = $this->account('facebook', 'page-123');
        [$campaign, $channel] = $this->socialCampaign('facebook', 'Mensagem Facebook', 'https://club.example.test/noticia', null);

        $delivery = app(CommunicationDeliveryService::class)->createAndExecuteDelivery($campaign, $channel);
        $recipient = $delivery->recipients()->sole();

        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame($account->id, $recipient->social_network_account_id);
        $this->assertSame('page-123_post-456', $recipient->provider_message_id);
        $this->assertSame('meta_facebook', $recipient->provider);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/page-123/feed'));
    }

    public function test_instagram_delivery_creates_container_then_publishes(): void
    {
        Http::fakeSequence()
            ->push(['id' => 'container-123'], 200)
            ->push(['id' => 'media-456'], 200);
        $this->account('instagram', 'ig-123');
        [$campaign, $channel] = $this->socialCampaign('instagram', 'Legenda Instagram', null, 'https://club.example.test/media/foto.jpg');

        $delivery = app(CommunicationDeliveryService::class)->createAndExecuteDelivery($campaign, $channel);
        $recipient = $delivery->recipients()->sole();

        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame('media-456', $recipient->provider_message_id);
        $this->assertSame(2, count(Http::recorded()));
    }

    public function test_meta_webhook_verifies_token_and_rejects_unsigned_payloads(): void
    {
        $account = $this->account('facebook', 'page-123');
        $account->update(['webhook_verify_token' => 'verify-me']);

        $this->get('/api/webhooks/meta/facebook?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=challenge-42')
            ->assertOk()
            ->assertSeeText('challenge-42');

        $payload = ['object' => 'page', 'entry' => [[
            'id' => 'page-123',
            'time' => now()->timestamp,
            'changes' => [['field' => 'feed', 'value' => ['post_id' => 'unknown-post', 'status' => 'published']]],
        ]]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/api/webhooks/meta/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame(1, SocialNetworkEvent::query()->count());
        $this->assertFalse(SocialNetworkEvent::query()->getConnection()->getSchemaBuilder()->hasColumn('social_network_events', 'payload'));

        $this->call('POST', '/api/webhooks/meta/facebook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong',
        ], $body)->assertUnauthorized();
    }

    private function account(string $provider, string $externalId): SocialNetworkAccount
    {
        return SocialNetworkAccount::query()->create([
            'provider' => $provider,
            'external_account_id' => $externalId,
            'graph_api_version' => 'v24.0',
            'app_secret' => 'app-secret',
            'access_token' => 'access-token',
            'is_enabled' => true,
        ]);
    }

    /** @return array{CommunicationCampaign,\App\Models\CommunicationCampaignChannel} */
    private function socialCampaign(string $provider, string $message, ?string $link, ?string $media): array
    {
        $segment = CommunicationSegment::query()->create([
            'name' => 'Contas sociais '.uniqid('', true),
            'type' => 'system',
            'rules_json' => ['source' => 'social_network_accounts'],
            'is_active' => true,
        ]);
        $campaign = CommunicationCampaign::query()->create([
            'codigo' => 'SOC-'.strtoupper(substr(str_replace('.', '', uniqid('', true)), -10)),
            'title' => 'Publicação social',
            'description' => $message,
            'segment_id' => $segment->id,
            'status' => 'em_processamento',
            'source_type' => 'social_publication',
            'idempotency_key' => hash('sha256', uniqid('social-', true)),
        ]);
        $channel = $campaign->channels()->create([
            'channel' => $provider,
            'message_body' => $message,
            'link_url' => $link,
            'media_url' => $media,
            'is_enabled' => true,
        ]);

        return [$campaign->load('segment'), $channel];
    }
}
