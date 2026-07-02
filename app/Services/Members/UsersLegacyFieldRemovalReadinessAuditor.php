<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\Schema;
use ReflectionClass;

final class UsersLegacyFieldRemovalReadinessAuditor
{
    private const VERSION = 'M4.11';

    public function __construct(
        private readonly UsersLegacyWriteGuardScanner $writeGuardScanner,
        private readonly UsersLegacyReadScanner $readScanner,
    ) {
    }

    /**
     * @return array{
     *   version:string,
     *   summary:array<string,mixed>,
     *   fields:list<array<string,mixed>>,
     *   grouped_summary:array<string,array<string,int>>
     * }
     */
    public function audit(): array
    {
        $config = config('member_user_legacy_fields');
        $fieldToCategory = is_array($config['field_to_category'] ?? null) ? $config['field_to_category'] : [];
        $categories = is_array($config['categories'] ?? null) ? $config['categories'] : [];

        $schemaColumns = array_values(array_unique(Schema::getColumnListing('users')));
        sort($schemaColumns);
        $schemaLookup = array_fill_keys($schemaColumns, true);

        $blockedFields = $this->writeGuardScanner->blockedFields();
        $blockedLookup = array_fill_keys($blockedFields, true);

        $readAudit = $this->readScanner->scan(null, $this->readScanner->defaultAllowlist());
        $legacyReadCountByField = $this->legacyReadCountByField($readAudit['findings'] ?? []);

        $personalMap = $this->fallbackUsersFieldToCanonicalMap('PERSONAL_FALLBACK_MAP');
        $configurationMap = $this->fallbackUsersFieldToCanonicalMap('CONFIGURATION_FALLBACK_MAP');

        $fields = [];
        foreach ($fieldToCategory as $field => $category) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            $normalizedField = trim($field);
            $normalizedCategory = is_string($category) ? trim($category) : 'unknown_review';
            if ($normalizedCategory === '') {
                $normalizedCategory = 'unknown_review';
            }

            $existsInSchema = isset($schemaLookup[$normalizedField]);
            $blockedForLegacyWrite = isset($blockedLookup[$normalizedField]);
            $legacyReadFindingsCount = (int) ($legacyReadCountByField[$normalizedField] ?? 0);

            [$canonicalArea, $canonicalField, $canonicalDetails] = $this->resolveCanonicalTarget(
                $normalizedField,
                $normalizedCategory,
                $personalMap,
                $configurationMap,
            );

            [$removalStatus, $risk, $classificationNotes, $unknownJustified] = $this->classifyField(
                $normalizedCategory,
                $existsInSchema,
                $blockedForLegacyWrite,
                $legacyReadFindingsCount,
                $canonicalArea,
                $canonicalField,
                (string) ($categories[$normalizedCategory]['description'] ?? ''),
            );

            $notesParts = array_filter([
                $classificationNotes,
                $canonicalDetails,
            ], static fn (string $value): bool => trim($value) !== '');

            $fields[] = [
                'field' => $normalizedField,
                'category' => $normalizedCategory,
                'exists_in_users_schema' => $existsInSchema,
                'blocked_for_legacy_write' => $blockedForLegacyWrite,
                'legacy_read_findings_count' => $legacyReadFindingsCount,
                'canonical_area' => $canonicalArea,
                'canonical_field' => $canonicalField,
                'removal_status' => $removalStatus,
                'risk' => $risk,
                'notes' => implode(' ', $notesParts),
                'unknown_justified' => $unknownJustified,
            ];
        }

        usort($fields, static fn (array $left, array $right): int => strcmp((string) $left['field'], (string) $right['field']));

        $configuredFields = array_keys($fieldToCategory);
        $unclassifiedSchemaFields = array_values(array_diff($schemaColumns, $configuredFields));
        sort($unclassifiedSchemaFields);

        $summary = [
            'total_configured_fields' => count($fields),
            'fields_existing_in_schema' => count(array_filter($fields, static fn (array $row): bool => (bool) ($row['exists_in_users_schema'] ?? false))),
            'fields_not_in_schema' => count(array_filter($fields, static fn (array $row): bool => !((bool) ($row['exists_in_users_schema'] ?? false)))),
            'candidate_after_legacy_write_cleanup_count' => count(array_filter($fields, static fn (array $row): bool => ($row['removal_status'] ?? null) === 'candidate_after_legacy_write_cleanup')),
            'keep_operational_count' => count(array_filter($fields, static fn (array $row): bool => ($row['removal_status'] ?? null) === 'keep_operational')),
            'needs_review_count' => count(array_filter($fields, static fn (array $row): bool => ($row['removal_status'] ?? null) === 'needs_review')),
            'unknown_count' => count(array_filter($fields, static fn (array $row): bool => ($row['category'] ?? null) === 'unknown_review' || ($row['canonical_area'] ?? null) === 'unknown')),
            'active_legacy_read_fields_count' => count(array_filter($fields, static fn (array $row): bool => ((int) ($row['legacy_read_findings_count'] ?? 0)) > 0)),
            'unknown_without_justification_count' => count(array_filter($fields, static fn (array $row): bool => ($row['canonical_area'] ?? null) === 'unknown' && !((bool) ($row['unknown_justified'] ?? false)))),
            'unclassified_schema_fields_count' => count($unclassifiedSchemaFields),
            'unclassified_schema_fields' => $unclassifiedSchemaFields,
        ];

        return [
            'version' => self::VERSION,
            'summary' => $summary,
            'fields' => $fields,
            'grouped_summary' => [
                'by_category' => $this->countByKey($fields, 'category'),
                'by_removal_status' => $this->countByKey($fields, 'removal_status'),
                'by_risk' => $this->countByKey($fields, 'risk'),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function legacyReadCountByField(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $field = is_string($finding['field'] ?? null) ? trim((string) $finding['field']) : '';
            if ($field === '') {
                continue;
            }

            $counts[$field] = (int) ($counts[$field] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{0:string,1:string|null,2:string}
     */
    private function resolveCanonicalTarget(
        string $field,
        string $category,
        array $personalMap,
        array $configurationMap,
    ): array {
        if (isset($personalMap[$field])) {
            $targets = $personalMap[$field];
            sort($targets);

            return [
                'dados_pessoais',
                $targets[0] ?? null,
                count($targets) > 1
                    ? 'Mapa canónico em dados_pessoais com múltiplos destinos: ' . implode(', ', $targets) . '.'
                    : 'Mapa canónico em dados_pessoais identificado.',
            ];
        }

        if (isset($configurationMap[$field])) {
            $targets = $configurationMap[$field];
            sort($targets);

            return [
                'dados_configuracao',
                $targets[0] ?? null,
                count($targets) > 1
                    ? 'Mapa canónico em dados_configuracao com múltiplos destinos: ' . implode(', ', $targets) . '.'
                    : 'Mapa canónico em dados_configuracao identificado.',
            ];
        }

        if (in_array($category, ['auth_operational_keep', 'sports_operational_keep', 'relationship_family_operational_keep'], true)) {
            return ['operational_keep', null, 'Campo operacional sem alvo canónico de remoção nesta fase.'];
        }

        return ['unknown', null, 'Sem mapeamento canónico explícito no MemberDataReadService.'];
    }

    /**
     * @return array{0:string,1:string,2:string,3:bool}
     */
    private function classifyField(
        string $category,
        bool $existsInSchema,
        bool $blockedForLegacyWrite,
        int $legacyReadFindingsCount,
        string $canonicalArea,
        ?string $canonicalField,
        string $categoryDescription,
    ): array {
        if (!$existsInSchema) {
            return [
                'not_in_schema',
                'low',
                'Campo configurado mas ausente no schema users.',
                true,
            ];
        }

        if (in_array($category, ['auth_operational_keep', 'sports_operational_keep', 'relationship_family_operational_keep'], true)) {
            $notes = 'Campo operacional e crítico para runtime atual; manter nesta fase.';
            if ($legacyReadFindingsCount > 0) {
                $notes .= ' Existe leitura legacy ativa para este campo e remoção teria impacto imediato.';
            }

            return ['keep_operational', 'high', $notes, true];
        }

        if (in_array($category, ['member_personal_legacy', 'member_configuration_legacy'], true)) {
            if (
                in_array($canonicalArea, ['dados_pessoais', 'dados_configuracao'], true)
                && $canonicalField !== null
                && $blockedForLegacyWrite
                && $legacyReadFindingsCount === 0
            ) {
                return [
                    'candidate_after_legacy_write_cleanup',
                    'medium',
                    'Campo legacy com equivalente canónico conhecido, sem escrita legacy e sem leitura legacy ativa.',
                    true,
                ];
            }

            $risk = ($legacyReadFindingsCount > 0 || !$blockedForLegacyWrite) ? 'high' : 'medium';

            return [
                'needs_review',
                $risk,
                'Campo legacy pessoal/configuração ainda precisa de validação antes de remoção/isolamento.',
                false,
            ];
        }

        if ($category === 'member_financial_legacy') {
            return [
                'needs_review',
                'high',
                'Campo financeiro legacy deve ser tratado com revisão funcional dedicada antes de decisão.',
                true,
            ];
        }

        if ($category === 'files_or_historical_review') {
            return [
                'needs_review',
                'medium',
                'Campo de ficheiro/histórico requer validação de retenção e estratégia de arquivo.',
                true,
            ];
        }

        if ($category === 'unknown_review') {
            $notes = 'Campo marcado em unknown_review no inventário técnico e requer classificação futura.';
            if (trim($categoryDescription) !== '') {
                $notes .= ' Contexto: ' . trim($categoryDescription);
            }

            return [
                'needs_review',
                'medium',
                $notes,
                true,
            ];
        }

        return [
            'needs_review',
            'high',
            'Categoria não reconhecida no inventário M4.6-F2; revisar classificação.',
            false,
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    private function fallbackUsersFieldToCanonicalMap(string $constantName): array
    {
        $reflection = new ReflectionClass(MemberDataReadService::class);
        $rawMap = $reflection->getConstant($constantName);

        if (!is_array($rawMap)) {
            return [];
        }

        $resolved = [];

        foreach ($rawMap as $canonicalField => $fallbackDefinition) {
            if (!is_string($canonicalField) || trim($canonicalField) === '') {
                continue;
            }

            $fallbackFields = is_array($fallbackDefinition)
                ? $fallbackDefinition
                : ($fallbackDefinition === null ? [] : [$fallbackDefinition]);

            foreach ($fallbackFields as $fallbackField) {
                if (!is_string($fallbackField) || trim($fallbackField) === '') {
                    continue;
                }

                $field = trim($fallbackField);
                $resolved[$field] ??= [];
                $resolved[$field][] = trim($canonicalField);
            }
        }

        foreach ($resolved as $field => $targets) {
            $targets = array_values(array_unique(array_filter($targets, static fn (string $target): bool => trim($target) !== '')));
            sort($targets);
            $resolved[$field] = $targets;
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countByKey(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = is_string($row[$key] ?? null) && trim((string) $row[$key]) !== ''
                ? trim((string) $row[$key])
                : 'unknown';

            $counts[$value] = (int) ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }
}
