<?php

declare(strict_types=1);

namespace App\Services\Members;

final class UsersLegacyCanonicalTargetDecisionAuditor
{
    private const VERSION = 'M4.17';

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'data_atestado_medico',
        'estado_civil',
        'numero_irmaos',
    ];

    /** @var list<string> */
    private const KNOWN_TARGET_STATUSES = [
        'architecture_decision_required',
        'canonical_payload_key_pending',
        'canonical_payload_key_defined',
        'canonical_domain_target_defined',
    ];

    public function __construct(
        private readonly UsersLegacyOnlyBackfillPreflightService $preflightService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $fieldFilter = null): array
    {
        $fieldFilter = $fieldFilter !== null ? trim($fieldFilter) : null;

        if ($fieldFilter !== null && $fieldFilter !== '' && !in_array($fieldFilter, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Campo invalido para auditoria canonical targets: %s', $fieldFilter));
        }

        $preflight = $this->preflightService->preflight($fieldFilter !== '' ? $fieldFilter : null);
        $preflightFields = is_array($preflight['fields'] ?? null) ? $preflight['fields'] : [];

        $config = config('member_user_legacy_canonical_targets');
        $configVersion = is_string($config['version'] ?? null) ? trim((string) $config['version']) : self::VERSION;
        $configFields = is_array($config['fields'] ?? null) ? $config['fields'] : [];

        $rows = [];
        foreach ($preflightFields as $preflightField) {
            if (!is_array($preflightField)) {
                continue;
            }

            $field = is_string($preflightField['field'] ?? null) ? trim((string) $preflightField['field']) : '';
            if ($field === '') {
                continue;
            }

            $decisionConfig = is_array($configFields[$field] ?? null) ? $configFields[$field] : null;
            $hasExplicitDecision = $decisionConfig !== null;

            $targetStatus = $this->stringOrNull($decisionConfig['target_status'] ?? null);
            $unknownTargetStatus = $targetStatus !== null && !in_array($targetStatus, self::KNOWN_TARGET_STATUSES, true);
            $writeAllowed = (bool) ($decisionConfig['write_allowed'] ?? false);

            $rows[] = [
                'field' => $field,
                'legacy_field' => (string) ($preflightField['legacy_field'] ?? ''),
                'proposed_canonical_area' => (string) ($preflightField['proposed_canonical_area'] ?? ''),
                'proposed_canonical_field' => (string) ($preflightField['proposed_canonical_field'] ?? ''),
                'legacy_only_count' => (int) ($preflightField['legacy_only_count'] ?? 0),
                'divergent_count' => (int) ($preflightField['divergent_count'] ?? 0),
                'target_area' => $this->stringOrNull($decisionConfig['target_area'] ?? null),
                'target_field' => $this->stringOrNull($decisionConfig['target_field'] ?? null),
                'target_status' => $targetStatus,
                'decision' => $this->stringOrNull($decisionConfig['decision'] ?? null),
                'write_allowed' => $writeAllowed,
                'owner_area' => $this->stringOrNull($decisionConfig['owner_area'] ?? null),
                'reason' => $this->stringOrNull($decisionConfig['reason'] ?? null),
                'next_action' => $this->stringOrNull($decisionConfig['next_action'] ?? null),
                'has_explicit_decision' => $hasExplicitDecision,
                'missing_decision' => !$hasExplicitDecision,
                'unknown_target_status' => $unknownTargetStatus,
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) $left['field'], (string) $right['field']));

        $summary = [
            'fields_analyzed' => count($rows),
            'explicit_decisions_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['has_explicit_decision'] ?? false))),
            'missing_decisions_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['missing_decision'] ?? false))),
            'write_allowed_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['write_allowed'] ?? false))),
            'blocked_write_count' => count(array_filter($rows, static fn (array $row): bool => !(bool) ($row['write_allowed'] ?? false))),
            'architecture_decision_required_count' => count(array_filter($rows, static fn (array $row): bool => ($row['target_status'] ?? null) === 'architecture_decision_required')),
            'canonical_payload_key_pending_count' => count(array_filter($rows, static fn (array $row): bool => ($row['target_status'] ?? null) === 'canonical_payload_key_pending')),
            'canonical_payload_key_defined_count' => count(array_filter($rows, static fn (array $row): bool => ($row['target_status'] ?? null) === 'canonical_payload_key_defined')),
            'canonical_domain_target_defined_count' => count(array_filter($rows, static fn (array $row): bool => ($row['target_status'] ?? null) === 'canonical_domain_target_defined')),
            'unknown_target_status_count' => count(array_filter($rows, static fn (array $row): bool => (bool) ($row['unknown_target_status'] ?? false))),
            'passed' => true,
            'failure_reason' => null,
        ];

        if ((int) $summary['missing_decisions_count'] > 0) {
            $summary['passed'] = false;
            $summary['failure_reason'] = 'Existem campos bloqueados sem decisao explicita no config M4.17.';
        } elseif ((int) $summary['unknown_target_status_count'] > 0) {
            $summary['passed'] = false;
            $summary['failure_reason'] = 'Existem campos com target_status desconhecido no config M4.17.';
        }

        return [
            'version' => self::VERSION,
            'config_version' => $configVersion !== '' ? $configVersion : self::VERSION,
            'preflight_version' => (string) ($preflight['version'] ?? self::VERSION),
            'summary' => $summary,
            'fields' => $rows,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}