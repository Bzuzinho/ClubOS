<?php

namespace App\Services\AccessControl;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

final class OperationalPermissionRouteGuardRegistrar
{
    private const OPERATIONAL_MODULES = [
        'logistica',
        'loja',
        'patrocinios',
        'comunicacao',
        'marketing',
    ];

    /**
     * Core administrative prefixes that historically contain routes declared
     * inside auth/verified without an explicit module guard on every route.
     *
     * This pass only restores the module boundary. Granular permission
     * expansion is handled separately so existing grants are not invalidated.
     */
    private const CORE_ADMIN_PREFIXES = [
        'financeiro' => 'financeiro',
        'configuracoes' => 'configuracoes',
    ];

    public function register(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->ensureCoreAdministrativeModuleGuard($route);

            $moduleKey = $this->guardedOperationalModule($route);
            if ($moduleKey === null) {
                continue;
            }

            $permissionNode = $this->permissionNode($moduleKey, $route->uri());
            $capability = $this->capability($route);
            $middleware = "permission.access:{$permissionNode},{$capability}";

            if (! in_array($middleware, $route->gatherMiddleware(), true)) {
                $route->middleware($middleware);
                $route->computedMiddleware = null;
            }
        }
    }

    private function ensureCoreAdministrativeModuleGuard(RoutingRoute $route): void
    {
        $moduleKey = $this->coreAdministrativeModule($route->uri());
        if ($moduleKey === null) {
            return;
        }

        $middleware = "module.access:{$moduleKey}";
        if (in_array($middleware, $route->gatherMiddleware(), true)) {
            return;
        }

        $route->middleware($middleware);
        $route->computedMiddleware = null;
    }

    private function coreAdministrativeModule(string $uri): ?string
    {
        $uri = ltrim($uri, '/');

        foreach (self::CORE_ADMIN_PREFIXES as $prefix => $moduleKey) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function guardedOperationalModule(RoutingRoute $route): ?string
    {
        $middleware = $route->gatherMiddleware();

        foreach (self::OPERATIONAL_MODULES as $moduleKey) {
            if (in_array("module.access:{$moduleKey}", $middleware, true)) {
                return $moduleKey;
            }
        }

        return null;
    }

    private function permissionNode(string $moduleKey, string $uri): string
    {
        $uri = ltrim($uri, '/');

        return match ($moduleKey) {
            'logistica' => match (true) {
                str_contains($uri, 'logistica/requisicoes') => 'logistica.requisicoes',
                str_contains($uri, 'logistica/stock') => 'logistica.stock',
                str_contains($uri, 'logistica/emprestimos') => 'logistica.emprestimos',
                str_contains($uri, 'logistica/fornecedores') => 'logistica.fornecedores',
                default => 'logistica.dashboard',
            },
            'loja' => match (true) {
                str_contains($uri, 'admin/loja/produtos') => 'loja.produtos',
                str_contains($uri, 'admin/loja/encomendas') => 'loja.encomendas',
                str_contains($uri, 'admin/loja/hero') => 'loja.hero',
                default => 'loja.dashboard',
            },
            'patrocinios' => str_contains($uri, 'patrocinios') && str_contains($uri, 'integracoes')
                ? 'patrocinios.integracoes'
                : 'patrocinios.dashboard',
            'comunicacao' => match (true) {
                str_contains($uri, 'comunicacao/campaigns') => 'comunicacao.campanhas',
                str_contains($uri, 'comunicacao/deliveries') => 'comunicacao.entregas',
                str_contains($uri, 'comunicacao/templates') => 'comunicacao.modelos',
                str_contains($uri, 'comunicacao/segments') => 'comunicacao.segmentos',
                str_contains($uri, 'comunicacao/alerts') => 'comunicacao.alertas',
                default => 'comunicacao.dashboard',
            },
            'marketing' => 'marketing.campanhas',
        };
    }

    private function capability(RoutingRoute $route): string
    {
        $methods = $route->methods();
        $routeName = (string) $route->getName();

        if (
            in_array('DELETE', $methods, true)
            || str_ends_with($routeName, '.destroy')
        ) {
            return 'delete';
        }

        $mutatingMethods = array_diff($methods, ['GET', 'HEAD', 'OPTIONS']);
        if ($mutatingMethods !== []) {
            return 'edit';
        }

        if (str_ends_with($routeName, '.create') || str_ends_with($routeName, '.edit')) {
            return 'edit';
        }

        return 'view';
    }
}
