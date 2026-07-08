<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\User;

final class MemberMonthlyFeeResolver
{
    public function resolveForUser(User $user): ?string
    {
        return $this->canonicalMonthlyFeeId($user);
    }

    public function hasCanonicalMonthlyFee(User $user): bool
    {
        return $this->canonicalMonthlyFeeId($user) !== null;
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
        $canonical = $this->canonicalMonthlyFeeId($user);
        $legacy = $this->legacyMonthlyFeeId($user);

        return [
            'canonical_monthly_fee_id' => $canonical,
            'legacy_monthly_fee_id' => $legacy,
            'resolved_monthly_fee_id' => $canonical ?? $legacy,
            'has_canonical_monthly_fee' => $canonical !== null,
            'has_legacy_fallback' => $legacy !== null,
            'uses_legacy_fallback' => $canonical === null && $legacy !== null,
            'has_divergence' => $canonical !== null && $legacy !== null && $canonical !== $legacy,
        ];
    }

    public function canonicalMonthlyFeeId(User $user): ?string
    {
        $user->loadMissing('dadosFinanceiros');

        return $this->normalizeMonthlyFeeId($user->dadosFinanceiros?->mensalidade_id);
    }

    public function legacyMonthlyFeeId(User $user): ?string
    {
        return $this->normalizeMonthlyFeeId($user->tipo_mensalidade);
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
}
