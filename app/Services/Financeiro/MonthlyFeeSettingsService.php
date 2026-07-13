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

        return $this->normalize($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(ClubSetting|array|null $settings): array
    {
        return [
            'generation_enabled' => $this->toBoolean($this->settingValue($settings, 'monthly_fee_generation_enabled'), false),
            'start_month' => $this->normalizeMonth($this->settingValue($settings, 'monthly_fee_start_month') ?? 9),
            'end_month' => $this->normalizeMonth($this->settingValue($settings, 'monthly_fee_end_month') ?? 7),
            'due_day' => $this->normalizeDueDay($this->settingValue($settings, 'monthly_fee_due_day') ?? 1),
            'hide_future' => $this->toBoolean($this->settingValue($settings, 'monthly_fee_hide_future'), true),
            'auto_activate_due' => $this->toBoolean($this->settingValue($settings, 'monthly_fee_auto_activate_due'), false),
            'respect_registration_date' => $this->toBoolean($this->settingValue($settings, 'monthly_fee_respect_registration_date'), true),
            'generate_months_ahead' => $this->normalizeMonthsAhead($this->settingValue($settings, 'monthly_fee_generate_months_ahead')),
            'default_period_mode' => (string) ($this->settingValue($settings, 'monthly_fee_default_period_mode') ?: 'financial_cycle'),
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveReferenceWindow(?Carbon $referenceDate = null): array
    {
        $settings = $this->get();

        return $this->resolveReferenceWindowFromSettings($settings, $referenceDate);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveReferenceWindowFromSettings(array $settings, ?Carbon $referenceDate = null): array
    {
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

        return $this->resolveGenerationWindowFromSettings($settings, $today);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveGenerationWindowFromSettings(array $settings, ?Carbon $referenceDate = null): array
    {
        $today = ($referenceDate ?? Carbon::today())->copy()->startOfDay();
        $window = $this->resolveReferenceWindowFromSettings($settings, $today);

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

        return $this->resolveDueDateFromSettings($periodStart, $settings);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function resolveDueDateFromSettings(Carbon $periodStart, array $settings): Carbon
    {
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

    private function toBoolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
    }

    private function settingValue(ClubSetting|array|null $settings, string $key): mixed
    {
        if ($settings instanceof ClubSetting) {
            return $settings->{$key};
        }

        if (! is_array($settings)) {
            return null;
        }

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        $aliases = [
            'monthly_fee_generation_enabled' => 'generation_enabled',
            'monthly_fee_start_month' => 'start_month',
            'monthly_fee_end_month' => 'end_month',
            'monthly_fee_due_day' => 'due_day',
            'monthly_fee_hide_future' => 'hide_future',
            'monthly_fee_auto_activate_due' => 'auto_activate_due',
            'monthly_fee_respect_registration_date' => 'respect_registration_date',
            'monthly_fee_generate_months_ahead' => 'generate_months_ahead',
            'monthly_fee_default_period_mode' => 'default_period_mode',
        ];

        $alias = $aliases[$key] ?? null;

        return $alias && array_key_exists($alias, $settings) ? $settings[$alias] : null;
    }
}
