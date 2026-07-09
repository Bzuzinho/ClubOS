<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use Illuminate\Support\Facades\File;

class LegacySaleCodeReferenceScanner
{
    /**
     * @return array{operational_write_paths:list<array{path:string,line:int,snippet:string}>,operational_read_paths:list<array{path:string,line:int,snippet:string}>}
     */
    public function scan(): array
    {
        $writePaths = [];
        $readPaths = [];

        foreach ($this->scanRoots() as $root) {
            if (!File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $content = File::get($absolutePath);
                if (!str_contains($content, 'Sale::')) {
                    continue;
                }

                $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $absolutePath);
                $classification = $this->classifyFileReferences($content, $relativePath);

                if ($classification['operational_write'] !== null) {
                    $writePaths[] = $classification['operational_write'];
                    continue;
                }

                if ($classification['operational_read'] !== null) {
                    $readPaths[] = $classification['operational_read'];
                }
            }
        }

        usort($writePaths, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($readPaths, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return [
            'operational_write_paths' => $writePaths,
            'operational_read_paths' => $readPaths,
        ];
    }

    /**
     * @return list<string>
     */
    private function scanRoots(): array
    {
        return [
            app_path(),
            base_path('routes'),
        ];
    }

    /**
     * @return array{operational_write:array{path:string,line:int,snippet:string}|null,operational_read:array{path:string,line:int,snippet:string}|null}
     */
    private function classifyFileReferences(string $content, string $relativePath): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $firstWrite = null;
        $firstRead = null;

        foreach ($lines as $index => $line) {
            if (!str_contains($line, 'Sale::')) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            if ($this->isWriteReference($trimmed)) {
                $firstWrite = [
                    'path' => $relativePath,
                    'line' => $index + 1,
                    'snippet' => $trimmed,
                ];
                break;
            }

            if ($firstRead === null) {
                $firstRead = [
                    'path' => $relativePath,
                    'line' => $index + 1,
                    'snippet' => $trimmed,
                ];
            }
        }

        return [
            'operational_write' => $firstWrite,
            'operational_read' => $firstWrite === null ? $firstRead : null,
        ];
    }

    private function isWriteReference(string $line): bool
    {
        return preg_match('/Sale::\s*(create|forceCreate|firstOrCreate|updateOrCreate|insert|upsert|destroy|truncate)\s*\(/', $line) === 1
            || preg_match('/Sale::query\(\)->\s*(update|delete|insert|upsert)\s*\(/', $line) === 1
            || preg_match('/Sale::[a-zA-Z0-9_]+\([^\n]*->\s*(update|delete)\s*\(/', $line) === 1;
    }
}