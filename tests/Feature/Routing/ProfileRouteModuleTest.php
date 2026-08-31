<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProfileRouteModuleTest extends TestCase
{
    public function test_profile_routes_are_isolated_in_the_auth_route_module_without_contract_drift(): void
    {
        $webRoutes = File::get(base_path('routes/web.php'));
        $authRoutes = File::get(base_path('routes/auth.php'));

        $this->assertStringNotContainsString('ProfileController::class', $webRoutes);
        $this->assertStringContainsString("require __DIR__.'/auth.php';", $webRoutes);
        $this->assertStringContainsString('ProfileController::class', $authRoutes);
        $this->assertStringContainsString("Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');", $authRoutes);
        $this->assertStringContainsString("Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');", $authRoutes);
        $this->assertStringContainsString("Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');", $authRoutes);

        $expected = [
            'profile.edit' => ['GET', 'edit'],
            'profile.update' => ['PATCH', 'update'],
            'profile.destroy' => ['DELETE', 'destroy'],
        ];

        foreach ($expected as $name => [$method, $action]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertSame('profile', $route->uri());
            $this->assertContains($method, $route->methods());
            $this->assertSame(ProfileController::class.'@'.$action, $route->getActionName());
            $this->assertContains('auth', $route->gatherMiddleware());
        }
    }
}
