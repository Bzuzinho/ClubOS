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
        $this->assertStringContainsString('.version == "h5c-store-payment-fiscal-audit-v4"', $workflow);
        $this->assertStringContainsString('.read_only == true', $workflow);
        $this->assertStringContainsString('.summary.canonical_invoice_linked_count >= 0', $workflow);
        $this->assertStringContainsString('.summary.legacy_without_invoice_count >= 0', $workflow);
        $this->assertStringContainsString('.summary.invoice_contract_mismatch_count >= 0', $workflow);
        $this->assertStringContainsString('.summary.payment_projection_mismatch_count >= 0', $workflow);
        $this->assertStringContainsString('.summary.paid_fiscal_request_missing_count >= 0', $workflow);
        $this->assertStringContainsString('.summary.cancelled_stock_unbalanced_count >= 0', $workflow);
        $this->assertStringContainsString('.interpretation.canonical_store_invoice_contract_active == true', $workflow);
        $this->assertStringContainsString('.interpretation.store_financial_state_is_derived_from_invoice == true', $workflow);
        $this->assertStringContainsString('.interpretation.paid_store_invoice_requires_manual_wintouch_request == true', $workflow);
        $this->assertStringContainsString('.interpretation.no_data_changed == true', $workflow);
        $this->assertStringContainsString('store-logistics-lifecycle-readiness-${{ github.sha }}', $workflow);
        $this->assertStringContainsString('Artifact contains aggregate counts only; no row or user identifiers.', $workflow);
        $this->assertStringNotContainsString(
            'inventory:audit-store-logistics-stock --json --fail-on-critical',
            $workflow,
        );
    }
}
