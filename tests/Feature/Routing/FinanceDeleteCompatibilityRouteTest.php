<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Http\Controllers\FinanceiroController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class FinanceDeleteCompatibilityRouteTest extends TestCase
{
    public function test_finance_delete_compatibility_route_preserves_runtime_contract(): void
    {
        $webRoutes = File::get(base_path('routes/web.php'));
        $compatRoutes = File::get(base_path('routes/compat/web_finance_delete.php'));
        $route = Route::getRoutes()->getByName('financeiro.destroy.post');

        $this->assertStringContainsString("require __DIR__.'/compat/web_finance_delete.php';", $webRoutes);
        $this->assertStringNotContainsString("Route::post('financeiro/{financeiro}/apagar'", $webRoutes);
        $this->assertStringContainsString("Route::post('financeiro/{financeiro}/apagar', [FinanceiroController::class, 'destroy'])", $compatRoutes);
        $this->assertStringContainsString("->middleware(['module.access:financeiro', 'permission.access:financeiro.dashboard,delete'])", $compatRoutes);
        $this->assertStringContainsString("->name('financeiro.destroy.post');", $compatRoutes);

        $this->assertNotNull($route);
        $this->assertSame('financeiro/{financeiro}/apagar', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(FinanceiroController::class.'@destroy', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('module.access:financeiro', $route->gatherMiddleware());
        $this->assertContains('permission.access:financeiro.dashboard,delete', $route->gatherMiddleware());
    }
}
