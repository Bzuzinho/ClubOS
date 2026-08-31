<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Http\Controllers\Desportivo\SportsAnalysisWorkspaceController;
use App\Http\Controllers\Desportivo\SportsCaisWorkspaceController;
use App\Http\Controllers\Desportivo\SportsCompetitionWorkspaceController;
use App\Http\Controllers\Desportivo\SportsDashboardWorkspaceController;
use App\Http\Controllers\Desportivo\SportsLiveWorkspaceController;
use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Controllers\Desportivo\SportsResultsWorkspaceController;
use App\Support\LegacySportsGuard;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SportsEndToEndCanonicalContractTest extends TestCase
{
    /** @return array<string, array{uri:string,action:string,permission:string}> */
    public static function workspaceRoutes(): array
    {
        return [
            'dashboard' => [
                'uri' => 'desportivo',
                'action' => SportsDashboardWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.dashboard,view',
            ],
            'planning' => [
                'uri' => 'desportivo/planeamento',
                'action' => SportsPlanningWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.planeamento,view',
            ],
            'cais' => [
                'uri' => 'desportivo/cais',
                'action' => SportsCaisWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.treinos.cais,view',
            ],
            'live' => [
                'uri' => 'desportivo/live',
                'action' => SportsLiveWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.treinos.cais,view',
            ],
            'competitions' => [
                'uri' => 'desportivo/competicoes',
                'action' => SportsCompetitionWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.competicoes,view',
            ],
            'results' => [
                'uri' => 'desportivo/resultados',
                'action' => SportsResultsWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.resultados,view',
            ],
            'analysis' => [
                'uri' => 'desportivo/relatorios',
                'action' => SportsAnalysisWorkspaceController::class.'@index',
                'permission' => 'permission.access:desportivo.treinos.cais,view',
            ],
        ];
    }

    /** @dataProvider workspaceRoutes */
    public function test_canonical_sports_workspaces_keep_their_route_and_access_contract(string $uri, string $action, string $permission): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === $uri && in_array('GET', $candidate->methods(), true));

        $this->assertNotNull($route, "Missing canonical sports workspace route: {$uri}");
        $this->assertSame($action, $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('module.access:desportivo', $route->gatherMiddleware());
        $this->assertContains($permission, $route->gatherMiddleware());
    }

    public function test_legacy_sports_tables_remain_forbidden_as_active_sources(): void
    {
        $forbidden = app(LegacySportsGuard::class)->forbiddenTables();

        $this->assertContains('training_sessions', $forbidden);
        $this->assertContains('presences', $forbidden);
        $this->assertContains('event_results', $forbidden);
        $this->assertContains('event_attendances', $forbidden);
    }
}
