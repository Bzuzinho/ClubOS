<?php

namespace App\Services\Desportivo;

use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class SportsNavigation
{
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User || ! $request->routeIs('desportivo.*')
            || $request->routeIs('desportivo.presencas', 'desportivo.cais*', 'desportivo.live*', 'desportivo.treinos.cais*', 'desportivo.treinos.live*')) {
            return [];
        }

        $access = app(UserTypeAccessControlService::class);
        if (! $access->canAccessModule($user, 'desportivo')) {
            return [];
        }

        $items = [
            ['Visão geral', 'desportivo.index', ['desportivo.index', 'desportivo.dashboard']],
            ['Organização', 'desportivo.estrutura.index', ['desportivo.estrutura.*', 'desportivo.configuracao.*']],
            ['Atletas', 'desportivo.atletas.index', ['desportivo.atletas.*']],
            ['Planeamento', 'desportivo.planeamento', ['desportivo.planeamento*']],
            ['Treinos', 'desportivo.treinos', ['desportivo.treinos*', 'desportivo.biblioteca*']],
            ['Competições', 'desportivo.competicoes', ['desportivo.competicoes*', 'desportivo.convocatorias.*', 'desportivo.resultados*']],
            ['Análise', 'desportivo.analise.index', ['desportivo.analise.*', 'desportivo.registos.*', 'desportivo.relatorios']],
        ];

        $result = [];
        foreach ($items as [$label, $name, $patterns]) {
            $destination = Route::getRoutes()->getByName($name);
            if (! $destination) {
                continue;
            }
            $allowed = true;
            foreach ($destination->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission.access:')) {
                    continue;
                }
                [$permission, $capability] = array_pad(explode(',', substr($middleware, strlen('permission.access:'))), 2, 'view');
                if (! $access->canAccessPermission($user, $permission, $capability)) {
                    $allowed = false;
                    break;
                }
            }
            if ($allowed) {
                $result[] = ['label' => $label, 'href' => route($name), 'active' => $request->routeIs(...$patterns)];
            }
        }

        return $result;
    }
}
