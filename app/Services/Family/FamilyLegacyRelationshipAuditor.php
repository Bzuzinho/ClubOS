<?php

declare(strict_types=1);

namespace App\Services\Family;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FamilyLegacyRelationshipAuditor
{
    private const SUPPORTED_TYPES = [
        'encarregado_educacao',
        'educando',
        'familiar',
    ];

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        if (! Schema::hasTable('user_relationships')) {
            return [
                'version' => 'H2.2',
                'summary' => [
                    'table_present' => false,
                    'total_rows' => 0,
                    'canonical_covered_count' => 0,
                    'uncovered_count' => 0,
                    'unknown_type_count' => 0,
                    'reciprocal_missing_count' => 0,
                    'ready_for_physical_cleanup' => true,
                ],
                'by_type' => [],
                'unresolved' => [],
            ];
        }

        $rows = DB::table('user_relationships')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'related_user_id', 'type']);

        $guardianPairs = $this->guardianPairs();
        $activeFamiliesByUser = $this->activeFamiliesByUser();
        $legacyKeys = [];

        foreach ($rows as $row) {
            $legacyKeys[$this->legacyKey(
                (string) $row->user_id,
                (string) $row->related_user_id,
                (string) $row->type,
            )] = true;
        }

        $byType = [];
        $canonicalCoveredCount = 0;
        $uncoveredCount = 0;
        $unknownTypeCount = 0;
        $reciprocalMissingCount = 0;
        $unresolved = [];

        foreach ($rows as $row) {
            $id = (string) $row->id;
            $userId = (string) $row->user_id;
            $relatedUserId = (string) $row->related_user_id;
            $type = trim((string) $row->type);
            $supported = in_array($type, self::SUPPORTED_TYPES, true);
            $covered = $supported && $this->isCanonicallyCovered(
                $userId,
                $relatedUserId,
                $type,
                $guardianPairs,
                $activeFamiliesByUser,
            );
            $reciprocalPresent = $this->hasExpectedReciprocal(
                $userId,
                $relatedUserId,
                $type,
                $legacyKeys,
            );

            if (! isset($byType[$type])) {
                $byType[$type] = [
                    'type' => $type,
                    'total_rows' => 0,
                    'canonical_covered_count' => 0,
                    'uncovered_count' => 0,
                    'reciprocal_missing_count' => 0,
                ];
            }

            $byType[$type]['total_rows']++;

            if (! $supported) {
                $unknownTypeCount++;
                $byType[$type]['uncovered_count']++;
                $unresolved[] = $this->unresolvedRow(
                    $id,
                    $userId,
                    $relatedUserId,
                    $type,
                    'unsupported_type',
                );
                continue;
            }

            if ($covered) {
                $canonicalCoveredCount++;
                $byType[$type]['canonical_covered_count']++;
            } else {
                $uncoveredCount++;
                $byType[$type]['uncovered_count']++;
                $unresolved[] = $this->unresolvedRow(
                    $id,
                    $userId,
                    $relatedUserId,
                    $type,
                    'missing_canonical_projection',
                );
            }

            if (! $reciprocalPresent) {
                $reciprocalMissingCount++;
                $byType[$type]['reciprocal_missing_count']++;
            }
        }

        ksort($byType);

        return [
            'version' => 'H2.2',
            'summary' => [
                'table_present' => true,
                'total_rows' => $rows->count(),
                'canonical_covered_count' => $canonicalCoveredCount,
                'uncovered_count' => $uncoveredCount,
                'unknown_type_count' => $unknownTypeCount,
                'reciprocal_missing_count' => $reciprocalMissingCount,
                'ready_for_physical_cleanup' => $uncoveredCount === 0 && $unknownTypeCount === 0,
            ],
            'by_type' => array_values($byType),
            'unresolved' => array_slice($unresolved, 0, 100),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function guardianPairs(): array
    {
        if (! Schema::hasTable('user_guardian')) {
            return [];
        }

        $pairs = [];

        foreach (DB::table('user_guardian')->get(['user_id', 'guardian_id']) as $row) {
            $pairs[$this->pairKey((string) $row->user_id, (string) $row->guardian_id)] = true;
        }

        return $pairs;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function activeFamiliesByUser(): array
    {
        if (! Schema::hasTable('familias') || ! Schema::hasTable('familia_user')) {
            return [];
        }

        $memberships = [];

        $rows = DB::table('familia_user')
            ->join('familias', 'familias.id', '=', 'familia_user.familia_id')
            ->where('familias.ativo', true)
            ->get(['familia_user.user_id', 'familia_user.familia_id']);

        foreach ($rows as $row) {
            $userId = (string) $row->user_id;
            $familyId = (string) $row->familia_id;
            $memberships[$userId][$familyId] = true;
        }

        return $memberships;
    }

    /**
     * @param array<string, true> $guardianPairs
     * @param array<string, array<string, true>> $activeFamiliesByUser
     */
    private function isCanonicallyCovered(
        string $userId,
        string $relatedUserId,
        string $type,
        array $guardianPairs,
        array $activeFamiliesByUser,
    ): bool {
        return match ($type) {
            'encarregado_educacao' => isset($guardianPairs[$this->pairKey($userId, $relatedUserId)]),
            'educando' => isset($guardianPairs[$this->pairKey($relatedUserId, $userId)]),
            'familiar' => $this->shareActiveFamily($userId, $relatedUserId, $activeFamiliesByUser),
            default => false,
        };
    }

    /**
     * @param array<string, array<string, true>> $activeFamiliesByUser
     */
    private function shareActiveFamily(
        string $userId,
        string $relatedUserId,
        array $activeFamiliesByUser,
    ): bool {
        $userFamilies = $activeFamiliesByUser[$userId] ?? [];
        $relatedFamilies = $activeFamiliesByUser[$relatedUserId] ?? [];

        if ($userFamilies === [] || $relatedFamilies === []) {
            return false;
        }

        foreach ($userFamilies as $familyId => $_) {
            if (isset($relatedFamilies[$familyId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, true> $legacyKeys
     */
    private function hasExpectedReciprocal(
        string $userId,
        string $relatedUserId,
        string $type,
        array $legacyKeys,
    ): bool {
        $reciprocalType = match ($type) {
            'encarregado_educacao' => 'educando',
            'educando' => 'encarregado_educacao',
            default => null,
        };

        if ($reciprocalType === null) {
            return true;
        }

        return isset($legacyKeys[$this->legacyKey($relatedUserId, $userId, $reciprocalType)]);
    }

    private function pairKey(string $memberId, string $guardianId): string
    {
        return $memberId.'|'.$guardianId;
    }

    private function legacyKey(string $userId, string $relatedUserId, string $type): string
    {
        return $userId.'|'.$relatedUserId.'|'.$type;
    }

    /**
     * @return array<string, string>
     */
    private function unresolvedRow(
        string $id,
        string $userId,
        string $relatedUserId,
        string $type,
        string $reason,
    ): array {
        return [
            'relationship_id' => $id,
            'user_id' => $userId,
            'related_user_id' => $relatedUserId,
            'type' => $type,
            'reason' => $reason,
        ];
    }
}
