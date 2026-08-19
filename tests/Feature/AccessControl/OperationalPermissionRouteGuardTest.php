<?php

namespace Tests\Feature\AccessControl;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationalPermissionRouteGuardTest extends TestCase
{
    public function test_operational_web_routes_receive_the_expected_granular_capability(): void
    {
        $expectations = [
            'logistica.index' => 'permission.access:logistica.dashboard,view',
            'logistica.requisicoes.store' => 'permission.access:logistica.requisicoes,edit',
            'logistica.requisicoes.destroy' => 'permission.access:logistica.requisicoes,delete',
            'logistica.stock.movimentos.store' => 'permission.access:logistica.stock,edit',
            'logistica.emprestimos.destroy' => 'permission.access:logistica.emprestimos,delete',
            'logistica.fornecedores.compras.store' => 'permission.access:logistica.fornecedores,edit',
            'admin.loja.produtos.index' => 'permission.access:loja.produtos,view',
            'admin.loja.produtos.create' => 'permission.access:loja.produtos,edit',
            'admin.loja.hero.edit' => 'permission.access:loja.hero,edit',
            'patrocinios.integrations.index' => 'permission.access:patrocinios.integracoes,view',
            'patrocinios.integrations.retry' => 'permission.access:patrocinios.integracoes,edit',
            'patrocinios.destroy' => 'permission.access:patrocinios.dashboard,delete',
            'comunicacao.index' => 'permission.access:comunicacao.dashboard,view',
            'comunicacao.campaigns.store' => 'permission.access:comunicacao.campanhas,edit',
            'comunicacao.templates.destroy' => 'permission.access:comunicacao.modelos,delete',
            'comunicacao.alerts.markRead' => 'permission.access:comunicacao.alertas,edit',
            'comunicacao.alerts.destroy' => 'permission.access:comunicacao.alertas,delete',
            'campanhas-marketing.index' => 'permission.access:marketing.campanhas,view',
            'campanhas-marketing.create' => 'permission.access:marketing.campanhas,edit',
            'campanhas-marketing.store' => 'permission.access:marketing.campanhas,edit',
            'campanhas-marketing.destroy' => 'permission.access:marketing.campanhas,delete',
        ];

        foreach ($expectations as $routeName => $expectedMiddleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
            $this->assertContains(
                $expectedMiddleware,
                $route->gatherMiddleware(),
                "Route [{$routeName}] is missing [{$expectedMiddleware}].",
            );
        }
    }

    public function test_admin_store_api_mutations_receive_granular_guards(): void
    {
        $expectations = [
            ['POST', 'api/admin/loja/produtos', 'permission.access:loja.produtos,edit'],
            ['DELETE', 'api/admin/loja/produtos/{produto}', 'permission.access:loja.produtos,delete'],
            ['PATCH', 'api/admin/loja/encomendas/{encomenda}/estado', 'permission.access:loja.encomendas,edit'],
            ['POST', 'api/admin/loja/hero', 'permission.access:loja.hero,edit'],
            ['DELETE', 'api/admin/loja/hero/{item}', 'permission.access:loja.hero,delete'],
        ];

        foreach ($expectations as [$method, $uri, $expectedMiddleware]) {
            $route = $this->routeByMethodAndUri($method, $uri);

            $this->assertNotNull($route, "Route [{$method} {$uri}] is not registered.");
            $this->assertContains(
                'module.access:loja',
                $route->gatherMiddleware(),
                "Route [{$method} {$uri}] lost its module guard.",
            );
            $this->assertContains(
                $expectedMiddleware,
                $route->gatherMiddleware(),
                "Route [{$method} {$uri}] is missing [{$expectedMiddleware}].",
            );
        }
    }

    public function test_member_store_api_routes_are_not_converted_into_admin_module_routes(): void
    {
        $route = $this->routeByMethodAndUri('POST', 'api/loja/carrinho/itens');

        $this->assertNotNull($route);
        $this->assertNotContains('module.access:loja', $route->gatherMiddleware());
        $this->assertFalse(
            collect($route->gatherMiddleware())->contains(
                static fn (string $middleware): bool => str_starts_with($middleware, 'permission.access:loja.')
            )
        );
    }

    private function routeByMethodAndUri(string $method, string $uri): ?RoutingRoute
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
