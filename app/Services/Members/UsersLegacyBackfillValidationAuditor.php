<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class UsersLegacyBackfillValidationAuditor
{
    private const VERSION = 'M4.13';

    private const DECISION = 'candidate_after_backfill_validation';

    private const MAX_SAMPLE_DIFFERENCES = 5;

    /** @var list<string> */
    private const BOOLEAN_FIELDS = [
        'declaracao_transporte',
    ];

    /** @var list<string> */
    private const DATE_FIELDS = [
        'data_atestado_medico',
    ];

    /**
     * @return array{
     *   version:string,
     *   summary:array<string,mixed>,
     *   fields:list<array<string,mixed>>,
     *   grouped_summary:array<string,array<string,int>>
     * }
     */
    public function audit(?string $fieldFilter = null): array
    {
        $legacyFieldConfig = config('member_user_legacy_fields');
        $decisionsConfig = config('member_user_legacy_removal_decisions');

        $fieldToCategory = is_array($legacyFieldConfig['field_to_category'] ?? null)
            ? $legacyFieldConfig['field_to_category']
            : [];

        $decisionFields = is_array($decisionsConfig['fields'] ?? null)
            ? $decisionsConfig['fields']
            : [];

        $candidateEntries = $this->candidateEntries($decisionFields, $fieldToCategory);

        if ($fieldFilter !== null) {
            $candidateEntries = $this->filterCandidateEntries($candidateEntries, $fieldFilter);
        }

        $usersColumns = $this->schemaLookup('users');
        $dadosPessoaisColumns = $this->schemaLookup('dados_pessoais');
        $dadosConfiguracaoColumns = $this->schemaLookup('dados_configuracao');

        $legacyFields = array_values(array_filter(
            array_map(static fn (array $entry): ?string => isset($usersColumns[$entry['field']]) ? $entry['field'] : null, $candidateEntries),
            static fn (?string $field): bool => is_string($field) && $field !== ''
        ));

        $personalFields = [];
        $configurationFields = [];

        foreach ($candidateEntries as $entry) {
            $canonicalField = $entry['canonical_field'];
            if (!is_string($canonicalField) || $canonicalField === '') {
                continue;
            }

            if ($entry['canonical_area'] === 'dados_pessoais' && isset($dadosPessoaisColumns[$canonicalField])) {
                $personalFields[] = $canonicalField;
            }

            if ($entry['canonical_area'] === 'dados_configuracao' && isset($dadosConfiguracaoColumns[$canonicalField])) {
                $configurationFields[] = $canonicalField;
            }
        }

        $userSelect = array_values(array_unique(array_merge(['id'], $legacyFields)));
        if (Schema::hasColumn('users', 'dados_pessoais')) {
            $userSelect[] = 'dados_pessoais';
        }

        $users = User::query()
            ->select($userSelect)
            ->with([
                'dadosPessoais' => static fn ($query) => $query->select(array_values(array_unique(array_merge(['id', 'user_id'], $personalFields)))),
                'dadosConfiguracao' => static fn ($query) => $query->select(array_values(array_unique(array_merge(['id', 'user_id'], $configurationFields)))),
                'athleteSportsData:id,user_id,data_atestado_medico',
            ])
            ->orderBy('id')
            ->get();

        $fields = [];
        foreach ($candidateEntries as $entry) {
            $fields[] = $this->auditField(
                $users->all(),
                $entry,
                $usersColumns,
                $dadosPessoaisColumns,
                $dadosConfiguracaoColumns,
            );
        }

        usort($fields, static fn (array $left, array $right): int => strcmp((string) $left['field'], (string) $right['field']));

        $summary = [
            'fields_analyzed' => count($fields),
            'users_analyzed' => $users->count(),
            'ready_for_cleanup_count' => count(array_filter($fields, static fn (array $row): bool => ($row['readiness_status'] ?? null) === 'ready_for_cleanup')),
            'needs_backfill_count' => count(array_filter($fields, static fn (array $row): bool => ($row['readiness_status'] ?? null) === 'needs_backfill')),
            'needs_manual_review_count' => count(array_filter($fields, static fn (array $row): bool => ($row['readiness_status'] ?? null) === 'needs_manual_review')),
            'total_legacy_only_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['legacy_only_count'] ?? 0), $fields)),
            'total_divergent_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['divergent_count'] ?? 0), $fields)),
            'total_non_scalar_review_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['non_scalar_review_count'] ?? 0), $fields)),
        ];

        return [
            'version' => self::VERSION,
            'summary' => $summary,
            'fields' => $fields,
            'grouped_summary' => [
                'by_readiness_status' => $this->countByKey($fields, 'readiness_status'),
                'by_canonical_area' => $this->countByKey($fields, 'canonical_area'),
                'by_category' => $this->countByKey($fields, 'category'),
            ],
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $decisionFields
     * @param array<string,mixed> $fieldToCategory
     * @return list<array{field:string,category:string,canonical_area:string|null,canonical_field:string|null,risk:string}>
     */
    private function candidateEntries(array $decisionFields, array $fieldToCategory): array
    {
        $entries = [];

        foreach ($decisionFields as $field => $config) {
            if (!is_string($field) || trim($field) === '' || !is_array($config)) {
                continue;
            }

            $decision = is_string($config['decision'] ?? null)
                ? trim((string) $config['decision'])
                : trim((string) ($config['removal_status'] ?? ''));

            if ($decision !== self::DECISION) {
                continue;
            }

            $normalizedField = trim($field);
            $category = is_string($fieldToCategory[$normalizedField] ?? null)
                ? trim((string) $fieldToCategory[$normalizedField])
                : 'unknown_review';

            $entries[] = [
                'field' => $normalizedField,
                'category' => $category !== '' ? $category : 'unknown_review',
                'canonical_area' => is_string($config['canonical_area'] ?? null) ? trim((string) $config['canonical_area']) : null,
                'canonical_field' => is_string($config['canonical_field'] ?? null) ? trim((string) $config['canonical_field']) : null,
                'risk' => $this->normalizeRisk(is_string($config['risk'] ?? null) ? (string) $config['risk'] : 'medium'),
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{field:string,category:string,canonical_area:string|null,canonical_field:string|null,risk:string}> $entries
     * @return list<array{field:string,category:string,canonical_area:string|null,canonical_field:string|null,risk:string}>
     */
    private function filterCandidateEntries(array $entries, string $fieldFilter): array
    {
        $normalizedFilter = trim($fieldFilter);

        return array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['field'] === $normalizedFilter,
        ));
    }

    /**
     * @param list<User> $users
     * @param array{field:string,category:string,canonical_area:string|null,canonical_field:string|null,risk:string} $entry
     * @param array<string,bool> $usersColumns
     * @param array<string,bool> $dadosPessoaisColumns
     * @param array<string,bool> $dadosConfiguracaoColumns
     * @return array<string,mixed>
     */
    private function auditField(
        array $users,
        array $entry,
        array $usersColumns,
        array $dadosPessoaisColumns,
        array $dadosConfiguracaoColumns,
    ): array {
        $field = $entry['field'];
        $canonicalArea = $entry['canonical_area'];
        $canonicalField = $entry['canonical_field'];

        $hasLegacyField = isset($usersColumns[$field]);
        $hasCanonicalMapping = $this->hasCanonicalMapping(
            $canonicalArea,
            $canonicalField,
            $dadosPessoaisColumns,
            $dadosConfiguracaoColumns,
        );

        $result = [
            'field' => $field,
            'category' => $entry['category'],
            'canonical_area' => $canonicalArea,
            'canonical_field' => $canonicalField,
            'users_total' => count($users),
            'legacy_non_empty_count' => 0,
            'canonical_non_empty_count' => 0,
            'matching_non_empty_count' => 0,
            'legacy_only_count' => 0,
            'canonical_only_count' => 0,
            'divergent_count' => 0,
            'empty_both_count' => 0,
            'non_scalar_review_count' => 0,
            'readiness_status' => 'ready_for_cleanup',
            'risk' => $entry['risk'],
            'sample_differences' => [],
        ];

        foreach ($users as $user) {
            $legacyRaw = $hasLegacyField ? $user->getAttribute($field) : null;
            $canonicalRaw = $hasCanonicalMapping ? $this->canonicalValue($user, $canonicalArea, (string) $canonicalField) : null;

            $legacyNormalized = $this->normalizeValue($legacyRaw, $field);
            $canonicalNormalized = $this->normalizeValue($canonicalRaw, $field);

            if ($legacyNormalized['present']) {
                $result['legacy_non_empty_count']++;
            }

            if ($canonicalNormalized['present']) {
                $result['canonical_non_empty_count']++;
            }

            if ($legacyNormalized['non_scalar'] || $canonicalNormalized['non_scalar']) {
                $result['non_scalar_review_count']++;
                $this->appendSampleDifference(
                    $result['sample_differences'],
                    (string) $user->getKey(),
                    $legacyNormalized['display'],
                    $canonicalNormalized['display'],
                    'non_scalar_review',
                );

                continue;
            }

            if (!$legacyNormalized['present'] && !$canonicalNormalized['present']) {
                $result['empty_both_count']++;

                continue;
            }

            if ($legacyNormalized['present'] && !$canonicalNormalized['present']) {
                $result['legacy_only_count']++;
                $this->appendSampleDifference(
                    $result['sample_differences'],
                    (string) $user->getKey(),
                    $legacyNormalized['display'],
                    null,
                    'legacy_only',
                );

                continue;
            }

            if (!$legacyNormalized['present'] && $canonicalNormalized['present']) {
                $result['canonical_only_count']++;
                $this->appendSampleDifference(
                    $result['sample_differences'],
                    (string) $user->getKey(),
                    null,
                    $canonicalNormalized['display'],
                    'canonical_only',
                );

                continue;
            }

            if ($legacyNormalized['comparable'] === $canonicalNormalized['comparable']) {
                $result['matching_non_empty_count']++;

                continue;
            }

            $result['divergent_count']++;
            $this->appendSampleDifference(
                $result['sample_differences'],
                (string) $user->getKey(),
                $legacyNormalized['display'],
                $canonicalNormalized['display'],
                'divergent',
            );
        }

        $result['readiness_status'] = $this->resolveReadinessStatus(
            $result,
            $hasCanonicalMapping,
        );

        $result['risk'] = $this->resolveReportedRisk(
            $entry['risk'],
            (string) $result['readiness_status'],
            !$hasCanonicalMapping,
        );

        return $result;
    }

    /**
     * @param array<string,bool> $dadosPessoaisColumns
     * @param array<string,bool> $dadosConfiguracaoColumns
     */
    private function hasCanonicalMapping(
        ?string $canonicalArea,
        ?string $canonicalField,
        array $dadosPessoaisColumns,
        array $dadosConfiguracaoColumns,
    ): bool {
        if (!is_string($canonicalArea) || $canonicalArea === '' || !is_string($canonicalField) || $canonicalField === '') {
            return false;
        }

        return match ($canonicalArea) {
            'dados_pessoais' => isset($dadosPessoaisColumns[$canonicalField]) || Schema::hasColumn('users', 'dados_pessoais'),
            'dados_configuracao' => isset($dadosConfiguracaoColumns[$canonicalField]),
            'athlete_sports_data' => Schema::hasTable('athlete_sports_data') && Schema::hasColumn('athlete_sports_data', $canonicalField),
            default => false,
        } || $this->hasCanonicalContractDefinition($canonicalArea, $canonicalField);
    }

    private function hasCanonicalContractDefinition(?string $canonicalArea, ?string $canonicalField): bool
    {
        if (!is_string($canonicalArea) || $canonicalArea === '' || !is_string($canonicalField) || $canonicalField === '') {
            return false;
        }

        $targetsConfig = config('member_user_legacy_canonical_targets');
        $fields = is_array($targetsConfig['fields'] ?? null) ? $targetsConfig['fields'] : [];

        foreach ($fields as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $targetArea = is_string($definition['target_area'] ?? null) ? trim((string) $definition['target_area']) : '';
            $targetField = is_string($definition['target_field'] ?? null) ? trim((string) $definition['target_field']) : '';
            $targetStatus = is_string($definition['target_status'] ?? null) ? trim((string) $definition['target_status']) : '';

            if ($targetArea !== $canonicalArea || $targetField !== $canonicalField) {
                continue;
            }

            return in_array($targetStatus, ['canonical_payload_key_defined', 'canonical_domain_target_defined'], true);
        }

        return false;
    }

    private function canonicalValue(User $user, ?string $canonicalArea, string $canonicalField): mixed
    {
        if ($canonicalArea === 'dados_pessoais') {
            $fromTable = $user->dadosPessoais?->getAttribute($canonicalField);
            if ($fromTable !== null && $fromTable !== '') {
                return $fromTable;
            }

            return $this->payloadValueFromUser($user, 'dados_pessoais', $canonicalField);
        }

        if ($canonicalArea === 'dados_configuracao') {
            return $user->dadosConfiguracao?->getAttribute($canonicalField);
        }

        if ($canonicalArea === 'athlete_sports_data') {
            return $user->athleteSportsData?->getAttribute($canonicalField);
        }

        return null;
    }

    private function payloadValueFromUser(User $user, string $payloadColumn, string $key): mixed
    {
        $raw = $user->getAttribute($payloadColumn);

        if (is_array($raw)) {
            return $raw[$key] ?? null;
        }

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded[$key] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array{present:bool,non_scalar:bool,comparable:string|null,display:string|null}
     */
    private function normalizeValue(mixed $value, string $field): array
    {
        if ($value === null) {
            return [
                'present' => false,
                'non_scalar' => false,
                'comparable' => null,
                'display' => null,
            ];
        }

        if ($value instanceof DateTimeInterface) {
            $normalized = $value->format('Y-m-d');

            return [
                'present' => true,
                'non_scalar' => false,
                'comparable' => 'date:' . $normalized,
                'display' => $normalized,
            ];
        }

        if (is_array($value) || is_object($value)) {
            return [
                'present' => true,
                'non_scalar' => true,
                'comparable' => null,
                'display' => '[non-scalar]',
            ];
        }

        if (is_bool($value) && $this->isBooleanField($field)) {
            return [
                'present' => true,
                'non_scalar' => false,
                'comparable' => 'bool:' . ($value ? '1' : '0'),
                'display' => $value ? 'true' : 'false',
            ];
        }

        if (is_int($value) || is_float($value)) {
            if ($this->isBooleanField($field) && in_array($value, [0, 1, 0.0, 1.0], true)) {
                return [
                    'present' => true,
                    'non_scalar' => false,
                    'comparable' => 'bool:' . ((float) $value === 1.0 ? '1' : '0'),
                    'display' => (float) $value === 1.0 ? 'true' : 'false',
                ];
            }

            $stringValue = (string) $value;

            return [
                'present' => true,
                'non_scalar' => false,
                'comparable' => 'scalar:' . $stringValue,
                'display' => $stringValue,
            ];
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return [
                'present' => false,
                'non_scalar' => false,
                'comparable' => null,
                'display' => null,
            ];
        }

        $decoded = json_decode($stringValue, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            return [
                'present' => true,
                'non_scalar' => true,
                'comparable' => null,
                'display' => '[non-scalar]',
            ];
        }

        if ($this->isDateField($field)) {
            $normalizedDate = $this->normalizeDateString($stringValue);
            if ($normalizedDate !== null) {
                return [
                    'present' => true,
                    'non_scalar' => false,
                    'comparable' => 'date:' . $normalizedDate,
                    'display' => $normalizedDate,
                ];
            }
        }

        if ($this->isBooleanField($field)) {
            $normalizedBoolean = $this->normalizeBooleanString($stringValue);
            if ($normalizedBoolean !== null) {
                return [
                    'present' => true,
                    'non_scalar' => false,
                    'comparable' => 'bool:' . $normalizedBoolean,
                    'display' => $normalizedBoolean === '1' ? 'true' : 'false',
                ];
            }
        }

        return [
            'present' => true,
            'non_scalar' => false,
            'comparable' => 'scalar:' . $stringValue,
            'display' => $stringValue,
        ];
    }

    private function isBooleanField(string $field): bool
    {
        return in_array($field, self::BOOLEAN_FIELDS, true);
    }

    private function isDateField(string $field): bool
    {
        return in_array($field, self::DATE_FIELDS, true);
    }

    private function normalizeDateString(string $value): ?string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'Y-m-d H:i:s', DATE_ATOM, DATE_RFC3339];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeBooleanString(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true)) {
            return '1';
        }

        if (in_array($normalized, ['0', 'false', 'no', 'nao', 'não', 'off'], true)) {
            return '0';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function resolveReadinessStatus(array $result, bool $hasCanonicalMapping): string
    {
        if (!$hasCanonicalMapping) {
            return 'needs_manual_review';
        }

        if (((int) ($result['divergent_count'] ?? 0)) > 0 || ((int) ($result['non_scalar_review_count'] ?? 0)) > 0) {
            return 'needs_manual_review';
        }

        if (((int) ($result['legacy_only_count'] ?? 0)) > 0) {
            return 'needs_backfill';
        }

        return 'ready_for_cleanup';
    }

    private function resolveReportedRisk(string $configuredRisk, string $readinessStatus, bool $hasMappingIssue): string
    {
        if ($hasMappingIssue || $readinessStatus === 'needs_manual_review') {
            return 'high';
        }

        if ($readinessStatus === 'needs_backfill') {
            return 'medium';
        }

        return $this->normalizeRisk($configuredRisk);
    }

    private function normalizeRisk(string $risk): string
    {
        $normalized = trim(mb_strtolower($risk));

        return in_array($normalized, ['low', 'medium', 'high'], true)
            ? $normalized
            : 'medium';
    }

    /**
     * @param list<array<string,mixed>> $samples
     */
    private function appendSampleDifference(
        array &$samples,
        string $userId,
        ?string $legacyValue,
        ?string $canonicalValue,
        string $classification,
    ): void {
        if (count($samples) >= self::MAX_SAMPLE_DIFFERENCES) {
            return;
        }

        $samples[] = [
            'user_id' => $userId,
            'legacy_value' => $legacyValue,
            'canonical_value' => $canonicalValue,
            'classification' => $classification,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function schemaLookup(string $table): array
    {
        return array_fill_keys(Schema::getColumnListing($table), true);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countByKey(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = is_string($row[$key] ?? null) ? trim((string) $row[$key]) : 'unknown';
            $normalizedValue = $value !== '' ? $value : 'unknown';
            $counts[$normalizedValue] = (int) ($counts[$normalizedValue] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}