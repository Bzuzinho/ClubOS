<?php

namespace App\Services\Desportivo;

use App\Models\AthleteAgeGroupOverride;
use App\Models\SeasonAgeGroupRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class AgeGroupPlacementService
{
    public function resolve(string $clubId, string $userId, string $seasonId, string $modalityId, CarbonInterface|string $birthDate, ?string $gender = null): ?array
    {
        $override = AthleteAgeGroupOverride::query()->where('club_id',$clubId)->where('user_id',$userId)->where('season_id',$seasonId)->where('sports_modality_id',$modalityId)->where('active',true)->with('ageGroup')->latest('effective_at')->first();
        if ($override) return ['age_group'=>$override->ageGroup,'source'=>'override','override_id'=>$override->id];

        $birth = $birthDate instanceof CarbonInterface ? Carbon::instance($birthDate) : Carbon::parse($birthDate);
        $rules = SeasonAgeGroupRule::query()->where('club_id',$clubId)->where('season_id',$seasonId)->where('sports_modality_id',$modalityId)->where('active',true)->with('ageGroup')->orderByDesc('priority')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if ($rule->gender && (! $gender || mb_strtolower($rule->gender) !== mb_strtolower($gender))) continue;
            $year = $birth->year;
            if ($rule->birth_year_min !== null && $year < $rule->birth_year_min) continue;
            if ($rule->birth_year_max !== null && $year > $rule->birth_year_max) continue;
            $reference = $rule->reference_date ? Carbon::parse($rule->reference_date) : now();
            $age = $birth->diffInYears($reference);
            if ($rule->age_min !== null && $age < $rule->age_min) continue;
            if ($rule->age_max !== null && $age > $rule->age_max) continue;
            return ['age_group'=>$rule->ageGroup,'source'=>'rule','rule_id'=>$rule->id];
        }
        return null;
    }
}
