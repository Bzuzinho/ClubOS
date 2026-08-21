<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class DisasterRecoveryActivationWorkflowTest extends TestCase
{
    public function test_r2_activation_workflow_is_manual_gated_and_secret_safe(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root.'/.github/workflows/dr-activate-r2.yml';

        self::assertFileExists($path);
        $workflow = file_get_contents($path);
        self::assertIsString($workflow);

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString('ACTIVATE-R2', $workflow);
        self::assertStringContainsString('bucket_locks_confirmed', $workflow);

        foreach ([
            'CLUBOS_DR_R2_ENDPOINT',
            'CLUBOS_DR_R2_BUCKET',
            'CLUBOS_DR_R2_ACCESS_KEY_ID',
            'CLUBOS_DR_R2_SECRET_ACCESS_KEY',
            'CLUBOS_DR_BACKUP_PASSPHRASE',
        ] as $secret) {
            self::assertStringContainsString($secret, $workflow);
        }

        self::assertStringContainsString('StrictHostKeyChecking=yes', $workflow);
        self::assertStringContainsString('REMOTE_PAYLOAD', $workflow);
        self::assertStringContainsString('base64 -w0', $workflow);
        self::assertStringNotContainsString('set -x', $workflow);

        $configure = strpos($workflow, 'scripts/ops/dr/configure-r2.sh');
        $backup = strpos($workflow, 'scripts/ops/dr/backup-offsite.sh');
        $restore = strpos($workflow, 'scripts/ops/dr/restore-test-offsite.sh');
        $cron = strpos($workflow, 'scripts/ops/dr/install-dr-cron.sh');
        $health = strpos($workflow, 'scripts/ops/dr/check-dr-health.sh');

        self::assertIsInt($configure);
        self::assertIsInt($backup);
        self::assertIsInt($restore);
        self::assertIsInt($cron);
        self::assertIsInt($health);
        self::assertLessThan($backup, $configure);
        self::assertLessThan($restore, $backup);
        self::assertLessThan($cron, $restore);
        self::assertLessThan($health, $cron);

        self::assertStringContainsString('production_sha_mismatch', $workflow);
        self::assertStringContainsString('dr_activation=ok', $workflow);
    }
}
