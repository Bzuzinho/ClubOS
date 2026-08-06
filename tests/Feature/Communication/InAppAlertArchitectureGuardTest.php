<?php

namespace Tests\Feature\Communication;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class InAppAlertArchitectureGuardTest extends TestCase
{
    public function test_production_code_creates_in_app_alerts_only_through_central_service(): void
    {
        $allowedPath = realpath(app_path('Services/Communication/InAppAlertService.php'));
        $violations = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();
            if ($path === $allowedPath) {
                continue;
            }

            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            $forbiddenPatterns = [
                '/InAppAlert\s*::\s*create\s*\(/',
                '/InAppAlert\s*::\s*query\s*\(\s*\)\s*->\s*create\s*\(/',
                '/new\s+InAppAlert\s*\(/',
                '/DB\s*::\s*table\s*\(\s*[\'\"]in_app_alerts[\'\"]\s*\)\s*->\s*insert/',
            ];

            foreach ($forbiddenPatterns as $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($violations)),
            "A criação de alertas internos deve passar pelo InAppAlertService. Violações:\n- " . implode("\n- ", $violations)
        );
    }
}
