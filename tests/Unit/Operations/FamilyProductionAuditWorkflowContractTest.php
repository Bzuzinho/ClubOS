<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class FamilyProductionAuditWorkflowContractTest extends TestCase
{
    public function test_main_deploy_collects_minimised_read_only_family_readiness_artifacts(): void
    {
        $workflowPath = dirname(__DIR__, 3).'/.github/workflows/ci.yml';
        self::assertFileExists($workflowPath);

        $workflow = file_get_contents($workflowPath);
        self::assertIsString($workflow);

        self::assertStringContainsString(
            'php artisan members:audit-family-legacy-relationships --json',
            $workflow,
        );
        self::assertStringContainsString(
            'php artisan members:audit-family-json-mirrors --json',
            $workflow,
        );
        self::assertStringContainsString("jq 'del(.unresolved)'", $workflow);
        self::assertStringContainsString(
            "jq 'del(.source.findings, .data.unresolved)'",
            $workflow,
        );
        self::assertStringContainsString(
            'family-legacy-relationships-production-readiness-${{ github.sha }}',
            $workflow,
        );
        self::assertStringContainsString(
            'family-json-mirrors-production-readiness-${{ github.sha }}',
            $workflow,
        );

        self::assertStringNotContainsString(
            'members:audit-family-legacy-relationships --json --fail-on-uncovered',
            $workflow,
        );
        self::assertStringNotContainsString(
            'members:audit-family-json-mirrors --json --fail-on-finding',
            $workflow,
        );
    }
}
