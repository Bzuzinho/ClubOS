<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Contracts\Communication\CommunicationChannelAdapter;
use App\Services\Communication\Adapters\EmailChannelAdapter;
use App\Services\Communication\Adapters\PushChannelAdapter;
use App\Services\Communication\Adapters\SmsChannelAdapter;
use App\Services\Communication\Adapters\FacebookChannelAdapter;
use App\Services\Communication\Adapters\InstagramChannelAdapter;
use Tests\TestCase;

final class CommunicationProviderAdapterArchitectureTest extends TestCase
{
    public function test_external_channels_have_explicit_adapters(): void
    {
        foreach ([EmailChannelAdapter::class, SmsChannelAdapter::class, PushChannelAdapter::class, FacebookChannelAdapter::class, InstagramChannelAdapter::class] as $adapter) {
            $this->assertContains(CommunicationChannelAdapter::class, class_implements($adapter));
        }

        $deliverySource = (string) file_get_contents(app_path('Services/Communication/CommunicationDeliveryService.php'));
        $this->assertStringNotContainsString('Mail::', $deliverySource);
        $this->assertStringNotContainsString('Http::', $deliverySource);
        $this->assertStringContainsString('adapterRegistry->resolve($channel)', $deliverySource);
    }

    public function test_provider_webhook_route_is_signed_throttled_and_outside_auth_group(): void
    {
        $routeSource = (string) file_get_contents(base_path('routes/api.php'));
        $routePosition = strpos($routeSource, "Route::post('/webhooks/communication/{provider}'");
        $authPosition = strpos($routeSource, "Route::middleware(['auth'])->group");

        $this->assertNotFalse($routePosition);
        $this->assertNotFalse($authPosition);
        $this->assertLessThan($authPosition, $routePosition);
        $this->assertStringContainsString("->middleware(['throttle:120,1', 'communication.webhook.signature'])", $routeSource);
    }

    public function test_social_publication_is_kept_out_of_the_website_boundary(): void
    {
        $socialController = (string) file_get_contents(app_path('Http/Controllers/Communication/SocialPublicationController.php'));
        $websiteRoutes = (string) file_get_contents(base_path('routes/web_website.php'));

        $this->assertStringContainsString('CommunicationCampaignService', $socialController);
        $this->assertStringContainsString("'source_type' => 'social_publication'", $socialController);
        $this->assertStringNotContainsString('WebsiteController', $socialController);
        $this->assertStringNotContainsString('webhooks/meta', $websiteRoutes);
    }
}
