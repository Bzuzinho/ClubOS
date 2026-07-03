<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\File;

final class LegacyCleanupReadinessAuditor
{
    private const VERSION = 'M5';

    private const ROLLBACK_DOC_PATH = 'docs/MEMBERS_LEGACY_CLEANUP_M5.md';

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'estado_civil',
        'numero_irmaos',
        'declaracao_transporte',
    ];

    /** @var list<string>|null */
    private ?array $readScanPaths = null;

    /** @var list<string>|null */
    private ?array $writeScanPaths = null;

    public function __construct(
        private readonly UsersLegacyBackfillValidationAuditor $backfillValidationAuditor,
        private readonly UsersLegacyReadScanner $readScanner,
        private readonly UsersLegacyWriteGuardScanner $writeGuardScanner,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $fieldFilter = null): array
    {
        $normalizedFilter = $fieldFilter !== null ? trim($fieldFilter) : null;
        if ($normalizedFilter !== null && $normalizedFilter !== '' && !in_array($normalizedFilter, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Campo invalido para auditoria de cleanup legacy: %s', $normalizedFilter));
        }

        $fieldsToAudit = $normalizedFilter === null || $normalizedFilter === ''
            ? self::ALLOWED_FIELDS
            : [$normalizedFilter];

        $readAudit = $this->readScanner->scan($this->readScanPaths, $this->readScanner->defaultAllowlist());
        $writeAudit = $this->writeGuardScanner->scan($this->writeScanPaths);

        $readCountByField = $this->countByField(is_array($readAudit['findings'] ?? null) ? $readAudit['findings'] : []);
        $writeCountByField = $this->countByField(is_array($writeAudit['violations'] ?? null) ? $writeAudit['violations'] : []);

        $rows = [];
        foreach ($fieldsToAudit as $field) {
            $rows[] = $this->auditField(
                $field,
                (int) ($readCountByField[$field] ?? 0),
                (int) ($writeCountByField[$field] ?? 0),
            );
        }

        $summary = [
            'fields_analyzed' => count($rows),
            'ready_for_cleanup_count' => count(array_filter($rows, static fn (array $row): bool => ($row['cleanup_status'] ?? null) === 'ready_for_cleanup')),
            'blocked_count' => count(array_filter($rows, static fn (array $row): bool => ($row['cleanup_status'] ?? null) === 'blocked')),
            'needs_manual_review_count' => count(array_filter($rows, static fn (array $row): bool => ($row['cleanup_status'] ?? null) === 'needs_manual_review')),
            'not_ready_count' => count(array_filter($rows, static fn (array $row): bool => !((bool) ($row['ready_for_cleanup'] ?? false)))),
            'rollback_doc_path' => self::ROLLBACK_DOC_PATH,
        ];

        return [
            'version' => self::VERSION,
            'summary' => $summary,
            'fields' => $rows,
        ];
    }

    /**
     * @param list<string>|null $readPaths
     * @param list<string>|null $writePaths
     */
    public function useScanPathsForTesting(?array $readPaths, ?array $writePaths): void
    {
        $this->readScanPaths = $this->normalizePaths($readPaths);
        $this->writeScanPaths = $this->normalizePaths($writePaths);
    }

    /**
     * @return array<string,mixed>
     */
    private function auditField(string $field, int $forbiddenReadCount, int $forbiddenWriteCount): array
    {
        $validation = $this->backfillValidationAuditor->audit($field);
        $fieldRow = $this->firstFieldRow($validation['fields'] ?? []);

        $canonicalArea = is_array($fieldRow) && is_string($fieldRow['canonical_area'] ?? null)
            ? trim((string) $fieldRow['canonical_area'])
            : '';
        $canonicalField = is_array($fieldRow) && is_string($fieldRow['canonical_field'] ?? null)
            ? trim((string) $fieldRow['canonical_field'])
            : '';

        $legacyOnlyCount = is_array($fieldRow) ? (int) ($fieldRow['legacy_only_count'] ?? 0) : 0;
        $divergentCount = is_array($fieldRow) ? (int) ($fieldRow['divergent_count'] ?? 0) : 0;
        $nonScalarReviewCount = is_array($fieldRow) ? (int) ($fieldRow['non_scalar_review_count'] ?? 0) : 0;
        $legacyNonEmptyCount = is_array($fieldRow) ? (int) ($fieldRow['legacy_non_empty_count'] ?? 0) : 0;
        $readinessStatus = is_array($fieldRow) && is_string($fieldRow['readiness_status'] ?? null)
            ? trim((string) $fieldRow['readiness_status'])
            : 'needs_manual_review';

        $hasCanonicalTarget = $canonicalArea !== '' && $canonicalField !== '';
        $allRelevantLegacyMigrated = $hasCanonicalTarget
            && $legacyOnlyCount === 0
            && $divergentCount === 0
            && $nonScalarReviewCount === 0;
        $hasNoDivergences = $divergentCount === 0 && $nonScalarReviewCount === 0;
        $hasNoForbiddenReads = $forbiddenReadCount === 0;
        $hasNoForbiddenWrites = $forbiddenWriteCount === 0;
        $rollbackDocumented = $this->hasRollbackDocumentationForField($field);

        $readyForCleanup = $hasCanonicalTarget
            && $allRelevantLegacyMigrated
            && $hasNoDivergences
            && $hasNoForbiddenReads
            && $hasNoForbiddenWrites
            && $rollbackDocumented
            && $readinessStatus === 'ready_for_cleanup';

        $cleanupStatus = $readyForCleanup
            ? 'ready_for_cleanup'
            : ($readinessStatus === 'needs_manual_review' ? 'needs_manual_review' : 'blocked');

        return [
            'field' => $field,
            'canonical_area' => $canonicalArea !== '' ? $canonicalArea : null,
            'canonical_field' => $canonicalField !== '' ? $canonicalField : null,
            'legacy_non_empty_count' => $legacyNonEmptyCount,
            'legacy_only_count' => $legacyOnlyCount,
            'divergent_count' => $divergentCount,
            'non_scalar_review_count' => $nonScalarReviewCount,
            'readiness_status' => $readinessStatus,
            'forbidden_legacy_read_count' => $forbiddenReadCount,
            'forbidden_legacy_write_count' => $forbiddenWriteCount,
            'checks' => [
                'canonical_target_exists' => $hasCanonicalTarget,
                'legacy_values_migrated' => $allRelevantLegacyMigrated,
                'no_divergences' => $hasNoDivergences,
                'no_forbidden_legacy_reads' => $hasNoForbiddenReads,
                'no_forbidden_legacy_writes' => $hasNoForbiddenWrites,
                'classified_ready_for_cleanup' => $readinessStatus === 'ready_for_cleanup',
                'rollback_plan_documented' => $rollbackDocumented,
            ],
            'ready_for_cleanup' => $readyForCleanup,
            'cleanup_status' => $cleanupStatus,
        ];
    }

    /**
     * @param mixed $rows
     * @return array<string,mixed>|null
     */
    private function firstFieldRow(mixed $rows): ?array
    {
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    private function hasRollbackDocumentationForField(string $field): bool
    {
        $absolutePath = base_path(self::ROLLBACK_DOC_PATH);
        if (!File::exists($absolutePath)) {
            return false;
        }

        $content = (string) File::get($absolutePath);

        return str_contains($content, '## Plano de rollback')
            && str_contains($content, $field);
    }

    /**
     * @param list<string>|null $paths
     * @return list<string>|null
     */
    private function normalizePaths(?array $paths): ?array
    {
        if ($paths === null) {
            return null;
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function countByField(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $field = is_string($item['field'] ?? null) ? trim((string) $item['field']) : '';
            if ($field === '') {
                continue;
            }

            $counts[$field] = (int) ($counts[$field] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
