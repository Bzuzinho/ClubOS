<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class VariantStockProductionAuditWorkflowContractTest extends TestCase
{
    public function test_deploy_collects_minimized_read_only_variant_stock_evidence(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

        $this->assertStringContainsString(
            'php artisan inventory:audit-variant-stock-readiness --json --report-path=',
            $workflow,
        );
        $this->assertStringContainsString('.version == "variant-stock-readiness-v1"', $workflow);
        $this->assertStringContainsString('.read_only == true', $workflow);
        $this->assertStringContainsString('.schema_detected.stock_movement_variant_column_present == true', $workflow);
        $this->assertStringContainsString('.summary.known_direct_variant_stock_writer_count == 0', $workflow);
        $this->assertStringContainsString('.interpretation.no_backfill_or_schema_change_performed == true', $workflow);
        $this->assertStringContainsString('variant-stock-production-readiness-${{ github.sha }}', $workflow);
        $this->assertStringContainsString('Artifact contains aggregate counts only; no row or user identifiers.', $workflow);
        $this->assertStringNotContainsString(
            'inventory:audit-variant-stock-readiness --json --fail-on-invalid-reference',
            $workflow,
        );
    }
}
