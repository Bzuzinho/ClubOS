<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MemberMonthlyFeeLegacyBackfillService
{
    /** @var list<string> */
    public const CLASSIFICATIONS = [
        'cleanup_completed',
        'ready_for_backfill',
        'already_canonical',
        'divergent',
        'invalid_legacy_reference',
        'missing_required',
        'not_required',
        'no_source',
        'skipped',
    ];

    public function __construct(
        private readonly MemberMonthlyFeeResolver $resolver,
        private readonly MemberMonthlyFeeSyncService $syncService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(?string $userId = null): array
    {
        $normalizedUserId = $this->normalizeUserId($userId);

        if (!Schema::hasColumn('users', 'tipo_mensalidade')) {
            return $this->analyzeAfterCleanup($normalizedUserId);
        }

        $existingMonthlyFeeIds = $this->loadExistingMonthlyFeeIds();

        $users = User::query()
            ->select('id', 'numero_socio', 'name', 'estado', 'tipo_mensalidade')
            ->with('dadosFinanceiros:id,user_id,mensalidade_id')
            ->when($normalizedUserId !== null, static fn ($query) => $query->whereKey($normalizedUserId))
            ->orderBy('numero_socio')
            ->orderBy('id')
            ->get();

        $cases = [];
        foreach ($users as $user) {
            $cases[] = $this->classifyUser($user, $existingMonthlyFeeIds);
        }

        $summary = $this->buildSummary($cases);

        return [
            'version' => 'f2.1-member-monthly-fee-backfill-v1',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'dry-run',
            'scope' => [
                'user' => $normalizedUserId,
            ],
            'summary' => $summary,
            'classifications' => $this->groupCasesByClassification($cases),
            'cases' => $cases,
            'preflight' => $this->buildPreflight($cases, $summary),
            'migration' => [
                'migrated_count' => 0,
                'migrated_user_ids' => [],
                'already_canonical_count' => 0,
                'already_canonical_user_ids' => [],
                'skipped_count' => 0,
                'skipped_user_ids' => [],
                'failed_count' => 0,
                'failed' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeAfterCleanup(?string $userId): array
    {
        $users = User::query()
            ->select('id', 'numero_socio', 'name', 'estado')
            ->with('dadosFinanceiros:id,user_id,mensalidade_id')
            ->when($userId !== null, static fn ($query) => $query->whereKey($userId))
            ->orderBy('numero_socio')
            ->orderBy('id')
            ->get();

        $cases = $users->map(fn (User $user): array => [
            'user_id' => (string) $user->id,
            'numero_socio' => (string) ($user->numero_socio ?? ''),
            'name' => (string) ($user->name ?? ''),
            'estado' => (string) ($user->estado ?? ''),
            'classification' => 'cleanup_completed',
            'canonical_monthly_fee_id' => $this->normalizeMonthlyFeeId($user->dadosFinanceiros?->mensalidade_id),
            'legacy_monthly_fee_id' => null,
            'resolved_monthly_fee_id' => $this->normalizeMonthlyFeeId($user->dadosFinanceiros?->mensalidade_id),
            'reference_valid' => true,
            'uses_legacy_fallback' => false,
            'has_divergence' => false,
            'requires_monthly_fee' => $this->requiresMonthlyFee($user),
            'reason' => 'Legacy column users.tipo_mensalidade was removed in FC2.',
            'canonical_payload_candidate' => [],
        ])->all();

        $summary = $this->buildSummary($cases);

        return [
            'version' => 'f2.1-member-monthly-fee-backfill-v1',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'dry-run',
            'scope' => [
                'user' => $userId,
            ],
            'summary' => $summary,
            'classifications' => $this->groupCasesByClassification($cases),
            'cases' => $cases,
            'preflight' => [
                'can_apply' => false,
                'blocking_reasons' => ['legacy_column_missing_cleanup_completed'],
                'divergent_count' => 0,
                'invalid_legacy_reference_count' => 0,
                'unexpected_no_source_within_legacy_expected_count' => 0,
            ],
            'migration' => [
                'migrated_count' => 0,
                'migrated_user_ids' => [],
                'already_canonical_count' => 0,
                'already_canonical_user_ids' => [],
                'skipped_count' => 0,
                'skipped_user_ids' => [],
                'failed_count' => 0,
                'failed' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    public function apply(array $analysis): array
    {
        $cases = is_array($analysis['cases'] ?? null) ? $analysis['cases'] : [];

        $migratedUserIds = [];
        $alreadyCanonicalUserIds = [];
        $skippedUserIds = [];
        $failed = [];

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $userId = (string) ($case['user_id'] ?? '');
            $classification = (string) ($case['classification'] ?? 'skipped');

            if ($userId === '') {
                continue;
            }

            if ($classification === 'already_canonical') {
                $alreadyCanonicalUserIds[] = $userId;

                continue;
            }

            if ($classification !== 'ready_for_backfill') {
                $skippedUserIds[] = $userId;

                continue;
            }

            try {
                DB::transaction(function () use ($userId, &$migratedUserIds, &$alreadyCanonicalUserIds): void {
                    $existingMonthlyFeeIds = $this->loadExistingMonthlyFeeIds();

                    $user = User::query()
                        ->with('dadosFinanceiros:id,user_id,mensalidade_id')
                        ->whereKey($userId)
                        ->lockForUpdate()
                        ->first();

                    if (!$user instanceof User) {
                        throw new \RuntimeException('Member not found during apply.');
                    }

                    $refreshedCase = $this->classifyUser($user, $existingMonthlyFeeIds);
                    $refreshedClassification = (string) ($refreshedCase['classification'] ?? 'skipped');

                    if ($refreshedClassification === 'already_canonical') {
                        $alreadyCanonicalUserIds[] = (string) $user->id;

                        return;
                    }

                    if ($refreshedClassification !== 'ready_for_backfill') {
                        throw new \RuntimeException(sprintf(
                            'Member no longer ready_for_backfill during apply (classification=%s).',
                            $refreshedClassification,
                        ));
                    }

                    $legacyMonthlyFeeId = $refreshedCase['legacy_monthly_fee_id'] ?? null;
                    if (!is_string($legacyMonthlyFeeId) || trim($legacyMonthlyFeeId) === '') {
                        throw new \RuntimeException('Legacy monthly fee id is empty during apply.');
                    }

                    // Canonical write path: reuse runtime sync service and keep current temporary dual-write.
                    $this->syncService->sync($user, $legacyMonthlyFeeId);
                    $migratedUserIds[] = (string) $user->id;
                });
            } catch (\Throwable $exception) {
                $failed[] = [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $refreshed = $this->analyze($this->normalizeUserId((string) ($analysis['scope']['user'] ?? '')));

        $refreshed['mode'] = 'apply';
        $refreshed['migration'] = [
            'migrated_count' => count(array_unique($migratedUserIds)),
            'migrated_user_ids' => array_values(array_unique($migratedUserIds)),
            'already_canonical_count' => count(array_unique($alreadyCanonicalUserIds)),
            'already_canonical_user_ids' => array_values(array_unique($alreadyCanonicalUserIds)),
            'skipped_count' => count(array_unique($skippedUserIds)),
            'skipped_user_ids' => array_values(array_unique($skippedUserIds)),
            'failed_count' => count($failed),
            'failed' => $failed,
        ];

        return $refreshed;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function preflightAllowsApply(array $analysis): bool
    {
        $preflight = is_array($analysis['preflight'] ?? null) ? $analysis['preflight'] : [];

        return (bool) ($preflight['can_apply'] ?? false);
    }

    /**
     * @param array<string, bool> $existingMonthlyFeeIds
     * @return array<string, mixed>
     */
    private function classifyUser(User $user, array $existingMonthlyFeeIds): array
    {
        $diagnostic = $this->resolver->detectDivergence($user);

        $canonicalId = $this->normalizeMonthlyFeeId($diagnostic['canonical_monthly_fee_id'] ?? null);
        $legacyId = $this->normalizeMonthlyFeeId($diagnostic['legacy_monthly_fee_id'] ?? null);
        $resolvedId = $this->normalizeMonthlyFeeId($diagnostic['resolved_monthly_fee_id'] ?? null);
        $hasDivergence = (bool) ($diagnostic['has_divergence'] ?? false);

        $legacyReferenceValid = $legacyId === null
            ? true
            : isset($existingMonthlyFeeIds[$legacyId]);

        $classification = 'skipped';
        $reason = 'Case was conservatively skipped.';

        if ($hasDivergence) {
            $classification = 'divergent';
            $reason = 'Canonical and legacy monthly fee ids diverge.';
        } elseif ($legacyId !== null && !$legacyReferenceValid) {
            $classification = 'invalid_legacy_reference';
            $reason = 'Legacy monthly fee references a missing or invalid plan.';
        } elseif ($canonicalId !== null) {
            $classification = 'already_canonical';
            $reason = 'Canonical monthly fee is already set.';
        } elseif ($canonicalId === null && $legacyId !== null) {
            $classification = 'ready_for_backfill';
            $reason = 'Legacy-only monthly fee can be migrated to canonical source.';
        } elseif ($canonicalId === null && $legacyId === null) {
            $required = $this->requiresMonthlyFee($user);

            if ($required === true) {
                $classification = 'missing_required';
                $reason = 'Monthly fee is required for this member but no source exists.';
            } elseif ($required === false) {
                $classification = 'not_required';
                $reason = 'Monthly fee is not required for this member state.';
            } else {
                $classification = 'no_source';
                $reason = 'No monthly fee source and member state is insufficient to classify requirement.';
            }
        }

        return [
            'user_id' => (string) $user->id,
            'numero_socio' => (string) ($user->numero_socio ?? ''),
            'name' => (string) ($user->name ?? ''),
            'estado' => (string) ($user->estado ?? ''),
            'classification' => $classification,
            'canonical_monthly_fee_id' => $canonicalId,
            'legacy_monthly_fee_id' => $legacyId,
            'resolved_monthly_fee_id' => $resolvedId,
            'reference_valid' => $legacyReferenceValid,
            'uses_legacy_fallback' => false,
            'has_divergence' => $hasDivergence,
            'requires_monthly_fee' => $this->requiresMonthlyFee($user),
            'reason' => $reason,
            'canonical_payload_candidate' => $classification === 'ready_for_backfill'
                ? ['mensalidade_id' => $legacyId]
                : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return array<string, int|string|array<string, int>|array<string, array<int, string>>>
     */
    private function buildSummary(array $cases): array
    {
        $counts = [];
        $userIdsByClassification = [];

        foreach (self::CLASSIFICATIONS as $classification) {
            $counts[$classification] = 0;
            $userIdsByClassification[$classification] = [];
        }

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $classification = (string) ($case['classification'] ?? 'skipped');
            if (!array_key_exists($classification, $counts)) {
                $classification = 'skipped';
            }

            $counts[$classification]++;
            $userIdsByClassification[$classification][] = (string) ($case['user_id'] ?? '');
        }

        return [
            'total' => count($cases),
            'counts' => $counts,
            'user_ids_by_classification' => array_map(
                static fn (array $ids): array => array_values(array_filter(array_unique($ids))),
                $userIdsByClassification,
            ),
            'ready_for_backfill_count' => (int) ($counts['ready_for_backfill'] ?? 0),
            'already_canonical_count' => (int) ($counts['already_canonical'] ?? 0),
            'divergent_count' => (int) ($counts['divergent'] ?? 0),
            'invalid_legacy_reference_count' => (int) ($counts['invalid_legacy_reference'] ?? 0),
            'missing_required_count' => (int) ($counts['missing_required'] ?? 0),
            'not_required_count' => (int) ($counts['not_required'] ?? 0),
            'no_source_count' => (int) ($counts['no_source'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildPreflight(array $cases, array $summary): array
    {
        $divergentCount = (int) ($summary['divergent_count'] ?? 0);
        $invalidLegacyReferenceCount = (int) ($summary['invalid_legacy_reference_count'] ?? 0);
        $unexpectedNoSourceFromLegacyExpected = $this->countUnexpectedNoSourceWithinLegacyExpected($cases);

        $blockingReasons = [];
        if ($divergentCount > 0) {
            $blockingReasons[] = 'divergent_count > 0';
        }

        if ($invalidLegacyReferenceCount > 0) {
            $blockingReasons[] = 'invalid_legacy_reference_count > 0';
        }

        if ($unexpectedNoSourceFromLegacyExpected > 0) {
            $blockingReasons[] = 'unexpected_no_source_within_legacy_expected_count > 0';
        }

        return [
            'can_apply' => $blockingReasons === [],
            'blocking_reasons' => $blockingReasons,
            'divergent_count' => $divergentCount,
            'invalid_legacy_reference_count' => $invalidLegacyReferenceCount,
            'unexpected_no_source_within_legacy_expected_count' => $unexpectedNoSourceFromLegacyExpected,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cases
     */
    private function countUnexpectedNoSourceWithinLegacyExpected(array $cases): int
    {
        $count = 0;

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $classification = (string) ($case['classification'] ?? 'skipped');
            $legacyId = $this->normalizeMonthlyFeeId($case['legacy_monthly_fee_id'] ?? null);
            $usesLegacyFallback = (bool) ($case['uses_legacy_fallback'] ?? false);

            if ($classification === 'no_source' && ($legacyId !== null || $usesLegacyFallback)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupCasesByClassification(array $cases): array
    {
        $grouped = [];
        foreach (self::CLASSIFICATIONS as $classification) {
            $grouped[$classification] = [];
        }

        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $classification = (string) ($case['classification'] ?? 'skipped');
            if (!array_key_exists($classification, $grouped)) {
                $classification = 'skipped';
            }

            $grouped[$classification][] = $case;
        }

        return $grouped;
    }

    /**
     * @return array<string, bool>
     */
    private function loadExistingMonthlyFeeIds(): array
    {
        $ids = MonthlyFee::query()->pluck('id')->all();
        $existing = [];

        foreach ($ids as $id) {
            $normalized = $this->normalizeMonthlyFeeId($id);
            if ($normalized === null) {
                continue;
            }

            $existing[$normalized] = true;
        }

        return $existing;
    }

    private function requiresMonthlyFee(User $user): ?bool
    {
        $estado = strtolower(trim((string) ($user->estado ?? '')));

        if ($estado === 'ativo') {
            return true;
        }

        if ($estado === '') {
            return null;
        }

        return false;
    }

    private function normalizeMonthlyFeeId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return ($normalized === '' || $normalized === '0') ? null : $normalized;
        }

        if (is_int($value) || is_float($value)) {
            if ((float) $value === 0.0) {
                return null;
            }

            return (string) $value;
        }

        return null;
    }

    private function normalizeUserId(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $trimmed = trim($userId);

        return $trimmed === '' ? null : $trimmed;
    }
}
