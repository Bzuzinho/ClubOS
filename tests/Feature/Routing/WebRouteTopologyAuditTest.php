<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Services\Routing\RouteTopologyAuditService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class WebRouteTopologyAuditTest extends TestCase
{
    public function test_web_route_topology_matches_the_reviewed_h25a_contract(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();

        $this->assertSame('web-route-topology-v1', $report['version']);
        $this->assertTrue($report['read_only']);
        $this->assertTrue($report['summary']['contract_matches_baseline']);
        $this->assertSame(1, $report['summary']['fallback_route_count']);
        $this->assertFalse($report['summary']['fallback_registered_last']);
        $this->assertSame('public.custom-page', $report['contract']['fallback_name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['contract']['hash']);
        $this->assertSame(22, $report['summary']['modular_route_file_count']);
        $this->assertSame(22, $report['summary']['loaded_modular_route_file_count']);
        $this->assertSame(23, $report['summary']['legacy_redirect_count']);
        $this->assertSame(1, $report['summary']['source_literal_duplicate_candidate_count']);
        $this->assertSame(1, $report['summary']['source_literal_duplicate_reviewed_count']);
        $this->assertSame(0, $report['summary']['source_literal_duplicate_unclassified_count']);
        $this->assertSame(2, $report['summary']['retired_shadowed_alias_count']);
        $this->assertSame(0, $report['summary']['retired_shadowed_alias_reference_count']);
        $this->assertArrayNotHasKey('store.front.index', $report['contract']['named_routes']);
        $this->assertArrayNotHasKey('configuracoes.club.update', $report['contract']['named_routes']);
        $this->assertArrayHasKey('loja.index', $report['contract']['named_routes']);
        $this->assertArrayHasKey('configuracoes.clube.update', $report['contract']['named_routes']);
        $this->assertSame('loja', $report['contract']['named_routes']['loja.index']['uri']);
        $this->assertSame('configuracoes/clube', $report['contract']['named_routes']['configuracoes.clube.update']['uri']);
        $classifications = collect($report['duplicates']['reviewed_source_classifications'])
            ->keyBy(fn (array $candidate): string => $candidate['method'].' '.$candidate['uri']);
        $this->assertSame('prefix_scoped_distinct_routes', $classifications['GET /']['classification']);
        $this->assertCount(1, $classifications);
        $retiredAliases = collect($report['duplicates']['retired_shadowed_aliases'])->keyBy('retired_name');
        $this->assertSame('loja.index', $retiredAliases['store.front.index']['effective_name']);
        $this->assertSame('configuracoes.clube.update', $retiredAliases['configuracoes.club.update']['effective_name']);
        $this->assertSame([], $report['duplicates']['retired_shadowed_alias_references']);
        $this->assertTrue($report['interpretation']['diagnostic_only']);
        $this->assertTrue($report['interpretation']['no_routes_changed']);
        $this->assertTrue($report['interpretation']['all_source_literal_duplicate_candidates_reviewed']);
        $this->assertTrue($report['interpretation']['retired_shadowed_aliases_have_zero_first_party_references']);
    }

    public function test_settings_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $settingsRoutes = File::get(base_path('routes/web_settings.php'));

        $this->assertTrue($routeFiles['routes/web_settings.php']['loaded']);
        $this->assertSame(68, $routeFiles['routes/web_settings.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_settings.php';", $webRoutes);
        $this->assertStringNotContainsString('ConfiguracoesController::class', $webRoutes);
        $this->assertStringNotContainsString('ConfiguracoesDesportivoController::class', $webRoutes);
        $this->assertStringContainsString("Route::middleware('module.access:configuracoes')", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes');", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes.clube.update');", $settingsRoutes);
        $this->assertStringContainsString("->name('configuracoes.desportivo.index');", $settingsRoutes);
    }

    public function test_administrative_website_routes_are_loaded_from_the_dedicated_module(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $webRoutes = File::get(base_path('routes/web.php'));
        $websiteRoutes = File::get(base_path('routes/web_website.php'));

        $this->assertTrue($routeFiles['routes/web_website.php']['loaded']);
        $this->assertSame(17, $routeFiles['routes/web_website.php']['route_call_count']);
        $this->assertStringContainsString("require __DIR__.'/web_website.php';", $webRoutes);
        $this->assertStringNotContainsString('WebsiteController::class', $webRoutes);
        $this->assertStringNotContainsString('WebsitePageController::class', $webRoutes);
        $this->assertStringNotContainsString('WebsiteMediaController::class', $webRoutes);
        $this->assertStringContainsString('PublicSiteController::class', $webRoutes);
        $this->assertStringContainsString('PublicFormSubmissionController::class', $webRoutes);
        $this->assertStringNotContainsString('PublicSiteController::class', $websiteRoutes);
        $this->assertStringNotContainsString('PublicFormSubmissionController::class', $websiteRoutes);
        $this->assertStringContainsString("Route::prefix('website')", $websiteRoutes);
        $this->assertStringContainsString("->middleware('module.access:website')", $websiteRoutes);
        $this->assertStringContainsString("->name('website.index');", $websiteRoutes);
        $this->assertStringContainsString("->name('website.pages.update');", $websiteRoutes);
        $this->assertStringContainsString("->name('website.legacy-redirect');", $websiteRoutes);
    }

    public function test_compatibility_redirects_are_modular_and_have_no_first_party_consumers(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $redirects = collect($report['legacy']['redirects']);
        $routeFiles = collect($report['modularization']['route_files'])->keyBy('path');
        $appLayout = File::get(resource_path('js/Layouts/Spark/AppLayout.tsx'));

        $this->assertSame(0, $report['summary']['legacy_redirect_consumer_count']);
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/marketing'
                && $redirect['target'] === '/campanhas-marketing'
                && $redirect['declared_in'] === 'routes/web_compatibility.php',
        ));
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/settings'
                && $redirect['target'] === '/configuracoes'
                && $redirect['declared_in'] === 'routes/web_compatibility.php',
        ));
        $this->assertTrue($routeFiles['routes/web_compatibility.php']['loaded']);
        $this->assertTrue($routeFiles['routes/web_portal.php']['loaded']);
        $this->assertTrue($redirects->contains(
            fn (array $redirect): bool => $redirect['source'] === '/portal/loja'
                && $redirect['target'] === '/loja'
                && $redirect['declared_in'] === 'routes/web_portal.php',
        ));
        $this->assertStringContainsString("href: '/campanhas-marketing'", $appLayout);
        $this->assertStringContainsString('href="/configuracoes"', $appLayout);
        $this->assertStringNotContainsString("href: '/marketing'", $appLayout);
        $this->assertStringNotContainsString('href="/settings"', $appLayout);
    }

    public function test_artisan_command_writes_a_machine_readable_report_and_enforces_the_contract(): void
    {
        $path = storage_path('framework/testing/web-route-topology.json');
        File::delete($path);

        $this->artisan('routes:audit-web-topology', [
            '--report-path' => $path,
            '--fail-on-contract-drift' => true,
        ])->assertSuccessful();

        $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['summary']['contract_matches_baseline']);
        $this->assertNotEmpty($payload['contract']['signatures']);
    }
}
