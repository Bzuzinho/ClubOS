<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;

final class QualityRatchetContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 3);
    }

    public function test_typescript_baseline_is_explicit_versioned_and_zero(): void
    {
        $baseline = json_decode($this->read('qa/baselines/typescript.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('clubos-typescript-baseline-v1', $baseline['contract'] ?? null);
        self::assertSame(0, $baseline['max_errors'] ?? null);
        self::assertSame(0, $baseline['max_affected_files'] ?? null);
    }

    public function test_package_exposes_typescript_ratchet(): void
    {
        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'node scripts/qa/typecheck-ratchet.mjs',
            $package['scripts']['typecheck:ratchet'] ?? null,
        );
    }

    public function test_typescript_ratchet_fails_on_regression_above_zero_baseline(): void
    {
        $script = $this->read('scripts/qa/typecheck-ratchet.mjs');

        self::assertStringContainsString('clubos-typescript-baseline-v1', $script);
        self::assertStringContainsString('errorCount > baseline.max_errors', $script);
        self::assertStringContainsString('affectedFileCount > baseline.max_affected_files', $script);
        self::assertStringContainsString('TypeScript debt regressed above the accepted H1 baseline.', $script);
    }

    public function test_composer_ratchet_requires_zero_advisories(): void
    {
        $script = $this->read('scripts/qa/composer-audit-ratchet.sh');

        self::assertStringContainsString('advisory_count > 0', $script);
        self::assertStringContainsString('zero advisories are permitted after H1.14', $script);
        self::assertStringContainsString('Composer security ratchet passed at zero advisories.', $script);
    }

    public function test_npm_ratchet_requires_zero_vulnerabilities(): void
    {
        $script = $this->read('scripts/qa/npm-audit-ratchet.mjs');

        self::assertStringContainsString('total !== 0', $script);
        self::assertStringContainsString('names.length !== 0', $script);
        self::assertStringContainsString('zero vulnerabilities are permitted after H1.15', $script);
        self::assertStringNotContainsString("name !== 'xlsx'", $script);
        self::assertStringNotContainsString('fixAvailable', $script);
    }

    public function test_xlsx_dependency_is_pinned_to_vendored_secure_release(): void
    {
        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);
        $checksum = trim($this->read('vendor/xlsx-0.20.3.tgz.sha256'));

        self::assertSame('file:vendor/xlsx-0.20.3.tgz', $package['dependencies']['xlsx'] ?? null);
        self::assertStringStartsWith('8dc73fc3b00203e72d176e85b50938627c7b086e607c682e8d3c22c02bb99fe8 ', $checksum);
        self::assertFileExists($this->root.'/vendor/xlsx-0.20.3.tgz');
        self::assertFileExists($this->root.'/scripts/qa/xlsx-import-contract.mjs');
    }

    public function test_ci_executes_all_h1_ratchets_and_spreadsheet_contract_before_build_and_tests(): void
    {
        $workflow = $this->read('.github/workflows/ci.yml');

        $composer = strpos($workflow, 'bash scripts/qa/composer-audit-ratchet.sh');
        $npm = strpos($workflow, 'node scripts/qa/npm-audit-ratchet.mjs');
        $xlsx = strpos($workflow, 'node scripts/qa/xlsx-import-contract.mjs');
        $typescript = strpos($workflow, 'npm run typecheck:ratchet');
        $build = strpos($workflow, 'npm run build');
        $tests = strpos($workflow, 'php artisan test');

        foreach ([$composer, $npm, $xlsx, $typescript, $build, $tests] as $position) {
            self::assertNotFalse($position);
        }

        self::assertLessThan($npm, $composer);
        self::assertLessThan($xlsx, $npm);
        self::assertLessThan($typescript, $xlsx);
        self::assertLessThan($build, $typescript);
        self::assertLessThan($tests, $build);
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
