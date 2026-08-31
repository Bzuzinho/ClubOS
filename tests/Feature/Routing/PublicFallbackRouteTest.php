<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicFallbackRouteTest extends TestCase
{
    public function test_public_fallback_is_loaded_from_the_dedicated_file_after_auth_and_compatibility_routes(): void
    {
        $webRoutes = File::get(base_path('routes/web.php'));
        $fallbackRoutes = File::get(base_path('routes/web_public_fallback.php'));
        $route = Route::getRoutes()->getByName('public.custom-page');

        $this->assertStringContainsString("require __DIR__.'/web_public_fallback.php';", $webRoutes);
        $this->assertStringNotContainsString('use App\\Http\\Controllers\\PublicSiteController;', $webRoutes);
        $this->assertStringContainsString("Route::fallback([PublicSiteController::class, 'custom'])", $fallbackRoutes);
        $this->assertStringContainsString("->name('public.custom-page');", $fallbackRoutes);

        $compatPosition = strpos($webRoutes, "require __DIR__.'/web_compatibility.php';");
        $authPosition = strpos($webRoutes, "require __DIR__.'/auth.php';");
        $fallbackPosition = strpos($webRoutes, "require __DIR__.'/web_public_fallback.php';");

        $this->assertIsInt($compatPosition);
        $this->assertIsInt($authPosition);
        $this->assertIsInt($fallbackPosition);
        $this->assertLessThan($authPosition, $compatPosition);
        $this->assertLessThan($fallbackPosition, $authPosition);

        $this->assertNotNull($route);
        $this->assertSame('{fallbackPlaceholder}', $route->uri());
        $this->assertSame(PublicSiteController::class.'@custom', $route->getActionName());
    }
}
