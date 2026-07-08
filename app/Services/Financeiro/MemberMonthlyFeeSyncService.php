<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use InvalidArgumentException;

final class MemberMonthlyFeeSyncService
{
    public function sync(User $member, mixed $monthlyFeeId): ?string
    {
        $normalizedMonthlyFeeId = $this->normalizeMonthlyFeeId($monthlyFeeId);

        if ($normalizedMonthlyFeeId !== null && !MonthlyFee::query()->whereKey($normalizedMonthlyFeeId)->exists()) {
            throw new InvalidArgumentException('Monthly fee reference is invalid.');
        }

        $financeData = DadosFinanceiros::query()->firstOrNew(['user_id' => $member->id]);

        if ($financeData->mensalidade_id !== $normalizedMonthlyFeeId) {
            $financeData->mensalidade_id = $normalizedMonthlyFeeId;
            $financeData->save();
        }

        return $normalizedMonthlyFeeId;
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
