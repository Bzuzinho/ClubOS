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

    public function test_typescript_baseline_is_explicit_and_versioned(): void
    {
        $baseline = json_decode($this->read('qa/baselines/typescript.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('clubos-typescript-baseline-v1', $baseline['contract'] ?? null);
        self::assertSame(132, $baseline['max_errors'] ?? null);
        self::assertSame(55, $baseline['max_affected_files'] ?? null);
    }

    public function test_package_exposes_typescript_ratchet(): void
    {
        $package = json_decode($this->read('package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'node scripts/qa/typecheck-ratchet.mjs',
            $package['scripts']['typecheck:ratchet'] ?? null,
        );
    }

    public function test_typescript_ratchet_fails_only_on_regression_above_baseline(): void
    {
        $script = $this->read('scripts/qa/typecheck-ratchet.mjs');

        self::assertStringContainsString("clubos-typescript-baseline-v1", $script);
        self::assertStringContainsString('errorCount > baseline.max_errors', $script);
        self::assertStringContainsString('affectedFileCount > baseline.max_affected_files', $script);
        self::assertStringContainsString('TypeScript debt regressed above the accepted H1 baseline.', $script);
    }

    public function test_composer_ratchet_only_accepts_bounded_laravel_11_residual(): void
    {
        $script = $this->read('scripts/qa/composer-audit-ratchet.sh');

        self::assertStringContainsString('select(. != "laravel/framework")', $script);
        self::assertStringContainsString('advisory_count > 3', $script);
        self::assertStringContainsString('laravel_count > 3', $script);
        self::assertStringContainsString('high_count > 1', $script);
        self::assertStringContainsString('critical_count > 0', $script);
    }

    public function test_npm_ratchet_only_accepts_single_unfixed_xlsx_residual(): void
    {
        $script = $this->read('scripts/qa/npm-audit-ratchet.mjs');

        self::assertStringContainsString("name !== 'xlsx'", $script);
        self::assertStringContainsString('total > 1', $script);
        self::assertStringContainsString('high > 1', $script);
        self::assertStringContainsString('moderate > 0', $script);
        self::assertStringContainsString('low > 0', $script);
        self::assertStringContainsString('xlsx.fixAvailable !== false', $script);
    }

    public function test_ci_executes_all_h1_ratchets_before_build_and_tests(): void
    {
        $workflow = $this->read('.github/workflows/ci.yml');

        $composer = strpos($workflow, 'bash scripts/qa/composer-audit-ratchet.sh');
        $npm = strpos($workflow, 'node scripts/qa/npm-audit-ratchet.mjs');
        $typescript = strpos($workflow, 'npm run typecheck:ratchet');
        $build = strpos($workflow, 'npm run build');
        $tests = strpos($workflow, 'php artisan test');

        foreach ([$composer, $npm, $typescript, $build, $tests] as $position) {
            self::assertNotFalse($position);
        }

        self::assertLessThan($npm, $composer);
        self::assertLessThan($typescript, $npm);
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
