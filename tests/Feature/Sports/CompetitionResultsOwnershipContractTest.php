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

        $index = $routes->first(fn ($route) => $route->uri() === 'api/desportivo/competition-results');
        $item = $routes->first(fn ($route) => $route->uri() === 'api/desportivo/competition-results/{competition_result}');

        $this->assertNotNull($index);
        $this->assertNotNull($item);
        $this->assertContains('GET', $index->methods());
        $this->assertContains('POST', $index->methods());
        $this->assertContains('GET', $item->methods());
        $this->assertContains('PUT', $item->methods());
        $this->assertContains('DELETE', $item->methods());
        $this->assertContains('module.access:desportivo', $index->gatherMiddleware());
        $this->assertContains('permission.access:desportivo.resultados,view', $index->gatherMiddleware());
    }

    public function test_legacy_results_hook_is_absent_from_runtime_exports(): void
    {
        $index = file_get_contents(resource_path('js/hooks/index.ts'));

        $this->assertIsString($index);
        $this->assertStringNotContainsString("from './useResults'", $index);
        $this->assertFileDoesNotExist(resource_path('js/hooks/useResults.ts'));
    }
}
