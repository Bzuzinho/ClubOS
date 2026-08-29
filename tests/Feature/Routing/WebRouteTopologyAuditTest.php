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
        $this->assertSame(18, $report['summary']['modular_route_file_count']);
        $this->assertSame(18, $report['summary']['loaded_modular_route_file_count']);
        $this->assertSame(23, $report['summary']['legacy_redirect_count']);
        $this->assertArrayHasKey('store.front.index', $report['contract']['named_routes']);
        $this->assertArrayHasKey('loja.index', $report['contract']['named_routes']);
        $this->assertSame('loja', $report['contract']['named_routes']['store.front.index']['uri']);
        $this->assertSame('loja', $report['contract']['named_routes']['loja.index']['uri']);
        $this->assertTrue(collect($report['duplicates']['source_literal_candidates'])->contains(
            fn (array $candidate): bool => $candidate['method'] === 'GET' && $candidate['uri'] === '/loja',
        ));
        $this->assertTrue(collect($report['duplicates']['source_literal_candidates'])->contains(
            fn (array $candidate): bool => $candidate['method'] === 'PUT' && $candidate['uri'] === '/configuracoes/clube',
        ));
        $this->assertTrue($report['interpretation']['diagnostic_only']);
        $this->assertTrue($report['interpretation']['no_routes_changed']);
    }

    public function test_audit_exposes_legacy_redirect_consumers_before_retirement(): void
    {
        $report = app(RouteTopologyAuditService::class)->report();
        $consumers = collect($report['legacy']['redirect_consumers']);

        $this->assertGreaterThanOrEqual(2, $report['summary']['legacy_redirect_consumer_count']);
        $this->assertTrue($consumers->contains(
            fn (array $finding): bool => $finding['source'] === '/marketing'
                && str_contains($finding['path'], 'resources/js/Layouts/Spark/AppLayout.tsx'),
        ));
        $this->assertTrue($consumers->contains(
            fn (array $finding): bool => $finding['source'] === '/settings'
                && str_contains($finding['path'], 'resources/js/Layouts/Spark/AppLayout.tsx'),
        ));
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
