<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class FinanceLegacyCleanupReadinessAuditor
{
    private const VERSION = 'fc1-finance-legacy-cleanup-readiness-v1';

    private const FIELD_CENTRO_CUSTO = 'centro_custo';
    private const FIELD_TIPO_MENSALIDADE = 'tipo_mensalidade';
    private const FIELD_CONTA_CORRENTE = 'conta_corrente';

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        self::FIELD_CENTRO_CUSTO,
        self::FIELD_TIPO_MENSALIDADE,
        self::FIELD_CONTA_CORRENTE,
    ];

    /** @var array<string,string> */
    private const CANONICAL_DESTINATIONS = [
        self::FIELD_CENTRO_CUSTO => 'centro_custo_user (centrosCusto())',
        self::FIELD_TIPO_MENSALIDADE => 'dados_financeiros.mensalidade_id',
        self::FIELD_CONTA_CORRENTE => 'dados_financeiros.conta_corrente_manual',
    ];

    /** @var array<string,string> */
    private const OPERATIONAL_SOURCES = [
        self::FIELD_CENTRO_CUSTO => 'MemberCostCenterResolver',
        self::FIELD_TIPO_MENSALIDADE => 'MemberMonthlyFeeResolver + MemberMonthlyFeeSyncService',
        self::FIELD_CONTA_CORRENTE => 'CurrentAccountService::summarize()',
    ];

    /** @var list<string> */
    private const DEFAULT_SCAN_PATHS = [
        'app',
        'routes',
        'database',
        'tests',
        'docs',
        'config',
        'resources/js',
    ];

    /** @var list<string>|null */
    private ?array $scanPaths = null;

    public function __construct(
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
        private readonly MemberManualAccountBalanceResolver $memberManualAccountBalanceResolver,
        private readonly CurrentAccountService $currentAccountService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $fieldFilter = null): array
    {
        $normalizedField = $fieldFilter !== null ? trim($fieldFilter) : null;
        if ($normalizedField !== null && $normalizedField !== '' && !in_array($normalizedField, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Campo invalido para auditoria FC1: %s', $normalizedField));
        }

        $fields = $normalizedField === null || $normalizedField === ''
            ? self::ALLOWED_FIELDS
            : [$normalizedField];

        $users = User::query()
            ->with([
                'dadosFinanceiros:id,user_id,mensalidade_id,conta_corrente_manual',
                'centrosCusto:id',
            ])
            ->select('id', 'estado', 'centro_custo', 'tipo_mensalidade', 'conta_corrente')
            ->orderBy('id')
            ->get();

        $monthlyFeeIds = MonthlyFee::query()->pluck('id')->map(static fn ($id) => (string) $id)->flip();
        $allFindings = $this->scanCodeFindings($fields);

        $fieldPayloads = [];
        foreach ($fields as $field) {
            $fieldPayloads[] = $this->buildFieldPayload($field, $users->all(), $monthlyFeeIds->all(), $allFindings);
        }

        $filteredFindings = array_values(array_filter(
            $allFindings,
            static fn (array $finding): bool => in_array((string) ($finding['field'] ?? ''), $fields, true),
        ));

        $summary = [
            'total_fields' => count($fieldPayloads),
            'ready_fields_count' => count(array_filter($fieldPayloads, static fn (array $row): bool => (bool) ($row['ready_for_cleanup'] ?? false))),
            'not_ready_fields_count' => count(array_filter($fieldPayloads, static fn (array $row): bool => !(bool) ($row['ready_for_cleanup'] ?? false))),
            'prohibited_read_findings_count' => count(array_filter($filteredFindings, static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'prohibited_operational_read')),
            'prohibited_write_findings_count' => count(array_filter($filteredFindings, static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'prohibited_operational_write')),
            'unknown_findings_count' => count(array_filter($filteredFindings, static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'unknown')),
        ];

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'field' => $normalizedField !== null && $normalizedField !== '' ? $normalizedField : 'all',
            ],
            'summary' => $summary,
            'fields' => $fieldPayloads,
            'code_findings' => $filteredFindings,
        ];
    }

    /**
     * @param list<string>|null $paths
     */
    public function useScanPathsForTesting(?array $paths): void
    {
        $this->scanPaths = $this->normalizePaths($paths);
    }

    /**
     * @param list<User> $users
     * @param array<string,int> $monthlyFeeIds
     * @param list<array<string,mixed>> $allFindings
     * @return array<string,mixed>
     */
    private function buildFieldPayload(string $field, array $users, array $monthlyFeeIds, array $allFindings): array
    {
        $legacyColumnExists = Schema::hasColumn('users', $field);

        $fieldFindings = array_values(array_filter(
            $allFindings,
            static fn (array $finding): bool => (string) ($finding['field'] ?? '') === $field,
        ));

        $directReadFindingsCount = count(array_filter(
            $fieldFindings,
            static fn (array $finding): bool => (string) ($finding['access_type'] ?? '') === 'read',
        ));
        $directWriteFindingsCount = count(array_filter(
            $fieldFindings,
            static fn (array $finding): bool => (string) ($finding['access_type'] ?? '') === 'write',
        ));

        $prohibitedReadCount = count(array_filter(
            $fieldFindings,
            static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'prohibited_operational_read',
        ));
        $prohibitedWriteCount = count(array_filter(
            $fieldFindings,
            static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'prohibited_operational_write',
        ));
        $unknownCount = count(array_filter(
            $fieldFindings,
            static fn (array $finding): bool => (string) ($finding['classification'] ?? '') === 'unknown',
        ));

        $metrics = $this->buildMetricsForField($field, $users, $monthlyFeeIds);
        $metrics['direct_read_findings_count'] = $directReadFindingsCount;
        $metrics['direct_write_findings_count'] = $directWriteFindingsCount;

        $blockingReasons = [];

        if (!$legacyColumnExists) {
            $blockingReasons[] = 'legacy_column_missing';
        }

        if ((int) ($metrics['fallback_count'] ?? 0) > 0) {
            $blockingReasons[] = 'fallback_in_use';
        }

        if ((int) ($metrics['divergence_count'] ?? 0) > 0) {
            $blockingReasons[] = 'divergence_detected';
        }

        if ((int) ($metrics['invalid_count'] ?? 0) > 0) {
            $blockingReasons[] = 'invalid_reference_or_value_detected';
        }

        if ((int) ($metrics['missing_required_count'] ?? 0) > 0) {
            $blockingReasons[] = 'missing_required_canonical_data';
        }

        if ($prohibitedReadCount > 0) {
            $blockingReasons[] = 'prohibited_operational_read';
        }

        if ($prohibitedWriteCount > 0) {
            $blockingReasons[] = 'prohibited_operational_write';
        }

        if ($unknownCount > 0) {
            $blockingReasons[] = 'unknown_finding_needs_review';
        }

        $readyForCleanup = $blockingReasons === [];

        return [
            'field' => $field,
            'legacy_column_exists' => $legacyColumnExists,
            'canonical_destination' => self::CANONICAL_DESTINATIONS[$field],
            'operational_source' => self::OPERATIONAL_SOURCES[$field],
            'metrics' => $metrics,
            'code_findings_summary' => [
                'total' => count($fieldFindings),
                'direct_read_findings_count' => $directReadFindingsCount,
                'direct_write_findings_count' => $directWriteFindingsCount,
                'by_classification' => $this->countFindingsByClassification($fieldFindings),
            ],
            'ready_for_cleanup' => $readyForCleanup,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
        ];
    }

    /**
     * @param list<User> $users
     * @param array<string,int> $monthlyFeeIds
     * @return array<string,int>
     */
    private function buildMetricsForField(string $field, array $users, array $monthlyFeeIds): array
    {
        if ($field === self::FIELD_CENTRO_CUSTO) {
            return $this->buildCostCenterMetrics($users);
        }

        if ($field === self::FIELD_TIPO_MENSALIDADE) {
            return $this->buildMonthlyFeeMetrics($users, $monthlyFeeIds);
        }

        return $this->buildCurrentAccountMetrics($users);
    }

    /**
     * @param list<User> $users
     * @return array<string,int>
     */
    private function buildCostCenterMetrics(array $users): array
    {
        $legacyValuesCount = 0;
        $canonicalValuesCount = 0;
        $fallbackCount = 0;
        $divergenceCount = 0;
        $invalidCount = 0;
        $missingRequiredCount = 0;

        foreach ($users as $user) {
            $diagnostic = $this->memberCostCenterResolver->detectDivergence($user);

            $hasCanonical = (bool) ($diagnostic['has_canonical_cost_centers'] ?? false);
            $hasLegacy = (bool) ($diagnostic['has_legacy_fallback'] ?? false);
            $usesFallback = (bool) ($diagnostic['uses_legacy_fallback'] ?? false);
            $hasDivergence = (bool) ($diagnostic['has_divergence'] ?? false);
            $invalidWeightIds = is_array($diagnostic['invalid_weight_ids'] ?? null) ? $diagnostic['invalid_weight_ids'] : [];

            if ($hasLegacy) {
                $legacyValuesCount++;
            }
            if ($hasCanonical) {
                $canonicalValuesCount++;
            }
            if ($usesFallback) {
                $fallbackCount++;
            }
            if ($hasDivergence) {
                $divergenceCount++;
            }
            if ($invalidWeightIds !== []) {
                $invalidCount++;
            }

            if ($this->shouldHaveCostCenter($user) && !$hasCanonical) {
                $missingRequiredCount++;
            }
        }

        return [
            'legacy_values_count' => $legacyValuesCount,
            'canonical_values_count' => $canonicalValuesCount,
            'fallback_count' => $fallbackCount,
            'divergence_count' => $divergenceCount,
            'invalid_count' => $invalidCount,
            'missing_required_count' => $missingRequiredCount,
        ];
    }

    /**
     * @param list<User> $users
     * @param array<string,int> $monthlyFeeIds
     * @return array<string,int>
     */
    private function buildMonthlyFeeMetrics(array $users, array $monthlyFeeIds): array
    {
        $legacyValuesCount = 0;
        $canonicalValuesCount = 0;
        $fallbackCount = 0;
        $divergenceCount = 0;
        $invalidCount = 0;

        foreach ($users as $user) {
            $diagnostic = $this->memberMonthlyFeeResolver->detectDivergence($user);

            $canonicalId = is_string($diagnostic['canonical_monthly_fee_id'] ?? null)
                ? (string) $diagnostic['canonical_monthly_fee_id']
                : null;
            $legacyId = is_string($diagnostic['legacy_monthly_fee_id'] ?? null)
                ? (string) $diagnostic['legacy_monthly_fee_id']
                : null;
            $resolvedId = is_string($diagnostic['resolved_monthly_fee_id'] ?? null)
                ? (string) $diagnostic['resolved_monthly_fee_id']
                : null;

            if ($legacyId !== null) {
                $legacyValuesCount++;
            }
            if ($canonicalId !== null) {
                $canonicalValuesCount++;
            }
            if ((bool) ($diagnostic['uses_legacy_fallback'] ?? false)) {
                $fallbackCount++;
            }
            if ((bool) ($diagnostic['has_divergence'] ?? false)) {
                $divergenceCount++;
            }
            if ($resolvedId !== null && !isset($monthlyFeeIds[$resolvedId])) {
                $invalidCount++;
            }
        }

        return [
            'legacy_values_count' => $legacyValuesCount,
            'canonical_values_count' => $canonicalValuesCount,
            'fallback_count' => $fallbackCount,
            'divergence_count' => $divergenceCount,
            'invalid_count' => $invalidCount,
            'missing_required_count' => 0,
        ];
    }

    /**
     * @param list<User> $users
     * @return array<string,int>
     */
    private function buildCurrentAccountMetrics(array $users): array
    {
        $legacyValuesCount = 0;
        $canonicalValuesCount = 0;
        $fallbackCount = 0;
        $divergenceCount = 0;
        $invalidCount = 0;

        foreach ($users as $user) {
            $diagnostic = $this->memberManualAccountBalanceResolver->detectDivergence($user);

            if ((bool) ($diagnostic['has_legacy_fallback'] ?? false)) {
                $legacyValuesCount++;
            }
            if ((bool) ($diagnostic['has_canonical_manual_balance'] ?? false)) {
                $canonicalValuesCount++;
            }
            if ((bool) ($diagnostic['uses_legacy_fallback'] ?? false)) {
                $fallbackCount++;
            }
            if ((bool) ($diagnostic['has_divergence'] ?? false)) {
                $divergenceCount++;
            }
            if ((bool) ($diagnostic['has_invalid_value'] ?? false)) {
                $invalidCount++;
            }

            // Keep operational source actively exercised in the same audit pass.
            $this->currentAccountService->summarize(['user_id' => (string) $user->id]);
        }

        return [
            'legacy_values_count' => $legacyValuesCount,
            'canonical_values_count' => $canonicalValuesCount,
            'fallback_count' => $fallbackCount,
            'divergence_count' => $divergenceCount,
            'invalid_count' => $invalidCount,
            'missing_required_count' => 0,
        ];
    }

    private function shouldHaveCostCenter(User $user): bool
    {
        if ((string) $user->estado !== 'ativo') {
            return false;
        }

        return $this->memberMonthlyFeeResolver->resolveForUser($user) !== null;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function countFindingsByClassification(array $findings): array
    {
        $counts = [
            'canonical_service_internal' => 0,
            'audit_or_migration' => 0,
            'compatibility_dual_write' => 0,
            'compatibility_legacy_fallback' => 0,
            'test_fixture' => 0,
            'prohibited_operational_read' => 0,
            'prohibited_operational_write' => 0,
            'false_positive' => 0,
            'obsolete_code' => 0,
            'documentation' => 0,
            'unknown' => 0,
        ];

        foreach ($findings as $finding) {
            $classification = is_string($finding['classification'] ?? null)
                ? (string) $finding['classification']
                : 'unknown';

            if (!array_key_exists($classification, $counts)) {
                $classification = 'unknown';
            }

            $counts[$classification]++;
        }

        return $counts;
    }

    /**
     * @param list<string> $fields
     * @return list<array<string,mixed>>
     */
    private function scanCodeFindings(array $fields): array
    {
        $files = $this->resolveScanFiles($this->scanPaths ?? self::DEFAULT_SCAN_PATHS);
        $findings = [];
        $seen = [];

        foreach ($files as $file) {
            $absolutePath = base_path($file);
            if (!File::exists($absolutePath)) {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', (string) File::get($absolutePath)) ?: [];

            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;

                foreach ($fields as $field) {
                    $matches = $this->matchFieldInLine($field, $line);
                    foreach ($matches as $match) {
                        $key = implode('|', [$file, (string) $lineNumber, $field, (string) $match['pattern'], (string) $match['access_type']]);
                        if (isset($seen[$key])) {
                            continue;
                        }

                        $seen[$key] = true;

                        $classification = $this->classifyFinding(
                            $file,
                            (string) $match['access_type'],
                            (string) $line,
                        );

                        $findings[] = [
                            'field' => $field,
                            'file' => $file,
                            'line' => $lineNumber,
                            'pattern' => (string) $match['pattern'],
                            'access_type' => (string) $match['access_type'],
                            'classification' => $classification,
                            'snippet' => trim((string) $line),
                        ];
                    }
                }
            }
        }

        usort(
            $findings,
            static fn (array $a, array $b): int => [
                (string) ($a['field'] ?? ''),
                (string) ($a['file'] ?? ''),
                (int) ($a['line'] ?? 0),
            ] <=> [
                (string) ($b['field'] ?? ''),
                (string) ($b['file'] ?? ''),
                (int) ($b['line'] ?? 0),
            ],
        );

        return $findings;
    }

    /**
     * @return list<array{pattern:string,access_type:string}>
     */
    private function matchFieldInLine(string $field, string $line): array
    {
        $escaped = preg_quote($field, '/');
        $matches = [];

        if (preg_match('/->\s*' . $escaped . '\b\s*=/', $line) === 1) {
            $matches[] = ['pattern' => 'property_assignment', 'access_type' => 'write'];
        } elseif (preg_match('/->\s*' . $escaped . '\b/', $line) === 1) {
            $matches[] = ['pattern' => 'property_access', 'access_type' => 'read'];
        }

        if (preg_match('/\[["\']' . $escaped . '["\']\]\s*=/', $line) === 1) {
            $matches[] = ['pattern' => 'array_access_assignment', 'access_type' => 'write'];
        } elseif (preg_match('/\[["\']' . $escaped . '["\']\]/', $line) === 1) {
            $matches[] = ['pattern' => 'array_access', 'access_type' => 'read'];
        }

        if (preg_match('/getAttribute\(\s*["\']' . $escaped . '["\']\s*\)/', $line) === 1) {
            $matches[] = ['pattern' => 'get_attribute', 'access_type' => 'read'];
        }

        if (preg_match('/users\.' . $escaped . '\b/i', $line) === 1) {
            $matches[] = ['pattern' => 'users_column_select', 'access_type' => 'read'];
        }

        if (preg_match('/["\']' . $escaped . '["\']\s*=>/', $line) === 1) {
            $matches[] = ['pattern' => 'array_key_write', 'access_type' => 'write'];
        }

        return $matches;
    }

    private function classifyFinding(string $file, string $accessType, string $line): string
    {
        if (str_starts_with($file, 'docs/') || str_starts_with($file, 'README')) {
            return 'documentation';
        }

        if ($this->isAuditConfigurationFile($file)) {
            return 'audit_or_migration';
        }

        if (str_starts_with($file, 'tests/') || str_starts_with($file, 'database/factories/') || str_starts_with($file, 'database/seeders/')) {
            return 'test_fixture';
        }

        if (str_starts_with($file, 'database/migrations/') || $this->isAuditOrMigrationFile($file) || $this->isAuditSupportFile($file)) {
            return 'audit_or_migration';
        }

        if ($this->isCanonicalServiceInternal($file)) {
            return 'canonical_service_internal';
        }

        if ($this->isCompatibilityDualWrite($file)) {
            return 'compatibility_dual_write';
        }

        if ($this->isFalsePositiveFinding($file, $accessType, $line)) {
            return 'false_positive';
        }

        if (!str_starts_with($file, 'app/') && !str_starts_with($file, 'routes/') && !str_starts_with($file, 'resources/')) {
            return 'unknown';
        }

        if ($accessType === 'read') {
            return 'prohibited_operational_read';
        }

        if ($accessType === 'write') {
            return 'prohibited_operational_write';
        }

        if (trim($line) === '') {
            return 'documentation';
        }

        return 'unknown';
    }

    private function isAuditOrMigrationFile(string $file): bool
    {
        if (!str_starts_with($file, 'app/Console/Commands/')) {
            return false;
        }

        return preg_match('/(Audit|Backfill|Migrate|Preflight)/', $file) === 1;
    }

    private function isAuditSupportFile(string $file): bool
    {
        return str_starts_with($file, 'app/Services/Financeiro/') && str_ends_with($file, 'Auditor.php');
    }

    private function isAuditConfigurationFile(string $file): bool
    {
        return str_starts_with($file, 'config/member_user_legacy_');
    }

    private function isFalsePositiveFinding(string $file, string $accessType, string $line): bool
    {
        if (str_starts_with($file, 'app/Http/Requests/')) {
            return $accessType === 'write';
        }

        if (str_starts_with($file, 'app/Models/')) {
            return $accessType === 'write';
        }

        if ($accessType === 'write' && str_starts_with($file, 'app/Http/Controllers/')) {
            return preg_match('/=>/', $line) === 1
                && !preg_match('/\b(save|update|forceFill|sync|create|firstOrNew|delete|detach|attach|fill|persist)\b/', $line);
        }

        if ($accessType === 'read' && str_starts_with($file, 'app/Http/Controllers/')) {
            return preg_match('/\[["\'].*["\']\]/', $line) === 1
                && !str_contains($line, 'getAttribute(');
        }

        return false;
    }

    private function isCanonicalServiceInternal(string $file): bool
    {
        return in_array($file, [
            'app/Services/Financeiro/MemberCostCenterResolver.php',
            'app/Services/Financeiro/MemberMonthlyFeeResolver.php',
            'app/Services/Financeiro/MemberManualAccountBalanceResolver.php',
            'app/Services/Financeiro/CurrentAccountService.php',
            'app/Services/Financeiro/FinanceLegacyCleanupReadinessAuditor.php',
            'app/Console/Commands/Financeiro/AuditMemberCostCentersCommand.php',
            'app/Console/Commands/Financeiro/AuditMemberMonthlyFeesCommand.php',
            'app/Console/Commands/Financeiro/AuditMemberCurrentAccountsCommand.php',
            'app/Console/Commands/Financeiro/AuditLegacyCleanupReadinessCommand.php',
        ], true);
    }

    private function isCompatibilityDualWrite(string $file): bool
    {
        return in_array($file, [
            'app/Services/Financeiro/MemberCostCenterSyncService.php',
            'app/Services/Financeiro/MemberMonthlyFeeSyncService.php',
            'app/Services/Financeiro/MemberCostCenterLegacyBackfillService.php',
            'app/Services/Financeiro/MemberMonthlyFeeLegacyBackfillService.php',
            'app/Http/Controllers/MembrosController.php',
            'app/Services/Members/MemberImportService.php',
        ], true);
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolveScanFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $normalizedPath = $this->normalizePath($path);
            if ($normalizedPath === '') {
                continue;
            }

            $absolutePath = base_path($normalizedPath);
            if (File::isFile($absolutePath)) {
                if ($this->shouldScanFile($normalizedPath)) {
                    $files[] = $normalizedPath;
                }

                continue;
            }

            if (!File::isDirectory($absolutePath)) {
                continue;
            }

            foreach (File::allFiles($absolutePath) as $entry) {
                $relative = $this->normalizePath($entry->getPathname());
                if ($relative !== '' && $this->shouldScanFile($relative)) {
                    $files[] = $relative;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function shouldScanFile(string $path): bool
    {
        return preg_match('/\.(php|md|txt|js|ts|tsx)$/', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $base = str_replace('\\', '/', base_path());

        if (str_starts_with($normalized, $base . '/')) {
            $normalized = substr($normalized, strlen($base) + 1);
        }

        if ($normalized === $base) {
            return '';
        }

        return ltrim($normalized, '/');
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

            $normalizedPath = $this->normalizePath($path);
            if ($normalizedPath === '') {
                continue;
            }

            $normalized[] = $normalizedPath;
        }

        return array_values(array_unique($normalized));
    }
}
