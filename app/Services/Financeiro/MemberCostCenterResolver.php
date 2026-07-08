<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\User;

final class MemberCostCenterResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolveForUser(User $user): array
    {
        $user->loadMissing('centrosCusto');

        $canonicalRows = $this->canonicalRowsFor($user);
        $legacyRows = $this->legacyRowsFor($user);
        $selectedRows = $canonicalRows;
        $source = $canonicalRows !== [] ? 'canonical' : 'none';

        return [
            'source' => $source,
            'centro_custo' => array_values(array_map(static fn (array $row): string => (string) $row['id'], $selectedRows)),
            'centro_custo_pesos' => array_values(array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'peso' => (float) $row['peso'],
            ], $selectedRows)),
            'canonical' => [
                'rows' => $canonicalRows,
                'ids' => array_values(array_map(static fn (array $row): string => (string) $row['id'], $canonicalRows)),
            ],
            'legacy' => [
                'rows' => $legacyRows,
                'ids' => array_values(array_map(static fn (array $row): string => (string) $row['id'], $legacyRows)),
            ],
            'divergence' => $this->detectDivergence($user),
        ];
    }

    public function hasCanonicalCostCenters(User $user): bool
    {
        $user->loadMissing('centrosCusto');

        return $this->canonicalRowsFor($user) !== [];
    }

    public function hasLegacyFallback(User $user): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function detectDivergence(User $user): array
    {
        $user->loadMissing('centrosCusto');

        $canonicalRows = $this->canonicalRowsFor($user);
        $legacyRows = $this->legacyRowsFor($user);
        $canonicalIds = array_values(array_map(static fn (array $row): string => (string) $row['id'], $canonicalRows));
        $legacyIds = array_values(array_map(static fn (array $row): string => (string) $row['id'], $legacyRows));
        $canonicalWeightMap = $this->weightMap($canonicalRows);
        $legacyWeightMap = $this->weightMap($legacyRows);

        $missingInCanonical = array_values(array_diff($legacyIds, $canonicalIds));
        $missingInLegacy = array_values(array_diff($canonicalIds, $legacyIds));

        $weightMismatches = [];
        foreach (array_intersect($canonicalIds, $legacyIds) as $id) {
            $canonicalWeight = $canonicalWeightMap[$id] ?? null;
            $legacyWeight = $legacyWeightMap[$id] ?? null;

            if ($canonicalWeight === null || $legacyWeight === null) {
                continue;
            }

            if (abs($canonicalWeight - $legacyWeight) > 0.0001) {
                $weightMismatches[] = [
                    'id' => $id,
                    'canonical_peso' => $canonicalWeight,
                    'legacy_peso' => $legacyWeight,
                ];
            }
        }

        $invalidWeights = array_values(array_merge(
            $this->invalidWeightIds($canonicalRows, 'canonical'),
            $this->invalidWeightIds($legacyRows, 'legacy'),
        ));

        $hasCanonical = $canonicalRows !== [];
        $hasLegacy = $legacyRows !== [];

        return [
            'has_canonical_cost_centers' => $hasCanonical,
            'has_legacy_fallback' => $hasLegacy,
            'uses_legacy_fallback' => !$hasCanonical && $hasLegacy,
            'has_divergence' => $hasCanonical && $hasLegacy && (
                $missingInCanonical !== []
                || $missingInLegacy !== []
                || $weightMismatches !== []
                || $invalidWeights !== []
            ),
            'canonical_ids' => $canonicalIds,
            'legacy_ids' => $legacyIds,
            'canonical_rows' => $canonicalRows,
            'legacy_rows' => $legacyRows,
            'missing_in_canonical' => $missingInCanonical,
            'missing_in_legacy' => $missingInLegacy,
            'weight_mismatches' => $weightMismatches,
            'invalid_weight_ids' => $invalidWeights,
            'canonical_only_ids' => array_values(array_diff($canonicalIds, $legacyIds)),
            'legacy_only_ids' => array_values(array_diff($legacyIds, $canonicalIds)),
        ];
    }

    /**
     * @return list<array{id:string,peso:float,is_valid_weight:bool}>
     */
    private function canonicalRowsFor(User $user): array
    {
        return $user->centrosCusto
            ->map(function ($center): array {
                [$peso, $isValid] = $this->normalizeWeight($center->pivot->peso ?? null);

                return [
                    'id' => (string) $center->id,
                    'peso' => $peso,
                    'is_valid_weight' => $isValid,
                ];
            })
            ->filter(static fn (array $row): bool => $row['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:string,peso:float,is_valid_weight:bool}>
     */
    private function legacyRowsFor(User $user): array
    {
        $legacy = $user->getAttribute('centro_custo');

        return collect(is_array($legacy) ? $legacy : [])
            ->map(function ($center): array {
                if (is_array($center)) {
                    [$peso, $isValid] = $this->normalizeWeight($center['peso'] ?? null);

                    return [
                        'id' => (string) ($center['id'] ?? ''),
                        'peso' => $peso,
                        'is_valid_weight' => $isValid,
                    ];
                }

                return [
                    'id' => (string) $center,
                    'peso' => 1.0,
                    'is_valid_weight' => $center !== null && $center !== '',
                ];
            })
            ->filter(static fn (array $row): bool => $row['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param list<array{id:string,peso:float,is_valid_weight:bool}> $rows
     * @return array<string, float>
     */
    private function weightMap(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['id']] = (float) $row['peso'];
        }

        return $map;
    }

    /**
     * @param list<array{id:string,peso:float,is_valid_weight:bool}> $rows
     * @return list<string>
     */
    private function invalidWeightIds(array $rows, string $source): array
    {
        $invalid = [];

        foreach ($rows as $row) {
            if ($row['is_valid_weight']) {
                continue;
            }

            $invalid[] = sprintf('%s:%s', $source, (string) $row['id']);
        }

        return $invalid;
    }

    /**
     * @return array{0:float,1:bool}
     */
    private function normalizeWeight(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return [$value > 0 ? (float) $value : 1.0, $value > 0];
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '') {
                return [1.0, false];
            }

            if (!is_numeric($normalized)) {
                return [1.0, false];
            }

            $weight = (float) $normalized;

            return [$weight > 0 ? $weight : 1.0, $weight > 0];
        }

        if ($value === null) {
            return [1.0, false];
        }

        return [1.0, false];
    }
}