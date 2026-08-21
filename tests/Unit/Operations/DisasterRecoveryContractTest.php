<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class DisasterRecoveryContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_local_backup_requires_postgresql_17_and_validates_restore_catalogue(): void
    {
        $script = $this->read('scripts/ops/database/backup-local-postgres.sh');

        self::assertStringContainsString('/usr/lib/postgresql/17/bin/pg_dump', $script);
        self::assertStringContainsString('/usr/lib/postgresql/17/bin/pg_restore', $script);
        self::assertStringContainsString('sha256sum -c', $script);
        self::assertStringContainsString('--list', $script);
        self::assertStringContainsString('BACKUPS_TO_KEEP=7', $script);
    }

    public function test_offsite_backup_is_client_side_encrypted_and_has_tiered_retention(): void
    {
        $script = $this->read('scripts/ops/dr/backup-offsite.sh');

        self::assertStringContainsString('--symmetric', $script);
        self::assertStringContainsString('--cipher-algo AES256', $script);
        self::assertStringContainsString('rclone copyto', $script);
        self::assertStringContainsString('rclone cat', $script);
        self::assertStringContainsString('apply_retention daily', $script);
        self::assertStringContainsString('apply_retention weekly', $script);
        self::assertStringContainsString('apply_retention monthly', $script);
    }

    public function test_common_dr_contract_defaults_to_7_daily_4_weekly_12_monthly(): void
    {
        $script = $this->read('scripts/ops/dr/common.sh');

        self::assertStringContainsString('DR_RETENTION_DAILY="${DR_RETENTION_DAILY:-7}"', $script);
        self::assertStringContainsString('DR_RETENTION_WEEKLY="${DR_RETENTION_WEEKLY:-4}"', $script);
        self::assertStringContainsString('DR_RETENTION_MONTHLY="${DR_RETENTION_MONTHLY:-12}"', $script);
        self::assertStringContainsString('DR_MAX_LOCAL_AGE_SECONDS="${DR_MAX_LOCAL_AGE_SECONDS:-93600}"', $script);
        self::assertStringContainsString('DR_MAX_RESTORE_TEST_AGE_SECONDS="${DR_MAX_RESTORE_TEST_AGE_SECONDS:-691200}"', $script);
    }

    public function test_offsite_restore_test_uses_postgresql_17_and_temporary_database(): void
    {
        $script = $this->read('scripts/ops/dr/restore-test-offsite.sh');

        self::assertStringContainsString('clubos_dr_restore_', $script);
        self::assertStringContainsString('DR_CREATEDB', $script);
        self::assertStringContainsString('DR_DROPDB', $script);
        self::assertStringContainsString('public_table_count', $script);
        self::assertStringContainsString('migration_count', $script);
        self::assertStringContainsString('last-restore-test-success', $script);
    }

    public function test_health_monitor_becomes_strict_after_dr_is_enabled(): void
    {
        $script = $this->read('scripts/ops/dr/check-dr-health.sh');

        self::assertStringContainsString('DR_ENABLED_MARKER', $script);
        self::assertStringContainsString('offsite_status=not_enabled', $script);
        self::assertStringContainsString('last-offsite-success', $script);
        self::assertStringContainsString('last-restore-test-success', $script);
        self::assertStringContainsString('rclone lsf', $script);
    }

    public function test_all_disaster_recovery_shell_scripts_have_valid_bash_syntax(): void
    {
        foreach ([
            'scripts/ops/database/backup-local-postgres.sh',
            'scripts/ops/database/restore-local-postgres.sh',
            'scripts/ops/dr/common.sh',
            'scripts/ops/dr/configure-r2.sh',
            'scripts/ops/dr/backup-offsite.sh',
            'scripts/ops/dr/restore-test-offsite.sh',
            'scripts/ops/dr/check-dr-health.sh',
            'scripts/ops/dr/install-dr-cron.sh',
        ] as $relativePath) {
            $path = $this->root.'/'.$relativePath;
            self::assertFileExists($path);

            $output = [];
            $exitCode = 0;
            exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

            self::assertSame(
                0,
                $exitCode,
                sprintf("Bash syntax invalid in %s:\n%s", $relativePath, implode("\n", $output)),
            );
        }
    }

    private function read(string $relativePath): string
    {
        $path = $this->root.'/'.$relativePath;
        self::assertFileExists($path);

        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }
}
