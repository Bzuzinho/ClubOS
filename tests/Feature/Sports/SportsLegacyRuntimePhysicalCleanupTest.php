<?php

namespace Tests\Feature\Sports;

use Tests\TestCase;

final class SportsLegacyRuntimePhysicalCleanupTest extends TestCase
{
    public function test_legacy_page_runtime_files_are_physically_absent(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Services/Desportivo/DesportivoPagePayloadBuilder.php'));
        $this->assertFileDoesNotExist(base_path('resources/js/Pages/Desportivo/Index.tsx'));
    }

    public function test_compatibility_controller_contains_no_legacy_sports_runtime_dependencies(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/DesportivoController.php'));

        $this->assertStringNotContainsString('DesportivoPagePayloadBuilder', $source);
        $this->assertStringNotContainsString('App\\Models\\Training;', $source);
        $this->assertStringNotContainsString('App\\Models\\Competition;', $source);
        $this->assertStringNotContainsString('TrainingAthlete', $source);
        $this->assertStringNotContainsString('TrainingMetric', $source);
        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringContainsString('SportsDashboardWorkspaceController', $source);
        $this->assertStringContainsString('SportsCaisWorkspaceController', $source);
    }

    public function test_performance_runtime_no_longer_warms_or_benchmarks_legacy_controller(): void
    {
        $warmup = file_get_contents(base_path('app/Services/Performance/AuthenticatedModuleWarmupService.php'));
        $benchmark = file_get_contents(base_path('app/Console/Commands/BenchmarkModulesPerformance.php'));

        $this->assertStringNotContainsString('App\\Http\\Controllers\\DesportivoController', $warmup);
        $this->assertStringNotContainsString('app(DesportivoController::class)', $warmup);
        $this->assertStringContainsString('SportsDashboardWorkspaceController', $warmup);

        $this->assertStringNotContainsString('App\\Http\\Controllers\\DesportivoController', $benchmark);
        $this->assertStringNotContainsString('app(DesportivoController::class)', $benchmark);
        $this->assertStringContainsString('SportsDashboardWorkspaceController', $benchmark);
    }

    public function test_cais_metric_contract_is_owned_by_canonical_controller(): void
    {
        $routes = file_get_contents(base_path('routes/desportivo_cais.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Desportivo/SportsCaisWorkspaceController.php'));

        $this->assertStringContainsString("Route::get('/metricas', [SportsCaisWorkspaceController::class, 'metrics'])", $routes);
        $this->assertStringContainsString("Route::post('/metricas', [SportsCaisWorkspaceController::class, 'storeMetrics'])", $routes);
        $this->assertStringContainsString('public function metrics(Request $request): JsonResponse', $controller);
        $this->assertStringContainsString('public function storeMetrics(StoreTrainingMetricRequest $request): JsonResponse', $controller);
    }
}
