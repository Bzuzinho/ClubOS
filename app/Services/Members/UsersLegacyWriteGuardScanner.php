<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\File;

final class UsersLegacyWriteGuardScanner
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
        'routes',
    ];

    /** @var array<string, string> */
    private const DEFAULT_ALLOWLIST = [
        'app/Http/Controllers/EventosController.php' => 'Historical view-model assignment without persistence on users.',
        'app/Services/Members/MemberDataReadService.php' => 'Read-only fallback service.',
        'app/Services/Members/MemberDataMigrationService.php' => 'Historical migration/backfill logic.',
        'app/Console/Commands/Members/AuditMemberDataStructureCommand.php' => 'Read-only audit command.',
        'app/Console/Commands/Members/AuditMemberDataFallbackCommand.php' => 'Read-only fallback audit command.',
        'app/Console/Commands/Members/AuditUsersLegacyFieldMapCommand.php' => 'Read-only field-map audit command.',
        'app/Console/Commands/Members/BackfillMemberContactCommand.php' => 'Explicitly write-gated backfill command.',
    ];

    /**
     * @param list<string>|null $paths
     * @param list<string>|null $allowlist
     * @return array{blocked_fields_count:int, scanned_files:int, violations:list<array{file:string,field:string,pattern:string,line:int,snippet:string}>}
     */
    public function scan(?array $paths = null, ?array $allowlist = null): array
    {
        $blockedFields = $this->blockedFields();
        $blockedLookup = array_fill_keys($blockedFields, true);

        $files = $this->resolveScanFiles($paths ?? self::DEFAULT_SCAN_PATHS);
        $allowlistLookup = array_fill_keys($this->normalizeAllowlist($allowlist), true);

        $violations = [];
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

            $violations = array_merge(
                $violations,
                $this->scanWritePayloadPattern($content, $file, '/\\bUser::create\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s', 'user_create_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, '/->update\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s', 'update_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, '/->fill\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s', 'fill_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, '/->forceFill\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s', 'force_fill_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, "/\\bDB::table\\s*\\(\\s*['\"]users['\"]\\s*\\)(?:(?!;).)*?->insert\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s", 'db_users_insert_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, "/\\bDB::table\\s*\\(\\s*['\"]users['\"]\\s*\\)(?:(?!;).)*?->update\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s", 'db_users_update_payload', $blockedLookup),
                $this->scanWritePayloadPattern($content, $file, "/\\bDB::table\\s*\\(\\s*['\"]users['\"]\\s*\\)(?:(?!;).)*?->upsert\\s*\\(\\s*\\[(.*?)\\]\\s*\\)/s", 'db_users_upsert_payload', $blockedLookup),
                $this->scanDirectAssignmentPattern($content, $file, $blockedLookup),
            );
        }

        return [
            'blocked_fields_count' => count($blockedFields),
            'scanned_files' => $scannedFiles,
            'violations' => array_values($violations),
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
    private function resolveScanFiles(array $paths): array
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
                if ($this->shouldScanPath($normalizedPath)) {
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
                if ($this->shouldScanPath($relative)) {
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

    private function shouldScanPath(string $relativePath): bool
    {
        if ($relativePath === '') {
            return false;
        }

        if (!str_ends_with($relativePath, '.php')) {
            return false;
        }

        foreach (['tests/', 'docs/', 'config/'] as $excludedPrefix) {
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
        $normalized = str_replace('\\\\', '/', trim($path));
        $basePath = str_replace('\\\\', '/', base_path());

        if (str_starts_with($normalized, $basePath . '/')) {
            $normalized = substr($normalized, strlen($basePath) + 1);
        }

        if ($normalized === $basePath) {
            return '';
        }

        return ltrim($normalized, '/');
    }

    /**
     * @param array<string, true> $blockedLookup
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanWritePayloadPattern(
        string $content,
        string $file,
        string $regex,
        string $pattern,
        array $blockedLookup,
    ): array {
        $matches = [];
        preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

        $violations = [];

        foreach ($matches[1] ?? [] as $payloadMatch) {
            $payload = is_string($payloadMatch[0]) ? $payloadMatch[0] : '';
            $payloadOffset = (int) ($payloadMatch[1] ?? 0);

            $keys = [];
            preg_match_all("/[\"']([a-zA-Z_][a-zA-Z0-9_]*)[\"']\\s*=>/", $payload, $keys, PREG_OFFSET_CAPTURE);

            foreach ($keys[1] ?? [] as $keyMatch) {
                $field = is_string($keyMatch[0] ?? null) ? $keyMatch[0] : '';
                if ($field === '' || !isset($blockedLookup[$field])) {
                    continue;
                }

                $fieldOffsetInPayload = (int) ($keyMatch[1] ?? 0);
                $absoluteOffset = $payloadOffset + $fieldOffsetInPayload;

                $violations[] = [
                    'file' => $file,
                    'field' => $field,
                    'pattern' => $pattern,
                    'line' => $this->lineFromOffset($content, $absoluteOffset),
                    'snippet' => $this->lineSnippetFromOffset($content, $absoluteOffset),
                ];
            }
        }

        return $violations;
    }

    /**
     * @param array<string, true> $blockedLookup
     * @return list<array{file:string,field:string,pattern:string,line:int,snippet:string}>
     */
    private function scanDirectAssignmentPattern(string $content, string $file, array $blockedLookup): array
    {
        $matches = [];
        preg_match_all('/\\$user->([a-zA-Z_][a-zA-Z0-9_]*)\\s*=/', $content, $matches, PREG_OFFSET_CAPTURE);

        $violations = [];

        foreach ($matches[1] ?? [] as $fieldMatch) {
            $field = is_string($fieldMatch[0] ?? null) ? $fieldMatch[0] : '';
            if ($field === '' || !isset($blockedLookup[$field])) {
                continue;
            }

            $offset = (int) ($fieldMatch[1] ?? 0);

            $violations[] = [
                'file' => $file,
                'field' => $field,
                'pattern' => 'user_direct_assignment',
                'line' => $this->lineFromOffset($content, $offset),
                'snippet' => $this->lineSnippetFromOffset($content, $offset),
            ];
        }

        return $violations;
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