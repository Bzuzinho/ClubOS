<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class R2HardeningContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_r2_probe_has_valid_bash_syntax_and_proves_required_data_plane_operations(): void
    {
        $relativePath = 'scripts/ops/dr/probe-r2-access.sh';
        $path = $this->root.'/'.$relativePath;
        $script = $this->read($relativePath);

        $output = [];
        $exitCode = 0;
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('${DR_REMOTE_BASE}/.access-probe', $script);
        self::assertStringContainsString('rclone lsf "${DR_REMOTE_BASE}"', $script);
        self::assertStringContainsString('rclone copyto "${LOCAL_OBJECT}" "${REMOTE_OBJECT}"', $script);
        self::assertStringContainsString('rclone cat "${REMOTE_OBJECT}"', $script);
        self::assertStringContainsString('rclone deletefile "${REMOTE_OBJECT}"', $script);
        self::assertStringContainsString("echo 'list_access=ok'", $script);
        self::assertStringContainsString("echo 'write_access=ok'", $script);
        self::assertStringContainsString("echo 'read_access=ok'", $script);
        self::assertStringContainsString("echo 'delete_access=ok'", $script);
        self::assertStringContainsString("echo 'delete_verification=ok'", $script);
        self::assertStringContainsString("echo 'r2_access_probe=ok'", $script);
        self::assertStringContainsString('trap cleanup EXIT', $script);
    }

    public function test_bucket_lock_verifier_is_read_only_and_enforces_the_three_production_prefixes(): void
    {
        $relativePath = 'scripts/ops/dr/check-r2-bucket-lock.sh';
        $path = $this->root.'/'.$relativePath;
        $script = $this->read($relativePath);

        $output = [];
        $exitCode = 0;
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('/r2/buckets/${CF_R2_BUCKET}/lock', $script);
        self::assertStringNotContainsString('--request PUT', $script);
        self::assertStringNotContainsString('-X PUT', $script);
        self::assertStringContainsString("'daily': 604800", $script);
        self::assertStringContainsString("'weekly': 2419200", $script);
        self::assertStringContainsString("'monthly': 31968000", $script);
        self::assertStringContainsString("condition.get('type') != 'Age'", $script);
        self::assertStringContainsString("print('r2_bucket_lock=ok')", $script);
    }

    public function test_activation_gates_backup_on_the_r2_data_plane_probe(): void
    {
        $workflow = $this->read('.github/workflows/dr-r2-activate.yml');

        $probePosition = strpos($workflow, "echo 'phase=data-plane-access-probe'");
        $backupPosition = strpos($workflow, "echo 'phase=first-offsite-backup'");

        self::assertNotFalse($probePosition);
        self::assertNotFalse($backupPosition);
        self::assertLessThan($backupPosition, $probePosition);
        self::assertStringContainsString('scripts/ops/dr/probe-r2-access.sh', $workflow);

        // The production data-plane workflow must not need a Cloudflare control-plane token.
        self::assertStringNotContainsString('CLOUDFLARE_API_TOKEN', $workflow);
        self::assertStringNotContainsString('CF_API_TOKEN', $workflow);
    }

    public function test_hardening_runbook_requires_single_bucket_object_credentials_and_exact_lock_prefixes(): void
    {
        $runbook = $this->read('docs/DR_R2_HARDENING.md');

        self::assertStringContainsString('Object Read & Write', $runbook);
        self::assertStringContainsString('specific buckets only', $runbook);
        self::assertStringContainsString('clubos-prod/daily/', $runbook);
        self::assertStringContainsString('604800', $runbook);
        self::assertStringContainsString('clubos-prod/weekly/', $runbook);
        self::assertStringContainsString('2419200', $runbook);
        self::assertStringContainsString('clubos-prod/monthly/', $runbook);
        self::assertStringContainsString('31968000', $runbook);
        self::assertStringContainsString('Admin Read & Write', $runbook);
        self::assertStringContainsString('revogar', $runbook);
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
