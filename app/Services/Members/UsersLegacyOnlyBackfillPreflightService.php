<?php

declare(strict_types=1);

namespace App\Services\Members;

final class UsersLegacyOnlyBackfillPreflightService
{
    private const VERSION = 'M4.17';

    public function __construct(
        private readonly UsersLegacyOnlyBackfillService $backfillService,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(?string $fieldFilter = null): array
    {
        $analysis = $this->backfillService->analyze($fieldFilter);

        $fields = [];
        foreach (($analysis['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fields[] = [
                'field' => $field['field'] ?? null,
                'legacy_field' => $field['legacy_field'] ?? null,
                'proposed_canonical_area' => $field['target_area'] ?? null,
                'proposed_canonical_field' => $field['target_field'] ?? null,
                'canonical_target_status' => $this->canonicalTargetStatus(
                    (bool) ($field['target_resolvable'] ?? false),
                    (bool) ($field['write_allowed'] ?? false),
                    is_string($field['target_area'] ?? null) ? (string) $field['target_area'] : null,
                ),
                'target_status' => $field['target_status'] ?? null,
                'decision' => $field['decision'] ?? null,
                'owner_area' => $field['owner_area'] ?? null,
                'reason' => $field['reason'] ?? null,
                'next_action' => $field['next_action'] ?? null,
                'write_allowed' => (bool) ($field['write_allowed'] ?? false),
                'target_resolvable' => (bool) ($field['target_resolvable'] ?? false),
                'legacy_only_count' => (int) ($field['legacy_only_count'] ?? 0),
                'divergent_count' => (int) ($field['divergent_count'] ?? 0),
                'already_matching_count' => (int) ($field['already_matching_count'] ?? 0),
                'skipped_missing_target_count' => (int) ($field['skipped_missing_target_count'] ?? 0),
                'skipped_ambiguous_target_count' => (int) ($field['skipped_ambiguous_target_count'] ?? 0),
            ];
        }

        $summary = is_array($analysis['summary'] ?? null) ? $analysis['summary'] : [];

        return [
            'version' => self::VERSION,
            'decision_config_version' => is_string(config('member_user_legacy_canonical_targets.version'))
                ? (string) config('member_user_legacy_canonical_targets.version')
                : self::VERSION,
            'mode' => 'preflight',
            'writable' => false,
            'commit_allowed' => (bool) ($summary['commit_allowed'] ?? false),
            'fields' => $fields,
            'summary' => [
                'fields_analyzed' => (int) ($summary['fields_analyzed'] ?? 0),
                'total_legacy_only_count' => (int) ($summary['total_legacy_only_count'] ?? 0),
                'total_divergent_count' => (int) ($summary['total_divergent_count'] ?? 0),
                'fields_with_missing_canonical_target' => (int) ($summary['unresolvable_target_fields_count'] ?? 0),
                'fields_requiring_architecture_decision' => 0,
                'fields_with_defined_but_write_blocked_target' => max(
                    0,
                    (int) ($summary['fields_analyzed'] ?? 0) - (int) ($summary['write_allowed_fields_count'] ?? 0),
                ),
                'passed' => true,
                'failure_reason' => null,
            ],
        ];
    }

    private function canonicalTargetStatus(bool $targetResolvable, bool $writeAllowed, ?string $targetArea): string
    {
        if (!$targetResolvable) {
            return 'canonical_target_missing_or_not_resolvable';
        }

        if (!$writeAllowed) {
            return 'canonical_target_defined_but_write_blocked';
        }

        if ($targetArea === 'dados_pessoais') {
            return 'canonical_payload_target_ready';
        }

        return 'canonical_target_ready';
    }
}
