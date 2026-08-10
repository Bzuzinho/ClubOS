<?php

namespace App\Services\Desportivo;

use App\Models\ClubSetting;

final class TrainingSchedulingPolicyService
{
    public const ALLOW = 'allow';
    public const WARN = 'warn';
    public const BLOCK = 'block';

    /** @return array{lane_overlap:string,athlete_overlap:string,capacity:string} */
    public function all(): array
    {
        $settings = ClubSetting::query()->first();

        return [
            'lane_overlap' => $this->normalize(
                $settings?->sports_lane_overlap_policy
                    ?? config('sports.scheduling.lane_overlap_policy', self::WARN)
            ),
            'athlete_overlap' => $this->normalize(
                $settings?->sports_athlete_overlap_policy
                    ?? config('sports.scheduling.athlete_overlap_policy', self::WARN)
            ),
            'capacity' => $this->normalize(
                $settings?->sports_capacity_policy
                    ?? config('sports.scheduling.capacity_policy', self::WARN)
            ),
        ];
    }

    public function severityFor(string $conflictType): ?string
    {
        if ($conflictType === 'closure') {
            return 'decision_required';
        }

        $policy = $this->all()[$conflictType] ?? self::WARN;

        return match ($policy) {
            self::ALLOW => null,
            self::BLOCK => 'blocker',
            default => 'warning',
        };
    }

    private function normalize(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, [self::ALLOW, self::WARN, self::BLOCK], true)
            ? $value
            : self::WARN;
    }
}
