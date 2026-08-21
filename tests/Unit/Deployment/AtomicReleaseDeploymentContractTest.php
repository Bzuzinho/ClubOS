<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class AtomicReleaseDeploymentContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_canonical_deploy_uses_atomic_release_layout_and_pinned_ssh(): void
    {
        $orchestrator = $this->read('bin/deploy-vm.mjs');
        $remote = $this->read('bin/remote-deploy-backend.sh');

        self::assertStringContainsString('StrictHostKeyChecking=yes', $orchestrator);
        self::assertStringContainsString('UserKnownHostsFile=', $orchestrator);
        self::assertStringContainsString('localHead', $orchestrator);
        self::assertStringContainsString('remoteBuildDir', $orchestrator);

        self::assertStringContainsString('DEPLOY_ROOT="${APP_DIR}.deploy"', $remote);
        self::assertStringContainsString('RELEASES_DIR="${DEPLOY_ROOT}/releases"', $remote);
        self::assertStringContainsString('SHARED_DIR="${DEPLOY_ROOT}/shared"', $remote);
        self::assertStringContainsString('CURRENT_LINK="${DEPLOY_ROOT}/current"', $remote);
        self::assertStringContainsString('PREVIOUS_LINK="${DEPLOY_ROOT}/previous"', $remote);
        self::assertStringContainsString('atomic_exchange', $remote);
        self::assertStringContainsString('rollback_after_failed_switch', $remote);
        self::assertStringContainsString('migrate --pretend --force', $remote);
        self::assertStringContainsString('migrate --force', $remote);
        self::assertStringNotContainsString('reset --hard origin/main', $remote);
        self::assertStringNotContainsString('ssh-keyscan', $orchestrator);
    }

    public function test_healthcheck_and_manual_rollback_are_release_aware(): void
    {
        $healthcheck = $this->read('bin/remote-healthcheck.sh');
        $rollback = $this->read('bin/remote-release-rollback.sh');

        self::assertStringContainsString('/up', $healthcheck);
        self::assertStringContainsString('HTTP %s', $healthcheck);
        self::assertStringContainsString('"${STATUS}" != "200"', $healthcheck);
        self::assertStringContainsString('CURRENT_LINK="${DEPLOY_ROOT}/current"', $rollback);
        self::assertStringContainsString('PREVIOUS_LINK="${DEPLOY_ROOT}/previous"', $rollback);
        self::assertStringContainsString('clubmanager-healthcheck.sh', $rollback);
    }

    public function test_legacy_shell_entrypoint_cannot_bypass_atomic_orchestrator(): void
    {
        $wrapper = $this->read('bin/deploy-vm.sh');

        self::assertStringContainsString('exec npm run deploy:vm', $wrapper);
        self::assertStringNotContainsString('129.159.13.211', $wrapper);
        self::assertStringNotContainsString('ssh-keyscan', $wrapper);
        self::assertStringNotContainsString('git pull', $wrapper);
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
