<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MemberCostCenterLegacyBackfillService
{
    /** @var list<string> */
    public const CLASSIFICATIONS = [
        'ready_for_backfill',
        'already_canonical',
        'divergent',
        'invalid_legacy',
        'invalid_weights',
        'no_source',
        'skipped',
    ];

    public function __construct(
        private readonly MemberCostCenterResolver $resolver,
        private readonly MemberCostCenterSyncService $syncService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(?string $userId = null): array
    {
        $trimmedUserId = $this->normalizeUserId($userId);
        $costCenterIds = $this->loadExistingCostCenterIds();

        $query = User::query()
            ->select('id', 'name', 'numero_socio', 'centro_custo')
            ->orderBy('numero_socio')
            ->orderBy('id');

        if ($trimmedUserId !== null) {
            $query->whereKey($trimmedUserId);
        }

        $users = $query->get();

        $rows = [];
        foreach ($users as $user) {
            $rows[] = $this->classifyUser($user, $costCenterIds);
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'user_filter' => $trimmedUserId,
            'mode' => 'dry-run',
            'dry_run' => true,
            'apply_requested' => false,
            'members' => $rows,
            'summary' => $this->buildSummary($rows),
            'preflight' => $this->buildPreflight($rows),
            'migration' => [
                'migrated_count' => 0,
                'migrated_user_ids' => [],
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
        $members = is_array($analysis['members'] ?? null) ? $analysis['members'] : [];

        $migratedUserIds = [];
        $skippedUserIds = [];
        $failed = [];

        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }

            $memberId = (string) ($member['id'] ?? '');
            $classification = (string) ($member['classification'] ?? 'skipped');

            if ($memberId === '') {
                continue;
            }

            if ($classification !== 'ready_for_backfill') {
                $skippedUserIds[] = $memberId;
                continue;
            }

            try {
                DB::transaction(function () use ($memberId, &$migratedUserIds): void {
                    $user = User::query()
                        ->whereKey($memberId)
                        ->lockForUpdate()
                        ->first();

                    if (!$user instanceof User) {
                        throw new \RuntimeException('Member not found during apply.');
                    }

                    $existingCostCenterIds = $this->loadExistingCostCenterIds();
                    $classification = $this->classifyUser($user->fresh(), $existingCostCenterIds);
                    $currentClassification = (string) ($classification['classification'] ?? 'skipped');

                    if ($currentClassification !== 'ready_for_backfill') {
                        throw new \RuntimeException('Member no longer ready_for_backfill during apply.');
                    }

                    $candidatePayload = is_array($classification['canonical_payload_candidate'] ?? null)
                        ? $classification['canonical_payload_candidate']
                        : [];

                    if ($candidatePayload === []) {
                        throw new \RuntimeException('Empty canonical payload candidate during apply.');
                    }

                    // Canonical write path: reuse the same pivot sync service used by runtime flows.
                    $this->syncService->sync($user, $candidatePayload);
                    $migratedUserIds[] = (string) $user->id;
                });
            } catch (\Throwable $exception) {
                $failed[] = [
                    'id' => $memberId,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $payload = $analysis;
        $payload['mode'] = 'apply';
        $payload['dry_run'] = false;
        $payload['apply_requested'] = true;
        $payload['migration'] = [
            'migrated_count' => count($migratedUserIds),
            'migrated_user_ids' => array_values(array_unique($migratedUserIds)),
            'skipped_count' => count($skippedUserIds),
            'skipped_user_ids' => array_values(array_unique($skippedUserIds)),
            'failed_count' => count($failed),
            'failed' => $failed,
        ];

        // Rebuild summary/preflight after writes to reflect the resulting dataset state.
        $refreshed = $this->analyze($this->normalizeUserId((string) ($analysis['user_filter'] ?? '')));
        $payload['members'] = $refreshed['members'];
        $payload['summary'] = $refreshed['summary'];
        $payload['preflight'] = $refreshed['preflight'];

        return $payload;
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
     * @param array<string, bool> $existingCostCenterIds
     * @return array<string, mixed>
     */
    private function classifyUser(User $user, array $existingCostCenterIds): array
    {
        $resolved = $this->resolver->resolveForUser($user);
        $divergence = is_array($resolved['divergence'] ?? null) ? $resolved['divergence'] : [];

        $canonicalRows = is_array($resolved['canonical']['rows'] ?? null) ? $resolved['canonical']['rows'] : [];
        $legacyRows = is_array($resolved['legacy']['rows'] ?? null) ? $resolved['legacy']['rows'] : [];
        $legacyIds = is_array($resolved['legacy']['ids'] ?? null) ? $resolved['legacy']['ids'] : [];

        $invalidWeightIds = array_values(array_filter(
            is_array($divergence['invalid_weight_ids'] ?? null) ? $divergence['invalid_weight_ids'] : [],
            static fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        ));

        $legacyMissingIds = [];
        foreach ($legacyIds as $legacyId) {
            $normalizedId = trim((string) $legacyId);
            if ($normalizedId === '') {
                continue;
            }

            if (!isset($existingCostCenterIds[$normalizedId])) {
                $legacyMissingIds[] = $normalizedId;
            }
        }

        $normalizedPayload = $this->syncService->normalize($legacyRows);

        $classification = 'skipped';
        $reason = 'Conservative fallback classification.';

        if ((bool) ($divergence['has_divergence'] ?? false)) {
            $classification = 'divergent';
            $reason = 'Canonical and legacy cost centers diverge.';
        } elseif ($invalidWeightIds !== []) {
            $classification = 'invalid_weights';
            $reason = 'Invalid cost center weights were detected.';
        } elseif ($canonicalRows !== []) {
            $classification = 'already_canonical';
            $reason = 'Member already has canonical cost centers in pivot.';
        } elseif ($legacyRows === []) {
            $classification = 'no_source';
            $reason = 'No canonical or legacy cost center source was found.';
        } elseif ($legacyMissingIds !== []) {
            $classification = 'invalid_legacy';
            $reason = 'Legacy payload references cost centers that do not exist.';
        } elseif ($normalizedPayload === []) {
            $classification = 'invalid_legacy';
            $reason = 'Legacy payload could not be normalized into canonical rows.';
        } elseif (!$this->allWeightsValid($normalizedPayload)) {
            $classification = 'invalid_weights';
            $reason = 'Normalized payload contains invalid weights.';
        } else {
            $classification = 'ready_for_backfill';
            $reason = 'Legacy-only member ready for canonical pivot backfill.';
        }

        return [
            'id' => (string) $user->id,
            'numero_socio' => (string) ($user->numero_socio ?? ''),
            'name' => (string) ($user->name ?? ''),
            'classification' => $classification,
            'reason' => $reason,
            'source' => (string) ($resolved['source'] ?? 'none'),
            'legacy_cost_centers_found' => array_values($legacyRows),
            'canonical_cost_centers_found' => array_values($canonicalRows),
            'legacy_ids_missing' => array_values(array_unique($legacyMissingIds)),
            'invalid_weight_ids' => $invalidWeightIds,
            'canonical_payload_candidate' => $classification === 'ready_for_backfill'
                ? array_values($normalizedPayload)
                : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildSummary(array $rows): array
    {
        $counts = [];
        $userIdsByClassification = [];

        foreach (self::CLASSIFICATIONS as $classification) {
            $counts[$classification] = 0;
            $userIdsByClassification[$classification] = [];
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $classification = (string) ($row['classification'] ?? 'skipped');
            if (!array_key_exists($classification, $counts)) {
                $classification = 'skipped';
            }

            $counts[$classification]++;
            $userIdsByClassification[$classification][] = (string) ($row['id'] ?? '');
        }

        return [
            'total_users_analyzed' => count($rows),
            'classifications' => $counts,
            'user_ids_by_classification' => array_map(
                static fn (array $ids): array => array_values(array_filter(array_unique($ids))),
                $userIdsByClassification,
            ),
            'ready_for_backfill_count' => (int) ($counts['ready_for_backfill'] ?? 0),
            'already_canonical_count' => (int) ($counts['already_canonical'] ?? 0),
            'divergent_count' => (int) ($counts['divergent'] ?? 0),
            'invalid_legacy_count' => (int) ($counts['invalid_legacy'] ?? 0),
            'invalid_weights_count' => (int) ($counts['invalid_weights'] ?? 0),
            'no_source_count' => (int) ($counts['no_source'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildPreflight(array $rows): array
    {
        $summary = $this->buildSummary($rows);

        $divergentCount = (int) ($summary['divergent_count'] ?? 0);
        $invalidLegacyCount = (int) ($summary['invalid_legacy_count'] ?? 0);
        $invalidWeightsCount = (int) ($summary['invalid_weights_count'] ?? 0);

        $blockingReasons = [];
        if ($divergentCount > 0) {
            $blockingReasons[] = 'divergent_count > 0';
        }

        if ($invalidLegacyCount > 0) {
            $blockingReasons[] = 'invalid_legacy_count > 0';
        }

        if ($invalidWeightsCount > 0) {
            $blockingReasons[] = 'invalid_weights_count > 0';
        }

        return [
            'can_apply' => $blockingReasons === [],
            'blocking_reasons' => $blockingReasons,
            'divergent_count' => $divergentCount,
            'invalid_legacy_count' => $invalidLegacyCount,
            'invalid_weights_count' => $invalidWeightsCount,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function loadExistingCostCenterIds(): array
    {
        $ids = CostCenter::query()->pluck('id')->all();
        $map = [];

        foreach ($ids as $id) {
            $normalized = trim((string) $id);
            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = true;
        }

        return $map;
    }

    /**
     * @param list<array{id:string,peso:float}> $rows
     */
    private function allWeightsValid(array $rows): bool
    {
        foreach ($rows as $row) {
            $weight = (float) ($row['peso'] ?? 0);
            if ($weight <= 0) {
                return false;
            }
        }

        return true;
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
