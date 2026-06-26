<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\File;

final class UsersLegacyReadScanner
{
    /** @var list<string> */
    private const BLOCKED_CATEGORIES = [
        'member_personal_legacy',
        'member_configuration_legacy',
    ];

    /** @var list<string> */
    private const DEFAULT_SCAN_PATHS = [
        'app/Http/Controllers',
        'app/Services',
        'app/Actions',
        'app/Console/Commands',
        'app/Models',
        'routes',
    ];

    /** @var list<string> */
    private const EXCLUDED_PREFIXES = [
        'tests/',
        'docs/',
        'config/',
        'vendor/',
        'storage/',
        'bootstrap/cache/',
        'node_modules/',
    ];

    /** @var array<string, string> */
    private const DEFAULT_ALLOWLIST = [
        'app/Services/Members/MemberDataReadService.php' => 'Read fallback canonico autorizado.',
        'app/Services/Members/MemberDataWriteService.php' => 'Mapeamento/canonicalizacao de dados de membro.',
        'app/Console/Commands/MembersAuditDataStructure.php' => 'Comando de auditoria/read-only M2.',
        'app/Console/Commands/MembersBackfillDataStructure.php' => 'Comando de backfill M2/M3 com guardas.',
        'app/Console/Commands/Members/AuditMemberDataStructureCommand.php' => 'Comando de auditoria/read-only legado permitido.',
        'app/Console/Commands/Members/AuditMemberDataFallbackCommand.php' => 'Comando de auditoria/read-only M4.',
        'app/Console/Commands/Members/AuditUsersLegacyFieldMapCommand.php' => 'Comando de auditoria/read-only M4.',
        'app/Console/Commands/Members/AuditUsersLegacyWriteGuardCommand.php' => 'Comando de auditoria/read-only M4.',
        'app/Console/Commands/Members/BackfillMemberContactCommand.php' => 'Backfill controlado e auditavel M4.',
        'app/Services/Members/UsersLegacyWriteGuardScanner.php' => 'Scanner de auditoria de escrita legado.',
        'app/Services/Members/UsersLegacyReadScanner.php' => 'Scanner de auditoria de leitura legado.',
    ];

    /**
     * @param list<string>|null $paths
     * @param list<string>|null $allowlist
     * @return array{summary: array{blocked_fields_count:int, scanned_files:int, findings_count:int}, findings:list<array{file:string,field:string,pattern:string,line:int,snippet:string}>}
     */
    public function scan(?array $paths = null, ?array $allowlist = null): array
    {
        $blockedFields = $this->blockedFields();
        $blockedLookup = array_fill_keys($blockedFields, true);

        $customPathsProvided = $paths !== null;
        $files = $this->resolveScanFiles($paths ?? self::DEFAULT_SCAN_PATHS, $customPathsProvided);
        $allowlistLookup = array_fill_keys($this->normalizeAllowlist($allowlist), true);

        $findings = [];
        $scannedFiles = 0;

        foreach ($files as $file) {
            if (isset($allowlistLookup[$file])) {
                continue;
            }

            $absolutePath = base_path($file);
            if (!File::exists($absolutePath)) {
                continue;
            }

            $content = (string) File::get($absolutePath);
            $scannedFiles++;

            $findings = array_merge(
                $findings,
                $this->scanDirectPropertyReadPattern($content, $file, $blockedFields),
                $this->scanDataGetPattern($content, $file, $blockedFields),
                $this->scanArrGetPattern($content, $file, $blockedFields),
                $this->scanSelectLikePattern($content, $file, $blockedLookup),
                $this->scanPluckPattern($content, $file, $blockedFields),
                $this->scanValuePattern($content, $file, $blockedFields),
                $this->scanArrayAccessPattern($content, $file, $blockedFields),
            );
        }

        $findings = $this->uniqueFindings($findings);

        return [
            'summary' => [
                'blocked_fields_count' => count($blockedFields),
                'scanned_files' => $scannedFiles,
                'findings_count' => count($findings),
            ],
            'findings' => array_values($findings),
        ];
    }

    /**
     * @return list<string>
     */
    public function blockedFields(): array
    {
        $config = config('member_user_legacy_fields.categories');
        if (!is_array($config)) {
            return [];
        }

        $fields = [];

        foreach (self::BLOCKED_CATEGORIES as $category) {
            $categoryFields = $config[$category]['fields'] ?? [];
            if (!is_array($categoryFields)) {
                continue;
            }

            foreach ($categoryFields as $field) {
                if (!is_string($field) || trim($field) === '') {
                    continue;
                }

                $fields[] = trim($field);
            }
        }

        $fields = array_values(array_unique($fields));
        sort($fields);

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function defaultAllowlist(): array
    {
        $paths = array_keys(self::DEFAULT_ALLOWLIST);

        return array_values(array_filter(array_map(
            fn (string $path): string => $this->normalizeRelativePath($path),
            $paths,
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolveScanFiles(array $paths, bool $forceIncludeExplicitPaths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $normalizedPath = $this->normalizePathInput($path);
            if ($normalizedPath === '') {
                continue;
            }

            $absolutePath = base_path($normalizedPath);

            if (File::isFile($absolutePath)) {
                if ($this->shouldScanPath($normalizedPath, $forceIncludeExplicitPaths)) {
                    $files[] = $normalizedPath;
                }

                continue;
            }

            if (!File::isDirectory($absolutePath)) {
                continue;
            }

            $phpFiles = File::allFiles($absolutePath);
            foreach ($phpFiles as $phpFile) {
                if ($phpFile->getExtension() !== 'php') {
                    continue;
                }

                $relative = $this->normalizeRelativePath($phpFile->getPathname());
                if ($this->shouldScanPath($relative, $forceIncludeExplicitPaths)) {
                    $files[] = $relative;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string>|null $allowlist
     * @return list<string>
     */
    private function normalizeAllowlist(?array $allowlist): array
    {
        $raw = $allowlist ?? $this->defaultAllowlist();

        $normalized = [];
        foreach ($raw as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $normalizedPath = $this->normalizePathInput($path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalized[] = $normalizedPath;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    private function shouldScanPath(string $relativePath, bool $forceIncludeExplicitPaths = false): bool
    {
        if ($relativePath === '') {
            return false;
        }

        if (!str_ends_with($relativePath, '.php')) {
            return false;
        }

        if ($forceIncludeExplicitPaths) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $excludedPrefix) {
            if (str_starts_with($relativePath, $excludedPrefix)) {
                return false;
            }
        }

        return true;
    }

    private function normalizePathInput(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, base_path() . DIRECTORY_SEPARATOR) || $trimmed === base_path()) {
            return $this->normalizeRelativePath($trimmed);
        }

        return $this->normalizeRelativePath($trimmed);
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $basePath = str_replace('\\', '/', base_path());

        if (str_starts_with($normalized, $basePath . '/')) {
            $normalized = substr($normalized, strlen($basePath) + 1);
        }

        if ($normalized === $basePath) {
            return '';
        }

        return ltrim($normalized, '/');
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanDirectPropertyReadPattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/\$[a-zA-Z_][a-zA-Z0-9_]*->' . preg_quote($field, '/') . '\b(?!\s*=)/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => '$object->field',
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanDataGetPattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/\bdata_get\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*,\s*["\']' . preg_quote($field, '/') . '["\']\s*[,)]/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "data_get(\$object, 'field')",
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanArrGetPattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/\bArr::get\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*,\s*["\']' . preg_quote($field, '/') . '["\']\s*[,)]/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "Arr::get(\$object, 'field')",
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param array<string, true> $blockedLookup
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanSelectLikePattern(string $content, string $file, array $blockedLookup): array
    {
        $findings = [];
        $matches = [];

        preg_match_all('/(?:->|::)(select|addSelect)\s*\((.*?)\)/s', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[2] ?? [] as $argsMatch) {
            $args = is_string($argsMatch[0] ?? null) ? $argsMatch[0] : '';
            $offset = (int) ($argsMatch[1] ?? 0);

            $fields = [];
            preg_match_all('/["\']([a-zA-Z_][a-zA-Z0-9_]*)["\']/', $args, $fields, PREG_OFFSET_CAPTURE);

            foreach ($fields[1] ?? [] as $fieldMatch) {
                $field = is_string($fieldMatch[0] ?? null) ? $fieldMatch[0] : '';
                if ($field === '' || !isset($blockedLookup[$field])) {
                    continue;
                }

                $localOffset = (int) ($fieldMatch[1] ?? 0);
                $absoluteOffset = $offset + $localOffset;

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "select('field')",
                    'line' => $this->lineFromOffset($content, $absoluteOffset),
                    'snippet' => $this->lineSnippetFromOffset($content, $absoluteOffset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanPluckPattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/->pluck\s*\(\s*["\']' . preg_quote($field, '/') . '["\']/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "pluck('field')",
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanValuePattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/->value\s*\(\s*["\']' . preg_quote($field, '/') . '["\']/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "value('field')",
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $blockedFields
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanArrayAccessPattern(string $content, string $file, array $blockedFields): array
    {
        $findings = [];

        foreach ($blockedFields as $field) {
            $regex = '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\[\s*["\']' . preg_quote($field, '/') . '["\']\s*\]/';

            $matches = [];
            preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] ?? [] as $match) {
                $offset = (int) ($match[1] ?? 0);
                if (!$this->hasUsersContext($content, $offset)) {
                    continue;
                }

                $findings[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => "\$row['field']",
                    'line' => $this->lineFromOffset($content, $offset),
                    'snippet' => $this->lineSnippetFromOffset($content, $offset),
                ];
            }
        }

        return $findings;
    }

    private function hasUsersContext(string $content, int $offset): bool
    {
        $windowStart = max(0, $offset - 200);
        $windowLength = 400;
        $window = substr($content, $windowStart, $windowLength);

        return preg_match('/\b(users|User::|App\\\\Models\\\\User|\$user|\$member|\$atleta)\b/i', $window) === 1;
    }

    /**
     * @param list<array{file:string,field:string,pattern:string,line:int,snippet:string}> $findings
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function uniqueFindings(array $findings): array
    {
        $lookup = [];

        foreach ($findings as $finding) {
            $key = implode('|', [
                $finding['file'],
                $finding['field'],
                $finding['pattern'],
                (string) $finding['line'],
            ]);

            $lookup[$key] = $finding;
        }

        $result = array_values($lookup);

        usort($result, static function (array $left, array $right): int {
            $fileCmp = strcmp((string) $left['file'], (string) $right['file']);
            if ($fileCmp !== 0) {
                return $fileCmp;
            }

            $lineCmp = ((int) $left['line']) <=> ((int) $right['line']);
            if ($lineCmp !== 0) {
                return $lineCmp;
            }

            return strcmp((string) $left['field'], (string) $right['field']);
        });

        return $result;
    }

    private function lineFromOffset(string $content, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function lineSnippetFromOffset(string $content, int $offset): string
    {
        $lineStart = strrpos(substr($content, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $lineEnd = strpos($content, "\n", $offset);
        if ($lineEnd === false) {
            $lineEnd = strlen($content);
        }

        return trim(substr($content, $lineStart, $lineEnd - $lineStart));
    }
}
