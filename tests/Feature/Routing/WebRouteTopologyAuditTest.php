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
        $this->assertSame(19, $report['summary']['modular_route_file_count']);
        $this->assertSame(19, $report['summary']['loaded_modular_route_file_count']);
        $this->assertSame(23, $report['summary']['legacy_redirect_count']);
        $this->assertSame(3, $report['summary']['source_literal_duplicate_reviewed_count']);
        $this->assertSame(0, $report['summary']['source_literal_duplicate_unclassified_count']);
        $this->assertArrayNotHasKey('store.front.index', $report['contract']['named_routes']);
        $this->assertArrayHasKey('loja.index', $report['contract']['named_routes']);
        $this->assertSame('loja', $report['contract']['named_routes']['loja.index']['uri']);
        $lojaCandidate = collect($report['duplicates']['source_literal_candidates'])->first(
            fn (array $candidate): bool => $candidate['method'] === 'GET' && $candidate['uri'] === '/loja',
        );
        $this->assertNotNull($lojaCandidate);
        $this->assertSame(
            ['store.front.index', 'loja.index'],
            collect($lojaCandidate['occurrences'])->pluck('name')->all(),
        );
        $this->assertTrue(collect($report['duplicates']['source_literal_candidates'])->contains(
            fn (array $candidate): bool => $candidate['method'] === 'PUT' && $candidate['uri'] === '/configuracoes/clube',
        ));
        $classifications = collect($report['duplicates']['reviewed_source_classifications'])
            ->keyBy(fn (array $candidate): string => $candidate['method'].' '.$candidate['uri']);
        $this->assertSame('prefix_scoped_distinct_routes', $classifications['GET /']['classification']);
        $this->assertSame('loja.index', $classifications['GET /loja']['effective_name']);
        $this->assertSame('store.front.index', $classifications['GET /loja']['shadowed_name']);
        $this->assertSame('configuracoes.clube.update', $classifications['PUT /configuracoes/clube']['effective_name']);
        $this->assertSame('configuracoes.club.update', $classifications['PUT /configuracoes/clube']['shadowed_name']);
        $this->assertTrue($report['interpretation']['diagnostic_only']);
        $this->assertTrue($report['interpretation']['no_routes_changed']);
        $this->assertTrue($report['interpretation']['all_source_literal_duplicate_candidates_reviewed']);
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
