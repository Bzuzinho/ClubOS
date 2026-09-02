<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class StoreLogisticsProductionAuditWorkflowContractTest extends TestCase
{
    public function test_deploy_collects_minimized_read_only_store_logistics_evidence(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

        $this->assertStringContainsString(
            'php artisan inventory:audit-store-logistics-stock --json --report-path=',
            $workflow,
        );
        $this->assertStringContainsString('.version == "h5a-store-logistics-stock-audit-v2"', $workflow);
        $this->assertStringContainsString('.read_only == true', $workflow);
        $this->assertStringContainsString('.summary.cancelled_stock_unbalanced_count >= 0', $workflow);
        $this->assertStringContainsString('.interpretation.no_data_changed == true', $workflow);
        $this->assertStringContainsString('store-logistics-lifecycle-readiness-${{ github.sha }}', $workflow);
        $this->assertStringContainsString('Artifact contains aggregate counts only; no row or user identifiers.', $workflow);
        $this->assertStringNotContainsString(
            'inventory:audit-store-logistics-stock --json --fail-on-critical',
            $workflow,
        );
    }
}
