<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\Schema;
use ReflectionClass;

final class UsersLegacyFieldRemovalReadinessAuditor
{
    private const VERSION = 'M4.12';

    /** @var list<string> */
    private const ALLOWED_DECISIONS = [
        'keep_operational_explicit',
        'candidate_after_legacy_write_cleanup',
        'candidate_after_backfill_validation',
        'needs_business_decision',
        'keep_historical_or_external_reference',
        'keep_until_module_refactor',
        'not_in_schema',
        'unclassified',
    ];

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
        $decisionsConfig = config('member_user_legacy_removal_decisions');
        $fieldToCategory = is_array($config['field_to_category'] ?? null) ? $config['field_to_category'] : [];
        $categories = is_array($config['categories'] ?? null) ? $config['categories'] : [];
        $explicitDecisionsByField = is_array($decisionsConfig['fields'] ?? null) ? $decisionsConfig['fields'] : [];

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

            [$decision, $risk, $ownerArea, $classificationReason, $nextAction] = $this->inferDecision(
                $normalizedCategory,
                $existsInSchema,
                $blockedForLegacyWrite,
                $legacyReadFindingsCount,
                $canonicalArea,
                $canonicalField,
                (string) ($categories[$normalizedCategory]['description'] ?? ''),
            );

            $decisionSource = 'inferred';
            $explicitConfig = is_array($explicitDecisionsByField[$normalizedField] ?? null)
                ? $explicitDecisionsByField[$normalizedField]
                : null;

            if ($explicitConfig !== null) {
                [$decision, $risk, $ownerArea, $canonicalArea, $canonicalField, $classificationReason, $nextAction] = $this->applyExplicitDecision(
                    $decision,
                    $risk,
                    $ownerArea,
                    $canonicalArea,
                    $canonicalField,
                    $classificationReason,
                    $nextAction,
                    $explicitConfig,
                );
                $decisionSource = 'explicit_config';
            }

            $notesParts = array_filter([
                $classificationReason,
                $canonicalDetails,
            ], static fn (string $value): bool => trim($value) !== '');

            $isUnclassified = $decision === 'unclassified';

            $fields[] = [
                'field' => $normalizedField,
                'category' => $normalizedCategory,
                'exists_in_users_schema' => $existsInSchema,
                'blocked_for_legacy_write' => $blockedForLegacyWrite,
                'legacy_read_findings_count' => $legacyReadFindingsCount,
                'canonical_area' => $canonicalArea,
                'canonical_field' => $canonicalField,
                'decision' => $decision,
                'decision_source' => $decisionSource,
                'owner_area' => $ownerArea,
                'reason' => $classificationReason,
                'next_action' => $nextAction,
                'removal_status' => $decision,
                'risk' => $risk,
                'notes' => implode(' ', $notesParts),
                'unknown_justified' => !$isUnclassified,
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
            'candidate_after_legacy_write_cleanup_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'candidate_after_legacy_write_cleanup')),
            'keep_operational_count' => count(array_filter($fields, static fn (array $row): bool => in_array(($row['decision'] ?? null), ['keep_operational', 'keep_operational_explicit'], true))),
            'needs_review_count' => count(array_filter($fields, fn (array $row): bool => $this->isNeedsReviewDecision((string) ($row['decision'] ?? '')))),
            'needs_business_decision_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'needs_business_decision')),
            'keep_until_module_refactor_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'keep_until_module_refactor')),
            'keep_historical_or_external_reference_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'keep_historical_or_external_reference')),
            'explicit_decisions_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision_source'] ?? null) === 'explicit_config')),
            'inferred_decisions_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision_source'] ?? null) === 'inferred')),
            'unclassified_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'unclassified')),
            'unknown_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'unclassified')),
            'active_legacy_read_fields_count' => count(array_filter($fields, static fn (array $row): bool => ((int) ($row['legacy_read_findings_count'] ?? 0)) > 0)),
            'unknown_without_justification_count' => count(array_filter($fields, static fn (array $row): bool => ($row['decision'] ?? null) === 'unclassified')),
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
                'by_decision' => $this->countByKey($fields, 'decision'),
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
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function inferDecision(
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
                'inventario',
                'Campo configurado mas ausente no schema users.',
                'Confirmar se deve ser removido do inventario tecnico ou se existe divergencia de schema.',
            ];
        }

        if (in_array($category, ['auth_operational_keep', 'sports_operational_keep', 'relationship_family_operational_keep'], true)) {
            $notes = 'Campo operacional e crítico para runtime atual; manter nesta fase.';
            if ($legacyReadFindingsCount > 0) {
                $notes .= ' Existe leitura legacy ativa para este campo e remoção teria impacto imediato.';
            }

            return [
                'keep_operational_explicit',
                'high',
                $category === 'auth_operational_keep' ? 'auth' : ($category === 'sports_operational_keep' ? 'desportivo' : 'membros'),
                $notes,
                'Manter no users ate existir substituicao operacional totalmente validada.',
            ];
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
                    'membros',
                    'Campo legacy com equivalente canónico conhecido, sem escrita legacy e sem leitura legacy ativa.',
                    'Manter observabilidade e validar estabilidade antes de isolamento por lote.',
                ];
            }
            return [
                'candidate_after_backfill_validation',
                'medium',
                'membros',
                'Campo legacy pessoal/configuração sem fecho canónico completo; exige validacao de backfill e cobertura.',
                'Validar completude dos dados canónicos e dependencia residual antes de isolamento.',
            ];
        }

        if ($category === 'member_financial_legacy') {
            return [
                'keep_until_module_refactor',
                'high',
                'financeiro',
                'Campo financeiro legacy deve permanecer ate refatoracao dedicada do modulo financeiro.',
                'Mapear dependencias e definir destino canónico antes de qualquer isolamento.',
            ];
        }

        if ($category === 'files_or_historical_review') {
            return [
                'keep_historical_or_external_reference',
                'medium',
                'documentos',
                'Campo de ficheiro/historico requer validacao de retencao e estrategia de arquivo.',
                'Confirmar politica de retencao e referencia externa antes de isolamento.',
            ];
        }

        if ($category === 'unknown_review') {
            $notes = 'Campo marcado em unknown_review no inventário técnico e requer classificação futura.';
            if (trim($categoryDescription) !== '') {
                $notes .= ' Contexto: ' . trim($categoryDescription);
            }

            return [
                'needs_business_decision',
                'medium',
                'membros',
                $notes,
                'Definir area/campo canónico e politica de retencao antes de isolamento.',
            ];
        }

        return [
            'unclassified',
            'high',
            'unknown',
            'Categoria não reconhecida no inventário M4.6-F2; revisar classificação.',
            'Classificar explicitamente no inventario ou no mapa de decisoes M4.12.',
        ];
    }

    /**
     * @param array<string,mixed> $explicitConfig
     * @return array{0:string,1:string,2:string,3:string,4:string|null,5:string,6:string}
     */
    private function applyExplicitDecision(
        string $decision,
        string $risk,
        string $ownerArea,
        string $canonicalArea,
        ?string $canonicalField,
        string $reason,
        string $nextAction,
        array $explicitConfig,
    ): array {
        $explicitDecision = is_string($explicitConfig['decision'] ?? null)
            ? trim((string) $explicitConfig['decision'])
            : '';
        if ($explicitDecision !== '' && in_array($explicitDecision, self::ALLOWED_DECISIONS, true)) {
            $decision = $explicitDecision;
        }

        $explicitRisk = is_string($explicitConfig['risk'] ?? null) ? trim((string) $explicitConfig['risk']) : '';
        if ($explicitRisk !== '') {
            $risk = $explicitRisk;
        }

        $explicitOwnerArea = is_string($explicitConfig['owner_area'] ?? null)
            ? trim((string) $explicitConfig['owner_area'])
            : '';
        if ($explicitOwnerArea !== '') {
            $ownerArea = $explicitOwnerArea;
        }

        $explicitCanonicalArea = is_string($explicitConfig['canonical_area'] ?? null)
            ? trim((string) $explicitConfig['canonical_area'])
            : '';
        if ($explicitCanonicalArea !== '') {
            $canonicalArea = $explicitCanonicalArea;
        }

        if (array_key_exists('canonical_field', $explicitConfig)) {
            $explicitCanonicalField = $explicitConfig['canonical_field'];
            $canonicalField = is_string($explicitCanonicalField) && trim($explicitCanonicalField) !== ''
                ? trim($explicitCanonicalField)
                : null;
        }

        $explicitReason = is_string($explicitConfig['reason'] ?? null)
            ? trim((string) $explicitConfig['reason'])
            : '';
        if ($explicitReason !== '') {
            $reason = $explicitReason;
        }

        $explicitNextAction = is_string($explicitConfig['next_action'] ?? null)
            ? trim((string) $explicitConfig['next_action'])
            : '';
        if ($explicitNextAction !== '') {
            $nextAction = $explicitNextAction;
        }

        return [$decision, $risk, $ownerArea, $canonicalArea, $canonicalField, $reason, $nextAction];
    }

    private function isNeedsReviewDecision(string $decision): bool
    {
        return in_array(
            $decision,
            [
                'candidate_after_backfill_validation',
                'needs_business_decision',
                'keep_historical_or_external_reference',
                'keep_until_module_refactor',
                'unclassified',
            ],
            true,
        );
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
