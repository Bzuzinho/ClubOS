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
        self::assertStringContainsString("local dump=\"$1\"\n    local checksum=\"\${dump}.sha256\"", $script);
        self::assertStringNotContainsString('local dump="$1" checksum="${dump}.sha256"', $script);
    }

    public function test_existing_daily_backup_is_validated_successfully_under_nounset(): void
    {
        $tmp = sys_get_temp_dir().'/clubos-dr-contract-'.bin2hex(random_bytes(6));
        $backupDir = $tmp.'/backups';
        $binDir = $tmp.'/bin';
        $envFile = $tmp.'/.env';
        $lockFile = $tmp.'/backup.lock';

        mkdir($backupDir, 0700, true);
        mkdir($binDir, 0700, true);

        $pgDump = $binDir.'/pg_dump';
        $pgRestore = $binDir.'/pg_restore';

        file_put_contents($pgDump, <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "--version" ]]; then
  echo 'pg_dump (PostgreSQL) 17.0'
  exit 0
fi
exit 0
BASH);
        file_put_contents($pgRestore, <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "--version" ]]; then
  echo 'pg_restore (PostgreSQL) 17.0'
  exit 0
fi
if [[ "${1:-}" == "--list" ]]; then
  exit 0
fi
exit 0
BASH);
        chmod($pgDump, 0755);
        chmod($pgRestore, 0755);

        file_put_contents($envFile, implode("\n", [
            'DB_CONNECTION=pgsql',
            'DB_HOST=127.0.0.1',
            'DB_PORT=5433',
            'DB_DATABASE=clubos_test',
            'DB_USERNAME=clubos_test',
            'DB_PASSWORD=clubos_test',
            '',
        ]));

        $dump = $backupDir.'/clubmanager-prod-'.gmdate('Ymd').'-010101.dump';
        file_put_contents($dump, 'fixture');
        file_put_contents(
            $dump.'.sha256',
            hash_file('sha256', $dump).'  '.basename($dump).PHP_EOL,
        );

        $output = [];
        $exitCode = 0;
        $command = sprintf(
            'env ENV_FILE=%s BACKUP_DIR=%s LOCK_FILE=%s PG_DUMP_PREFERRED=%s PG_RESTORE_PREFERRED=%s PATH=%s bash %s 2>&1',
            escapeshellarg($envFile),
            escapeshellarg($backupDir),
            escapeshellarg($lockFile),
            escapeshellarg($pgDump),
            escapeshellarg($pgRestore),
            escapeshellarg($binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin')),
            escapeshellarg($this->root.'/scripts/ops/database/backup-local-postgres.sh'),
        );

        try {
            exec($command, $output, $exitCode);

            self::assertSame(
                0,
                $exitCode,
                "Existing daily backup validation failed:\n".implode("\n", $output),
            );
            self::assertStringContainsString(
                'checksum and pg_restore catalogue are OK',
                implode("\n", $output),
            );
        } finally {
            exec('rm -rf -- '.escapeshellarg($tmp));
        }
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
