<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PwaAssetRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_route_is_public_and_returns_manifest_content_type(): void
    {
        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = json_decode(File::get(resource_path('pwa/site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/app', $manifest['id']);
        $this->assertSame('/app', $manifest['start_url']);
    }

    public function test_favicon_route_is_public(): void
    {
        $this->get('/favicon.ico')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/x-icon');
    }

    public function test_known_pwa_icon_route_is_public(): void
    {
        $this->get('/icons/apple-touch-icon.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }
}
