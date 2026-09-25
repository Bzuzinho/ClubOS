<?php

namespace Tests\Feature\Eventos;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EventLifecycleOwnershipContractTest extends TestCase
{
    public function test_generic_events_api_is_not_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        $this->assertFalse($uris->contains('api/events'));
        $this->assertFalse($uris->contains('api/events/{event}'));
        $this->assertFalse($uris->contains('api/events/{id}'));
    }

    public function test_canonical_event_lifecycle_routes_remain_registered(): void
    {
        $routes = collect(Route::getRoutes());

        $collectionRoutes = $routes->filter(fn ($route) => $route->uri() === 'eventos');
        $itemRoutes = $routes->filter(fn ($route) => $route->uri() === 'eventos/{evento}');

        $this->assertNotEmpty($collectionRoutes);
        $this->assertNotEmpty($itemRoutes);

        $collectionMethods = $collectionRoutes->flatMap(fn ($route) => $route->methods())->unique()->values()->all();
        $itemMethods = $itemRoutes->flatMap(fn ($route) => $route->methods())->unique()->values()->all();

        $this->assertContains('GET', $collectionMethods);
        $this->assertContains('POST', $collectionMethods);
        $this->assertContains('PUT', $itemMethods);
        $this->assertContains('DELETE', $itemMethods);

        $indexRoute = $collectionRoutes->first(fn ($route) => in_array('GET', $route->methods(), true));
        $storeRoute = $collectionRoutes->first(fn ($route) => in_array('POST', $route->methods(), true));
        $updateRoute = $itemRoutes->first(fn ($route) => in_array('PUT', $route->methods(), true));
        $destroyRoute = $itemRoutes->first(fn ($route) => in_array('DELETE', $route->methods(), true));

        $this->assertNotNull($indexRoute);
        $this->assertNotNull($storeRoute);
        $this->assertNotNull($updateRoute);
        $this->assertNotNull($destroyRoute);
        $this->assertContains('module.access:eventos', $indexRoute->gatherMiddleware());
        $this->assertContains('permission.access:eventos.calendario,view', $indexRoute->gatherMiddleware());
        $this->assertContains('permission.access:eventos.calendario,edit', $storeRoute->gatherMiddleware());
        $this->assertContains('permission.access:eventos.calendario,edit', $updateRoute->gatherMiddleware());
        $this->assertContains('permission.access:eventos.calendario,delete', $destroyRoute->gatherMiddleware());
    }

    public function test_legacy_events_hook_is_absent_from_runtime_exports(): void
    {
        $index = file_get_contents(resource_path('js/hooks/index.ts'));

        $this->assertIsString($index);
        $this->assertStringNotContainsString("from './useEvents'", $index);
        $this->assertFileDoesNotExist(resource_path('js/hooks/useEvents.ts'));
    }
}
