<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AthleteSportsData;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class UsersLegacyOnlyBackfillPreflightService
{
    private const VERSION = 'M4.14';

    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'data_atestado_medico',
        'estado_civil',
        'numero_irmaos',
    ];

    /** @return array<string,mixed> */
    public function preflight(?string $fieldFilter = null): array
    {
        $fieldFilter = $fieldFilter !== null ? trim($fieldFilter) : null;

        if ($fieldFilter !== null && $fieldFilter !== '' && !in_array($fieldFilter, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Campo invalido para preflight: %s', $fieldFilter));
        }

        $fieldDefinitions = array_values(array_filter(
            $this->fieldDefinitions(),
            static fn (array $definition): bool => $fieldFilter === null || $fieldFilter === '' || $definition['field'] === $fieldFilter,
        ));

        $users = User::query()
            ->select(['id', 'data_atestado_medico', 'estado_civil', 'numero_irmaos'])
            ->with(['athleteSportsData:id,user_id,data_atestado_medico'])
            ->orderBy('id')
            ->get();

        $fields = [];
        foreach ($fieldDefinitions as $definition) {
            $fields[] = $this->analyzeField($definition, $users->all());
        }

        usort($fields, static fn (array $left, array $right): int => strcmp((string) $left['field'], (string) $right['field']));

        return [
            'version' => self::VERSION,
            'mode' => 'preflight',
            'writable' => false,
            'commit_allowed' => false,
            'fields' => $fields,
            'summary' => [
                'fields_analyzed' => count($fields),
                'total_legacy_only_count' => array_sum(array_map(static fn (array $field): int => (int) ($field['legacy_only_count'] ?? 0), $fields)),
                'total_divergent_count' => array_sum(array_map(static fn (array $field): int => (int) ($field['divergent_count'] ?? 0), $fields)),
                'fields_with_missing_canonical_target' => count(array_filter($fields, static fn (array $field): bool => ($field['canonical_target_status'] ?? null) === 'canonical_target_missing_or_not_versioned')),
                'fields_requiring_architecture_decision' => count(array_filter($fields, static fn (array $field): bool => ($field['canonical_target_status'] ?? null) === 'canonical_target_requires_architecture_decision')),
                'passed' => true,
                'failure_reason' => null,
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fieldDefinitions(): array
    {
        return [
            [
                'field' => 'data_atestado_medico',
                'legacy_field' => 'users.data_atestado_medico',
                'proposed_canonical_area' => 'athlete_sports_data',
                'proposed_canonical_field' => 'data_atestado_medico',
                'canonical_target_status' => $this->canonicalTargetStatus('athlete_sports_data', 'data_atestado_medico'),
                'recommended_next_step' => 'Definir arquitetura canónica para o destino desportivo antes de qualquer backfill.',
                'commit_blocked_reason' => 'Commit bloqueado: o destino canónico real ainda exige decisão de arquitetura.',
            ],
            [
                'field' => 'estado_civil',
                'legacy_field' => 'users.estado_civil',
                'proposed_canonical_area' => 'dados_pessoais',
                'proposed_canonical_field' => 'estado_civil',
                'canonical_target_status' => $this->canonicalTargetStatus('dados_pessoais', 'estado_civil'),
                'recommended_next_step' => 'Versionar o campo em dados_pessoais antes de qualquer escrita canónica.',
                'commit_blocked_reason' => 'Commit bloqueado: destino canónico ausente ou ainda não versionado.',
            ],
            [
                'field' => 'numero_irmaos',
                'legacy_field' => 'users.numero_irmaos',
                'proposed_canonical_area' => 'dados_pessoais',
                'proposed_canonical_field' => 'numero_irmaos',
                'canonical_target_status' => $this->canonicalTargetStatus('dados_pessoais', 'numero_irmaos'),
                'recommended_next_step' => 'Versionar o campo em dados_pessoais antes de qualquer escrita canónica.',
                'commit_blocked_reason' => 'Commit bloqueado: destino canónico ausente ou ainda não versionado.',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @param list<User> $users
     * @return array<string,mixed>
     */
    private function analyzeField(array $definition, array $users): array
    {
        $field = (string) $definition['field'];
        $legacyOnlyCount = 0;
        $divergentCount = 0;

        foreach ($users as $user) {
            if ($field === 'data_atestado_medico') {
                $legacyValue = $this->normalizeDate($user->getAttribute('data_atestado_medico'));
                $canonicalValue = $this->normalizeDate($user->athleteSportsData?->getAttribute('data_atestado_medico'));

                if (!$this->hasValue($legacyValue)) {
                    continue;
                }

                if (!$this->hasValue($canonicalValue)) {
                    $legacyOnlyCount++;

                    continue;
                }

                if ($legacyValue !== $canonicalValue) {
                    $divergentCount++;
                }

                continue;
            }

            $legacyValue = $this->normalizeScalar($user->getAttribute($field));

            if (!$this->hasValue($legacyValue)) {
                continue;
            }

            $legacyOnlyCount++;
        }

        return [
            'field' => $field,
            'legacy_field' => $definition['legacy_field'],
            'proposed_canonical_area' => $definition['proposed_canonical_area'],
            'proposed_canonical_field' => $definition['proposed_canonical_field'],
            'canonical_target_status' => $definition['canonical_target_status'],
            'legacy_only_count' => $legacyOnlyCount,
            'divergent_count' => $divergentCount,
            'commit_blocked_reason' => $definition['commit_blocked_reason'],
            'recommended_next_step' => $definition['recommended_next_step'],
        ];
    }

    private function canonicalTargetStatus(string $area, string $field): string
    {
        if ($area === 'athlete_sports_data' && $field === 'data_atestado_medico') {
            return Schema::hasTable('athlete_sports_data') && Schema::hasColumn('athlete_sports_data', 'data_atestado_medico')
                ? 'canonical_target_requires_architecture_decision'
                : 'canonical_target_missing_or_not_versioned';
        }

        if (Schema::hasTable($area) && Schema::hasColumn($area, $field)) {
            return 'canonical_target_requires_architecture_decision';
        }

        return 'canonical_target_missing_or_not_versioned';
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            try {
                return \Illuminate\Support\Carbon::parse($trimmed)->toDateString();
            } catch (\Throwable) {
                return $trimmed;
            }
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $this->normalizeScalar($value);
    }
}