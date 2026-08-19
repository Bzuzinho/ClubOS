<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MarketingCampaignContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_status_scopes_use_canonical_values(): void
    {
        MarketingCampaign::create([
            'name' => 'Ativa',
            'type' => 'email',
            'start_date' => '2026-08-01',
            'status' => 'active',
        ]);

        MarketingCampaign::create([
            'name' => 'Concluída',
            'type' => 'social_media',
            'start_date' => '2026-07-01',
            'status' => 'completed',
        ]);

        MarketingCampaign::create([
            'name' => 'Planeada',
            'type' => 'event',
            'start_date' => '2026-09-01',
            'status' => 'planned',
        ]);

        $this->assertSame(1, MarketingCampaign::active()->count());
        $this->assertSame(1, MarketingCampaign::completed()->count());
        $this->assertSame(1, MarketingCampaign::ofStatus('planned')->count());
    }

    public function test_marketing_resource_uses_the_canonical_route_namespace(): void
    {
        $routes = Route::getRoutes();

        $index = $routes->getByName('campanhas-marketing.index');
        $store = $routes->getByName('campanhas-marketing.store');
        $update = $routes->getByName('campanhas-marketing.update');
        $destroy = $routes->getByName('campanhas-marketing.destroy');

        $this->assertNotNull($index);
        $this->assertNotNull($store);
        $this->assertNotNull($update);
        $this->assertNotNull($destroy);
        $this->assertSame('campanhas-marketing', $store->uri());
        $this->assertContains('POST', $store->methods());
        $this->assertContains('PUT', $update->methods());
        $this->assertContains('DELETE', $destroy->methods());
    }

    public function test_frontend_and_controller_share_the_canonical_marketing_contract(): void
    {
        $page = file_get_contents(resource_path('js/Pages/CampanhasMarketing/Index.tsx'));
        $controller = file_get_contents(app_path('Http/Controllers/CampanhasMarketingController.php'));

        $this->assertIsString($page);
        $this->assertIsString($controller);

        $this->assertStringContainsString("router.post('/campanhas-marketing'", $page);
        $this->assertStringContainsString('`/campanhas-marketing/${editingCampaign.id}`', $page);
        $this->assertStringContainsString("type: 'email'", $page);
        $this->assertStringContainsString("status: 'planned'", $page);
        $this->assertStringContainsString('social_media', $page);
        $this->assertStringContainsString('completed', $page);
        $this->assertStringNotContainsString("router.post('/marketing'", $page);
        $this->assertStringNotContainsString("router.put(`/marketing/", $page);
        $this->assertStringNotContainsString("router.delete(`/marketing/", $page);

        $this->assertStringContainsString("route('campanhas-marketing.index')", $controller);
        $this->assertStringNotContainsString("route('marketing.index')", $controller);
    }
}
