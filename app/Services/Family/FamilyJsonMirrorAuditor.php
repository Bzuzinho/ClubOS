<?php

declare(strict_types=1);

namespace App\Services\Family;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class FamilyJsonMirrorAuditor
{
    /** @var list<string> */
    private const FIELDS = ['encarregado_educacao', 'educandos'];

    /** @var list<string> */
    private const PHP_SCAN_PATHS = [
        'app/Http/Controllers',
        'app/Services',
        'app/Actions',
        'app/Console/Commands',
        'routes',
    ];

    /** @var list<string> */
    private const FRONTEND_SCAN_PATHS = ['resources/js'];

    /** @var list<string> */
    private const SOURCE_ALLOWLIST = [
        'app/Services/Family/FamilyJsonMirrorAuditor.php',
        'app/Console/Commands/Members/AuditFamilyJsonMirrorsCommand.php',
    ];

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $source = $this->auditSourceUsage();
        $data = $this->auditDataCoverage();

        return [
            'version' => 'H2.3a',
            'summary' => [
                'source_findings_count' => count($source['findings']),
                'declared_json_links_count' => $data['declared_json_links_count'],
                'unique_json_pairs_count' => $data['unique_json_pairs_count'],
                'canonical_covered_pairs_count' => $data['canonical_covered_pairs_count'],
                'uncovered_pairs_count' => $data['uncovered_pairs_count'],
                'invalid_reference_count' => $data['invalid_reference_count'],
                'self_reference_count' => $data['self_reference_count'],
                'ready_for_physical_cleanup' => count($source['findings']) === 0
                    && $data['uncovered_pairs_count'] === 0
                    && $data['invalid_reference_count'] === 0
                    && $data['self_reference_count'] === 0,
            ],
            'source' => $source,
            'data' => $data,
        ];
    }

    /**
     * @return array{scanned_files:int, findings:list<array<string,mixed>>}
     */
    private function auditSourceUsage(): array
    {
        $files = array_merge(
            $this->resolveFiles(self::PHP_SCAN_PATHS, ['php']),
            $this->resolveFiles(self::FRONTEND_SCAN_PATHS, ['ts', 'tsx', 'js', 'jsx']),
        );
        $allowlist = array_fill_keys(self::SOURCE_ALLOWLIST, true);
        $findings = [];
        $scannedFiles = 0;

        foreach ($files as $relativePath) {
            if (isset($allowlist[$relativePath])) {
                continue;
            }

            $content = (string) File::get(base_path($relativePath));
            $scannedFiles++;
            $isPhp = str_ends_with($relativePath, '.php');

            foreach (self::FIELDS as $field) {
                $patterns = $isPhp
                    ? $this->phpPatterns($field)
                    : $this->frontendPatterns($field);

                foreach ($patterns as $patternName => $regex) {
                    preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE);

                    foreach ($matches[0] ?? [] as $match) {
                        $offset = (int) ($match[1] ?? 0);
                        $findings[] = [
                            'file' => $relativePath,
                            'field' => $field,
                            'pattern' => $patternName,
                            'line' => substr_count(substr($content, 0, $offset), "\n") + 1,
                            'snippet' => $this->lineSnippet($content, $offset),
                        ];
                    }
                }
            }
        }

        $findings = collect($findings)
            ->unique(fn (array $finding): string => implode('|', [
                $finding['file'],
                $finding['field'],
                $finding['line'],
                $finding['pattern'],
            ]))
            ->values()
            ->all();

        return [
            'scanned_files' => $scannedFiles,
            'findings' => $findings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditDataCoverage(): array
    {
        if (! Schema::hasTable('users')) {
            return [
                'declared_json_links_count' => 0,
                'unique_json_pairs_count' => 0,
                'canonical_covered_pairs_count' => 0,
                'uncovered_pairs_count' => 0,
                'invalid_reference_count' => 0,
                'self_reference_count' => 0,
                'unresolved' => [],
            ];
        }

        $columns = array_values(array_filter(
            self::FIELDS,
            fn (string $field): bool => Schema::hasColumn('users', $field),
        ));

        if ($columns === []) {
            return [
                'declared_json_links_count' => 0,
                'unique_json_pairs_count' => 0,
                'canonical_covered_pairs_count' => 0,
                'uncovered_pairs_count' => 0,
                'invalid_reference_count' => 0,
                'self_reference_count' => 0,
                'unresolved' => [],
            ];
        }

        $users = DB::table('users')->get(array_merge(['id'], $columns));
        $knownUsers = $users->pluck('id')->map(fn ($id): string => (string) $id)->flip();
        $canonicalPairs = $this->canonicalGuardianPairs();
        $declaredLinks = 0;
        $pairs = [];
        $invalidReferences = [];
        $selfReferences = [];

        foreach ($users as $user) {
            $userId = (string) $user->id;

            if (in_array('encarregado_educacao', $columns, true)) {
                foreach ($this->normalizeIds($user->encarregado_educacao ?? null) as $guardianId) {
                    $declaredLinks++;
                    $this->registerMirrorPair(
                        $pairs,
                        $invalidReferences,
                        $selfReferences,
                        $knownUsers,
                        $userId,
                        $guardianId,
                        'encarregado_educacao',
                    );
                }
            }

            if (in_array('educandos', $columns, true)) {
                foreach ($this->normalizeIds($user->educandos ?? null) as $dependentId) {
                    $declaredLinks++;
                    $this->registerMirrorPair(
                        $pairs,
                        $invalidReferences,
                        $selfReferences,
                        $knownUsers,
                        $dependentId,
                        $userId,
                        'educandos',
                    );
                }
            }
        }

        $covered = 0;
        $unresolved = [];

        foreach ($pairs as $key => $pair) {
            if (isset($canonicalPairs[$key])) {
                $covered++;
                continue;
            }

            $unresolved[] = [
                'member_id' => $pair['member_id'],
                'guardian_id' => $pair['guardian_id'],
                'sources' => array_values(array_unique($pair['sources'])),
                'reason' => 'missing_user_guardian_projection',
            ];
        }

        return [
            'declared_json_links_count' => $declaredLinks,
            'unique_json_pairs_count' => count($pairs),
            'canonical_covered_pairs_count' => $covered,
            'uncovered_pairs_count' => count($unresolved),
            'invalid_reference_count' => count($invalidReferences),
            'self_reference_count' => count($selfReferences),
            'unresolved' => array_slice(array_merge($unresolved, $invalidReferences, $selfReferences), 0, 100),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function canonicalGuardianPairs(): array
    {
        if (! Schema::hasTable('user_guardian')) {
            return [];
        }

        $pairs = [];
        foreach (DB::table('user_guardian')->get(['user_id', 'guardian_id']) as $row) {
            $pairs[$this->pairKey((string) $row->user_id, (string) $row->guardian_id)] = true;
        }

        return $pairs;
    }

    /**
     * @param array<string, array{member_id:string,guardian_id:string,sources:list<string>}> $pairs
     * @param list<array<string,mixed>> $invalidReferences
     * @param list<array<string,mixed>> $selfReferences
     * @param \Illuminate\Support\Collection<string, int> $knownUsers
     */
    private function registerMirrorPair(
        array &$pairs,
        array &$invalidReferences,
        array &$selfReferences,
        $knownUsers,
        string $memberId,
        string $guardianId,
        string $source,
    ): void {
        if ($memberId === $guardianId) {
            $selfReferences[] = [
                'member_id' => $memberId,
                'guardian_id' => $guardianId,
                'sources' => [$source],
                'reason' => 'self_reference',
            ];

            return;
        }

        if (! $knownUsers->has($memberId) || ! $knownUsers->has($guardianId)) {
            $invalidReferences[] = [
                'member_id' => $memberId,
                'guardian_id' => $guardianId,
                'sources' => [$source],
                'reason' => 'unknown_user_reference',
            ];

            return;
        }

        $key = $this->pairKey($memberId, $guardianId);
        $pairs[$key] ??= [
            'member_id' => $memberId,
            'guardian_id' => $guardianId,
            'sources' => [],
        ];
        $pairs[$key]['sources'][] = $source;
    }

    /**
     * @return list<string>
     */
    private function normalizeIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $paths
     * @param list<string> $extensions
     * @return list<string>
     */
    private function resolveFiles(array $paths, array $extensions): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);
            if (! File::isDirectory($absolute)) {
                continue;
            }

            foreach (File::allFiles($absolute) as $file) {
                if (! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                $relative = str_replace('\\', '/', $file->getPathname());
                $base = str_replace('\\', '/', base_path()).'/';
                if (str_starts_with($relative, $base)) {
                    $relative = substr($relative, strlen($base));
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * Scan only access forms that can address an Eloquent/database attribute.
     * Request/DTO keys and domain labels intentionally are not findings: the
     * same Portuguese words remain valid API vocabulary after the columns die.
     *
     * @return array<string, string>
     */
    private function phpPatterns(string $field): array
    {
        $quoted = preg_quote($field, '/');

        return [
            'direct_property' => '/->[ ]*'.$quoted.'\b(?!\s*\()/',
            'get_attribute' => '/->getAttribute\s*\(\s*[\'\"]'.$quoted.'[\'\"]\s*\)/',
            'set_attribute' => '/->setAttribute\s*\(\s*[\'\"]'.$quoted.'[\'\"]\s*,/',
            'query_value' => '/->value\s*\(\s*[\'\"]'.$quoted.'[\'\"]\s*\)/',
        ];
    }

    /**
     * Frontend `educandos` is also the canonical relation payload name, so a
     * lexical match cannot distinguish it from the retired JSON column. The
     * unambiguous legacy guardian field remains scanned on member payloads.
     *
     * @return array<string, string>
     */
    private function frontendPatterns(string $field): array
    {
        if ($field === 'educandos') {
            return [];
        }

        $quoted = preg_quote($field, '/');

        return [
            'member_property_access' => '/(?:\bmember|\(\s*member\s+as\s+any\s*\))\s*(?:\.|\?\.)'.$quoted.'\b/',
            'member_array_access' => '/\bmember\s*\[\s*[\'\"]'.$quoted.'[\'\"]\s*\]/',
        ];
    }

    private function pairKey(string $memberId, string $guardianId): string
    {
        return $memberId.'|'.$guardianId;
    }

    private function lineSnippet(string $content, int $offset): string
    {
        $before = substr($content, 0, max(0, $offset));
        $start = strrpos($before, "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($content, "\n", $offset);
        $end = $end === false ? strlen($content) : $end;

        return trim(substr($content, $start, $end - $start));
    }
}
