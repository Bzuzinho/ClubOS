<?php

namespace App\Services\Desportivo;

use App\Models\AthleteAgeGroupOverride;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class AgeGroupPlacementService
{
    public function resolve(
        string $clubId,
        string $userId,
        string $seasonId,
        string $modalityId,
        CarbonInterface|string $birthDate,
        ?string $gender = null
    ): ?array {
        $season = Season::query()
            ->where('club_id', $clubId)
            ->where('sports_modality_id', $modalityId)
            ->findOrFail($seasonId);

        $override = AthleteAgeGroupOverride::query()
            ->where('club_id', $clubId)
            ->where('user_id', $userId)
            ->where('season_id', $seasonId)
            ->where('sports_modality_id', $modalityId)
            ->where('active', true)
            ->with('ageGroup')
            ->latest('effective_at')
            ->first();

        if ($override) {
            return [
                'age_group' => $override->ageGroup,
                'source' => 'override',
                'override_id' => $override->id,
            ];
        }

        $birth = $birthDate instanceof CarbonInterface
            ? Carbon::instance($birthDate)
            : Carbon::parse($birthDate);

        $rules = SeasonAgeGroupRule::query()
            ->where('club_id', $clubId)
            ->where('season_id', $seasonId)
            ->where('sports_modality_id', $modalityId)
            ->where('active', true)
            ->with('ageGroup')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->gender && (! $gender || mb_strtolower($rule->gender) !== mb_strtolower($gender))) {
                continue;
            }

            $year = $birth->year;
            if ($rule->birth_year_min !== null && $year < $rule->birth_year_min) {
                continue;
            }
            if ($rule->birth_year_max !== null && $year > $rule->birth_year_max) {
                continue;
            }

            // Never use the current date: age-group placement must be stable for
            // the season. A rule may define its own reference date; otherwise the
            // season end date is the deterministic reference for that season.
            $reference = $rule->reference_date
                ? Carbon::parse($rule->reference_date)
                : Carbon::parse($season->data_fim);

            if ($birth->greaterThan($reference)) {
                continue;
            }

            // Carbon 3 returns fractional years from diffInYears(). Sporting age
            // rules are based on completed years, so 18 years and 11 months must
            // still resolve as age 18 rather than failing an age_max=18 rule.
            $age = (int) floor($birth->diffInYears($reference, true));

            if ($rule->age_min !== null && $age < $rule->age_min) {
                continue;
            }
            if ($rule->age_max !== null && $age > $rule->age_max) {
                continue;
            }

            return [
                'age_group' => $rule->ageGroup,
                'source' => 'rule',
                'rule_id' => $rule->id,
                'reference_date' => $reference->toDateString(),
            ];
        }

        return null;
    }
}
