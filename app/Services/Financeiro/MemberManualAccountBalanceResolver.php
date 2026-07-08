<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\User;

final class MemberManualAccountBalanceResolver
{
    public function resolveForUser(User $user): float
    {
        $diagnostic = $this->detectDivergence($user);

        return (float) ($diagnostic['canonical_manual_balance'] ?? 0.0);
    }

    public function hasCanonicalManualBalance(User $user): bool
    {
        $diagnostic = $this->detectDivergence($user);

        return (bool) ($diagnostic['has_canonical_manual_balance'] ?? false);
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
        $user->loadMissing('dadosFinanceiros');

        $canonicalRaw = $user->dadosFinanceiros?->getRawOriginal('conta_corrente_manual');
        $legacyRaw = $user->getRawOriginal('conta_corrente');

        [$canonicalValid, $canonicalNormalized, $canonicalHasValue] = $this->normalizeAmount($canonicalRaw);
        [$legacyValid, $legacyNormalized, $legacyHasValue] = $this->normalizeAmount($legacyRaw);
        $legacyHasApplicableValue = $legacyHasValue && $legacyValid && abs($legacyNormalized) > 0.009;

        $resolved = $canonicalHasValue && $canonicalValid ? $canonicalNormalized : 0.0;

        $hasDivergence = $canonicalHasValue
            && $legacyHasApplicableValue
            && $canonicalValid
            && $legacyValid
            && abs($canonicalNormalized - $legacyNormalized) > 0.009;

        return [
            'canonical_manual_balance' => $canonicalHasValue && $canonicalValid ? round($canonicalNormalized, 2) : null,
            'legacy_account_balance' => $legacyHasApplicableValue ? round($legacyNormalized, 2) : null,
            'resolved_manual_balance' => round($resolved, 2),
            'has_canonical_manual_balance' => $canonicalHasValue && $canonicalValid,
            'has_legacy_fallback' => $legacyHasApplicableValue,
            'uses_legacy_fallback' => false,
            'has_divergence' => $hasDivergence,
            'has_invalid_value' => ($canonicalHasValue && !$canonicalValid) || ($legacyHasValue && !$legacyValid),
            'invalid_sources' => array_values(array_filter([
                $canonicalHasValue && !$canonicalValid ? 'canonical' : null,
                $legacyHasValue && !$legacyValid ? 'legacy' : null,
            ])),
        ];
    }

    /**
     * @return array{0:bool,1:float,2:bool}
     */
    private function normalizeAmount(mixed $value): array
    {
        if ($value === null) {
            return [true, 0.0, false];
        }

        if (is_int($value) || is_float($value)) {
            return [true, (float) $value, true];
        }

        if (is_string($value)) {
            $normalized = trim($value);

            if ($normalized === '') {
                return [true, 0.0, false];
            }

            if (!is_numeric($normalized)) {
                return [false, 0.0, true];
            }

            return [true, (float) $normalized, true];
        }

        return [false, 0.0, true];
    }
}
