<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class DisasterRecoveryActivationWorkflowContractTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 3).'/.github/workflows/dr-r2-activate.yml';
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        $this->workflow = $content;
    }

    public function test_activation_is_not_triggered_by_normal_main_or_pull_request_activity(): void
    {
        self::assertStringContainsString('workflow_dispatch:', $this->workflow);
        self::assertStringContainsString('- ops/h0-2-r2-activate', $this->workflow);
        self::assertStringNotContainsString('pull_request:', $this->workflow);
        self::assertStringNotContainsString('- main', $this->workflow);
        self::assertStringContainsString('cancel-in-progress: false', $this->workflow);
    }

    public function test_activation_requires_all_vm_and_dr_secrets(): void
    {
        foreach ([
            'ORACLE_VM_USER',
            'ORACLE_VM_HOST',
            'ORACLE_VM_APP_DIR',
            'ORACLE_VM_KNOWN_HOSTS',
            'ORACLE_VM_SSH_KEY',
            'CLUBOS_DR_R2_ENDPOINT',
            'CLUBOS_DR_R2_BUCKET',
            'CLUBOS_DR_R2_ACCESS_KEY_ID',
            'CLUBOS_DR_R2_SECRET_ACCESS_KEY',
            'CLUBOS_DR_BACKUP_PASSPHRASE',
        ] as $secret) {
            self::assertStringContainsString('secrets.'.$secret, $this->workflow);
        }
    }

    public function test_activation_keeps_ssh_pinned_and_uses_keepalives_for_long_restore(): void
    {
        self::assertStringContainsString('StrictHostKeyChecking=yes', $this->workflow);
        self::assertStringContainsString('UserKnownHostsFile=', $this->workflow);
        self::assertStringContainsString('ssh-keygen -F', $this->workflow);
        self::assertStringContainsString('ServerAliveInterval=30', $this->workflow);
        self::assertStringContainsString('ServerAliveCountMax=6', $this->workflow);
    }

    public function test_activation_proves_real_backup_restore_before_enabling_cron_and_strict_health(): void
    {
        $commands = [
            'configure-r2.sh',
            'backup-offsite.sh',
            'restore-test-offsite.sh',
            'install-dr-cron.sh',
            'check-dr-health.sh',
            'dr_r2_activation=ok',
        ];

        $previous = -1;
        foreach ($commands as $command) {
            $position = strpos($this->workflow, $command);
            self::assertNotFalse($position, sprintf('Missing activation phase: %s', $command));
            self::assertGreaterThan($previous, $position, sprintf('Activation phase out of order: %s', $command));
            $previous = $position;
        }

        self::assertStringContainsString('phase=ensure-rclone', $this->workflow);
        self::assertStringContainsString('/var/lib/clubos-dr/last-offsite-success', $this->workflow);
        self::assertStringContainsString('/var/lib/clubos-dr/last-restore-test-success', $this->workflow);
    }

    public function test_transient_bootstrap_secret_file_is_private_and_removed(): void
    {
        self::assertStringContainsString('umask 077', $this->workflow);
        self::assertStringContainsString('chmod 600 "${bootstrap_env}"', $this->workflow);
        self::assertStringContainsString('chmod 600 "${REMOTE_ENV}"', $this->workflow);
        self::assertStringContainsString('cleanup_remote', $this->workflow);
        self::assertStringContainsString('rm -f -- "${REMOTE_ENV}"', $this->workflow);
        self::assertStringNotContainsString('set -x', $this->workflow);
    }
}
