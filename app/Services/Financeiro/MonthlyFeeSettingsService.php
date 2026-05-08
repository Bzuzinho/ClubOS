<?php

namespace App\Services\Financeiro;

use App\Models\ClubSetting;
use Illuminate\Support\Carbon;

class MonthlyFeeSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $settings = ClubSetting::query()->first();

        return [
            'generation_enabled' => $settings?->monthly_fee_generation_enabled ?? true,
            'start_month' => $this->normalizeMonth($settings?->monthly_fee_start_month ?? 9),
            'end_month' => $this->normalizeMonth($settings?->monthly_fee_end_month ?? 7),
            'due_day' => $this->normalizeDueDay($settings?->monthly_fee_due_day ?? 1),
            'hide_future' => $settings?->monthly_fee_hide_future ?? true,
            'auto_activate_due' => $settings?->monthly_fee_auto_activate_due ?? true,
            'respect_registration_date' => $settings?->monthly_fee_respect_registration_date ?? true,
            'generate_months_ahead' => $this->normalizeMonthsAhead($settings?->monthly_fee_generate_months_ahead),
            'default_period_mode' => (string) ($settings?->monthly_fee_default_period_mode ?: 'financial_cycle'),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveReferenceWindow(?Carbon $referenceDate = null): array
    {
        $settings = $this->get();
        $today = ($referenceDate ?? Carbon::today())->copy()->startOfDay();
        $startMonth = $settings['start_month'];
        $endMonth = $settings['end_month'];
        $crossesYear = $startMonth > $endMonth;

        if ($crossesYear) {
            if ($today->month >= $startMonth) {
                $startYear = $today->year;
            } elseif ($today->month <= $endMonth) {
                $startYear = $today->year - 1;
            } else {
                $startYear = $today->year;
            }

            $endYear = $startYear + 1;
        } else {
            if ($today->month < $startMonth) {
                $startYear = $today->year;
            } elseif ($today->month > $endMonth) {
                $startYear = $today->year + 1;
            } else {
                $startYear = $today->year;
            }

            $endYear = $startYear;
        }

        return [
            'start' => Carbon::create($startYear, $startMonth, 1)->startOfMonth(),
            'end' => Carbon::create($endYear, $endMonth, 1)->startOfMonth(),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveGenerationWindow(?Carbon $referenceDate = null): array
    {
        $today = ($referenceDate ?? Carbon::today())->copy()->startOfDay();
        $settings = $this->get();
        $window = $this->resolveReferenceWindow($today);

        if ($settings['generate_months_ahead'] !== null) {
            $maxEnd = $today->copy()->startOfMonth()->addMonthsNoOverflow((int) $settings['generate_months_ahead']);
            if ($maxEnd->lessThan($window['end'])) {
                $window['end'] = $maxEnd;
            }
        }

        if ($window['end']->lessThan($window['start'])) {
            $window['end'] = $window['start']->copy();
        }

        return $window;
    }

    public function resolveDueDate(Carbon $periodStart): Carbon
    {
        $settings = $this->get();
        $day = min((int) $settings['due_day'], $periodStart->copy()->endOfMonth()->day);

        return $periodStart->copy()->day($day)->startOfDay();
    }

    private function normalizeMonth(mixed $value): int
    {
        $month = (int) $value;

        return max(1, min(12, $month));
    }

    private function normalizeDueDay(mixed $value): int
    {
        $day = (int) $value;

        return max(1, min(28, $day));
    }

    private function normalizeMonthsAhead(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}