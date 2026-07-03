<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AthleteSportsData;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UsersLegacyOnlyBackfillService
{
    public const VERSION = 'M4.17';

    public const CONFIRM_TOKEN = 'BACKFILL_LEGACY_ONLY_FIELDS';

    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'estado_civil',
        'numero_irmaos',
        'data_atestado_medico',
    ];

    /**
     * @return array<string,mixed>
     */
    public function analyze(?string $fieldFilter = null): array
    {
        $fieldFilter = $this->normalizeFieldFilter($fieldFilter);
        $fieldsToProcess = $fieldFilter !== null ? [$fieldFilter] : self::ALLOWED_FIELDS;

        $usersHasPersonalPayload = Schema::hasColumn('users', 'dados_pessoais');
        $athleteTableReady = Schema::hasTable('athlete_sports_data')
            && Schema::hasColumn('athlete_sports_data', 'user_id')
            && Schema::hasColumn('athlete_sports_data', 'data_atestado_medico');

        $userSelect = ['id', 'estado_civil', 'numero_irmaos', 'data_atestado_medico'];
        if ($usersHasPersonalPayload) {
            $userSelect[] = 'dados_pessoais';
        }

        $users = User::query()
            ->select($userSelect)
            ->orderBy('id')
            ->get();

        $athleteRowsByUser = $this->loadAthleteRowsByUser($athleteTableReady);

        $fieldResults = [];
        foreach ($fieldsToProcess as $field) {
            $definition = $this->fieldDefinition($field);

            if ($field === 'estado_civil') {
                $fieldResults[] = $this->analyzePersonalPayloadField(
                    $users->all(),
                    $definition,
                    $usersHasPersonalPayload,
                    static fn (User $user): mixed => $user->getAttribute('estado_civil'),
                    static fn (mixed $value): mixed => self::normalizeText($value),
                );

                continue;
            }

            if ($field === 'numero_irmaos') {
                $fieldResults[] = $this->analyzePersonalPayloadField(
                    $users->all(),
                    $definition,
                    $usersHasPersonalPayload,
                    static fn (User $user): mixed => $user->getAttribute('numero_irmaos'),
                    static fn (mixed $value): mixed => self::normalizeSiblingCount($value),
                );

                continue;
            }

            $fieldResults[] = $this->analyzeAthleteMedicalDateField(
                $users->all(),
                $definition,
                $athleteTableReady,
                $athleteRowsByUser,
            );
        }

        return [
            'version' => self::VERSION,
            'fields' => $fieldResults,
            'summary' => $this->buildSummary($fieldResults),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(?string $fieldFilter = null, bool $commit = false): array
    {
        $analysis = $this->analyze($fieldFilter);
        $fields = is_array($analysis['fields'] ?? null) ? $analysis['fields'] : [];

        $results = [];
        foreach ($fields as $fieldResult) {
            if (!is_array($fieldResult)) {
                continue;
            }

            $results[] = $this->executeField((array) $fieldResult, $commit);
        }

        return [
            'version' => self::VERSION,
            'mode' => $commit ? 'commit' : 'dry-run',
            'dry_run' => !$commit,
            'committed' => $commit,
            'fields' => $results,
            'summary' => $this->buildSummary($results),
        ];
    }

    private function normalizeFieldFilter(?string $fieldFilter): ?string
    {
        if ($fieldFilter === null) {
            return null;
        }

        $normalized = trim($fieldFilter);
        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf('Campo invalido para backfill: %s', $normalized));
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldDefinition(string $field): array
    {
        $config = config('member_user_legacy_canonical_targets');
        $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];
        $fieldConfig = is_array($fields[$field] ?? null) ? $fields[$field] : [];

        return [
            'field' => $field,
            'legacy_field' => 'users.' . $field,
            'target_area' => is_string($fieldConfig['target_area'] ?? null) ? trim((string) $fieldConfig['target_area']) : null,
            'target_field' => is_string($fieldConfig['target_field'] ?? null) ? trim((string) $fieldConfig['target_field']) : null,
            'target_status' => is_string($fieldConfig['target_status'] ?? null) ? trim((string) $fieldConfig['target_status']) : null,
            'decision' => is_string($fieldConfig['decision'] ?? null) ? trim((string) $fieldConfig['decision']) : null,
            'owner_area' => is_string($fieldConfig['owner_area'] ?? null) ? trim((string) $fieldConfig['owner_area']) : null,
            'reason' => is_string($fieldConfig['reason'] ?? null) ? trim((string) $fieldConfig['reason']) : null,
            'next_action' => is_string($fieldConfig['next_action'] ?? null) ? trim((string) $fieldConfig['next_action']) : null,
            'write_allowed' => (bool) ($fieldConfig['write_allowed'] ?? false),
        ];
    }

    /**
     * @param list<User> $users
     * @param callable(User):mixed $legacyResolver
     * @param callable(mixed):mixed $normalizer
     * @return array<string,mixed>
     */
    private function analyzePersonalPayloadField(
        array $users,
        array $definition,
        bool $usersHasPersonalPayload,
        callable $legacyResolver,
        callable $normalizer,
    ): array {
        $result = $this->emptyFieldResult($definition, $usersHasPersonalPayload);

        foreach ($users as $user) {
            $legacyValue = $normalizer($legacyResolver($user));
            if (!$this->hasValue($legacyValue)) {
                $result['skipped_empty_legacy_count']++;
                continue;
            }

            $result['legacy_non_empty_count']++;
            $result['legacy_only_count']++;

            if (!$usersHasPersonalPayload) {
                $result['skipped_missing_target_count']++;
                continue;
            }

            $payload = $this->decodePersonalPayload($user->getAttribute('dados_pessoais'));
            $targetField = (string) ($definition['target_field'] ?? '');
            $canonicalRaw = $payload[$targetField] ?? null;
            $canonicalValue = $normalizer($canonicalRaw);

            if (!$this->hasValue($canonicalValue)) {
                $result['candidates_count']++;
                $result['would_update_count']++;
                $result['update_candidates'][] = [
                    'user_id' => (string) $user->getKey(),
                    'target_field' => $targetField,
                    'target_value' => $legacyValue,
                    'payload' => $payload,
                ];

                continue;
            }

            $result['skipped_existing_canonical_count']++;
            $result['legacy_only_count']--;

            if ($this->comparableValue($legacyValue) === $this->comparableValue($canonicalValue)) {
                $result['already_matching_count']++;
                continue;
            }

            $result['divergent_count']++;
        }

        return $result;
    }

    /**
     * @param list<User> $users
     * @param array<string,list<array<string,mixed>>> $athleteRowsByUser
     * @return array<string,mixed>
     */
    private function analyzeAthleteMedicalDateField(
        array $users,
        array $definition,
        bool $athleteTableReady,
        array $athleteRowsByUser,
    ): array {
        $result = $this->emptyFieldResult($definition, $athleteTableReady);

        foreach ($users as $user) {
            $legacyDate = self::normalizeDate($user->getAttribute('data_atestado_medico'));
            if (!$this->hasValue($legacyDate)) {
                $result['skipped_empty_legacy_count']++;
                continue;
            }

            $result['legacy_non_empty_count']++;
            $result['legacy_only_count']++;

            if (!$athleteTableReady) {
                $result['skipped_missing_target_count']++;
                continue;
            }

            $userId = (string) $user->getKey();
            $rows = $athleteRowsByUser[$userId] ?? [];
            if ($rows === []) {
                $result['skipped_missing_target_count']++;
                continue;
            }

            if (count($rows) > 1) {
                $result['skipped_ambiguous_target_count']++;
                continue;
            }

            $row = $rows[0];
            $canonicalDate = self::normalizeDate($row['data_atestado_medico'] ?? null);

            if (!$this->hasValue($canonicalDate)) {
                $result['candidates_count']++;
                $result['would_update_count']++;
                $result['update_candidates'][] = [
                    'athlete_sports_data_id' => (string) ($row['id'] ?? ''),
                    'target_value' => $legacyDate,
                ];

                continue;
            }

            $result['skipped_existing_canonical_count']++;
            $result['legacy_only_count']--;

            if ($this->comparableValue($legacyDate) === $this->comparableValue($canonicalDate)) {
                $result['already_matching_count']++;
                continue;
            }

            $result['divergent_count']++;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $fieldResult
     * @return array<string,mixed>
     */
    private function executeField(array $fieldResult, bool $commit): array
    {
        $updateCandidates = is_array($fieldResult['update_candidates'] ?? null)
            ? $fieldResult['update_candidates']
            : [];

        $fieldResult['updated_count'] = 0;

        if (!$commit || !$fieldResult['target_resolvable'] || !(bool) ($fieldResult['write_allowed'] ?? false)) {
            return $fieldResult;
        }

        $field = (string) ($fieldResult['field'] ?? '');
        if ($field === '' || $updateCandidates === []) {
            return $fieldResult;
        }

        foreach ($updateCandidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if ($field === 'estado_civil' || $field === 'numero_irmaos') {
                $this->applyPersonalPayloadUpdate($candidate);
                $fieldResult['updated_count']++;
                continue;
            }

            if ($field === 'data_atestado_medico') {
                $this->applyAthleteMedicalDateUpdate($candidate);
                $fieldResult['updated_count']++;
            }
        }

        $fieldResult['would_update_count'] = 0;

        return $fieldResult;
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function applyPersonalPayloadUpdate(array $candidate): void
    {
        $userId = is_string($candidate['user_id'] ?? null) ? trim((string) $candidate['user_id']) : '';
        $targetField = is_string($candidate['target_field'] ?? null) ? trim((string) $candidate['target_field']) : '';
        $targetValue = $candidate['target_value'] ?? null;
        $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : [];

        if ($userId === '' || $targetField === '') {
            return;
        }

        $payload[$targetField] = $targetValue;

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'dados_pessoais' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function applyAthleteMedicalDateUpdate(array $candidate): void
    {
        $athleteSportsDataId = is_string($candidate['athlete_sports_data_id'] ?? null)
            ? trim((string) $candidate['athlete_sports_data_id'])
            : '';

        $targetValue = self::normalizeDate($candidate['target_value'] ?? null);

        if ($athleteSportsDataId === '' || $targetValue === null) {
            return;
        }

        AthleteSportsData::query()
            ->whereKey($athleteSportsDataId)
            ->update([
                'data_atestado_medico' => $targetValue,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyFieldResult(array $definition, bool $targetResolvable): array
    {
        return [
            'field' => $definition['field'] ?? null,
            'legacy_field' => $definition['legacy_field'] ?? null,
            'target_area' => $definition['target_area'] ?? null,
            'target_field' => $definition['target_field'] ?? null,
            'target_status' => $definition['target_status'] ?? null,
            'decision' => $definition['decision'] ?? null,
            'owner_area' => $definition['owner_area'] ?? null,
            'reason' => $definition['reason'] ?? null,
            'next_action' => $definition['next_action'] ?? null,
            'write_allowed' => (bool) ($definition['write_allowed'] ?? false),
            'target_resolvable' => $targetResolvable,
            'legacy_non_empty_count' => 0,
            'legacy_only_count' => 0,
            'candidates_count' => 0,
            'would_update_count' => 0,
            'updated_count' => 0,
            'already_matching_count' => 0,
            'divergent_count' => 0,
            'skipped_existing_canonical_count' => 0,
            'skipped_missing_target_count' => 0,
            'skipped_ambiguous_target_count' => 0,
            'skipped_empty_legacy_count' => 0,
            'update_candidates' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function buildSummary(array $fields): array
    {
        $fieldsAnalyzed = count($fields);
        $writeAllowedCount = 0;
        $unresolvableCount = 0;
        $totalDivergent = 0;

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if ((bool) ($field['write_allowed'] ?? false)) {
                $writeAllowedCount++;
            }

            if (!(bool) ($field['target_resolvable'] ?? false)) {
                $unresolvableCount++;
            }

            $totalDivergent += (int) ($field['divergent_count'] ?? 0);
        }

        $commitAllowed = $fieldsAnalyzed > 0
            && $writeAllowedCount === $fieldsAnalyzed
            && $unresolvableCount === 0
            && $totalDivergent === 0;

        return [
            'fields_analyzed' => $fieldsAnalyzed,
            'total_legacy_non_empty_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['legacy_non_empty_count'] ?? 0), $fields)),
            'total_legacy_only_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['legacy_only_count'] ?? 0), $fields)),
            'total_candidates_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['candidates_count'] ?? 0), $fields)),
            'total_would_update_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['would_update_count'] ?? 0), $fields)),
            'total_updated_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['updated_count'] ?? 0), $fields)),
            'total_already_matching_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['already_matching_count'] ?? 0), $fields)),
            'total_divergent_count' => $totalDivergent,
            'total_skipped_existing_canonical_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['skipped_existing_canonical_count'] ?? 0), $fields)),
            'total_skipped_missing_target_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['skipped_missing_target_count'] ?? 0), $fields)),
            'total_skipped_ambiguous_target_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['skipped_ambiguous_target_count'] ?? 0), $fields)),
            'total_skipped_empty_legacy_count' => array_sum(array_map(static fn (array $row): int => (int) ($row['skipped_empty_legacy_count'] ?? 0), $fields)),
            'write_allowed_fields_count' => $writeAllowedCount,
            'unresolvable_target_fields_count' => $unresolvableCount,
            'commit_allowed' => $commitAllowed,
        ];
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadAthleteRowsByUser(bool $athleteTableReady): array
    {
        if (!$athleteTableReady) {
            return [];
        }

        $rows = AthleteSportsData::query()
            ->select(['id', 'user_id', 'data_atestado_medico'])
            ->orderBy('user_id')
            ->orderBy('id')
            ->get()
            ->all();

        $grouped = [];
        foreach ($rows as $row) {
            if (!$row instanceof AthleteSportsData) {
                continue;
            }

            $userId = (string) $row->getAttribute('user_id');
            if ($userId === '') {
                continue;
            }

            $grouped[$userId] = $grouped[$userId] ?? [];
            $grouped[$userId][] = [
                'id' => (string) $row->getKey(),
                'data_atestado_medico' => $row->getAttribute('data_atestado_medico'),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePersonalPayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
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

    private function comparableValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return (string) $value;
    }

    private static function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $value = (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeSiblingCount(mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : (string) $value;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (is_numeric($trimmed)) {
            $float = (float) $trimmed;
            if (floor($float) === $float) {
                return (int) $float;
            }
        }

        return $trimmed;
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($trimmed)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
