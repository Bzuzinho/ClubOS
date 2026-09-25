<?php

namespace Tests\Feature\Sports;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompetitionResultsOwnershipContractTest extends TestCase
{
    public function test_generic_results_api_is_not_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        $this->assertFalse($uris->contains('api/results'));
        $this->assertFalse($uris->contains('api/results/{result}'));
        $this->assertFalse($uris->contains('api/results/{id}'));
    }

    public function test_canonical_competition_results_api_remains_registered(): void
    {
        $routes = collect(Route::getRoutes());

        $indexRoutes = $routes->filter(
            fn ($route) => $route->uri() === 'api/desportivo/competition-results'
        );
        $itemRoutes = $routes->filter(
            fn ($route) => $route->uri() === 'api/desportivo/competition-results/{competition_result}'
        );

        $this->assertNotEmpty($indexRoutes);
        $this->assertNotEmpty($itemRoutes);

        $indexMethods = $indexRoutes->flatMap(fn ($route) => $route->methods())->unique()->values()->all();
        $itemMethods = $itemRoutes->flatMap(fn ($route) => $route->methods())->unique()->values()->all();

        $this->assertContains('GET', $indexMethods);
        $this->assertContains('POST', $indexMethods);
        $this->assertContains('GET', $itemMethods);
        $this->assertContains('PUT', $itemMethods);
        $this->assertContains('DELETE', $itemMethods);

        $indexRoute = $indexRoutes->first(
            fn ($route) => in_array('GET', $route->methods(), true)
        );

        $this->assertNotNull($indexRoute);
        $this->assertContains('module.access:desportivo', $indexRoute->gatherMiddleware());
        $this->assertContains('permission.access:desportivo.resultados,view', $indexRoute->gatherMiddleware());
    }

    public function test_legacy_results_hook_is_absent_from_runtime_exports(): void
    {
        $index = file_get_contents(resource_path('js/hooks/index.ts'));

        $this->assertIsString($index);
        $this->assertStringNotContainsString("from './useResults'", $index);
        $this->assertFileDoesNotExist(resource_path('js/hooks/useResults.ts'));
    }
}
