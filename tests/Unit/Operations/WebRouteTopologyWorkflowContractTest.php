<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class WebRouteTopologyWorkflowContractTest extends TestCase
{
    public function test_ci_enforces_and_archives_the_web_route_topology_contract(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

        self::assertIsString($workflow);
        self::assertStringContainsString('php artisan routes:audit-web-topology', $workflow);
        self::assertStringContainsString('--fail-on-contract-drift', $workflow);
        self::assertStringContainsString('.summary.contract_matches_baseline == true', $workflow);
        self::assertStringContainsString('.summary.fallback_route_count == 1', $workflow);
        self::assertStringContainsString('.contract.fallback_name == "public.custom-page"', $workflow);
        self::assertStringContainsString('.summary.source_literal_duplicate_candidate_count == 1', $workflow);
        self::assertStringContainsString('.summary.source_literal_duplicate_unclassified_count == 0', $workflow);
        self::assertStringContainsString('.summary.legacy_redirect_consumer_count == 0', $workflow);
        self::assertStringContainsString('.summary.retired_shadowed_alias_reference_count == 0', $workflow);
        self::assertStringContainsString('.summary.modular_route_file_count == 23', $workflow);
        self::assertStringContainsString('.contract.hash == .contract.baseline_hash', $workflow);
        self::assertStringContainsString('name: web-route-topology-${{ github.sha }}', $workflow);
    }
}
