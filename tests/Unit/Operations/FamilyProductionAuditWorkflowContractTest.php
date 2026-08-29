<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class FamilyProductionAuditWorkflowContractTest extends TestCase
{
    public function test_main_deploy_gates_and_preserves_final_family_schema_audit(): void
    {
        $workflowPath = dirname(__DIR__, 3).'/.github/workflows/ci.yml';
        self::assertFileExists($workflowPath);

        $workflow = file_get_contents($workflowPath);
        self::assertIsString($workflow);

        self::assertStringContainsString(
            'php artisan members:audit-family-final-schema --json --fail-on-finding',
            $workflow,
        );
        self::assertStringContainsString(
            '.version == "family-final-schema-v1"',
            $workflow,
        );
        self::assertStringContainsString(
            '.summary.legacy_structures_present_count == 0',
            $workflow,
        );
        self::assertStringContainsString(
            'family-final-schema-${{ github.sha }}',
            $workflow,
        );

        self::assertStringNotContainsString(
            'members:audit-family-legacy-relationships',
            $workflow,
        );
        self::assertStringNotContainsString(
            'members:audit-family-json-mirrors',
            $workflow,
        );
    }
}
