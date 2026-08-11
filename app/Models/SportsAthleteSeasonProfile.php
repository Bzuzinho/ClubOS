<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsAthleteSeasonProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'user_id',
        'sports_athlete_participation_id',
        'season_id',
        'sports_modality_id',
        'calculated_age_group_id',
        'official_age_group_id',
        'placement_source',
        'season_age_group_rule_id',
        'athlete_age_group_override_id',
        'reference_date',
        'evaluated_at',
        'evaluated_by',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'evaluated_at' => 'datetime',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(SportsAthleteParticipation::class, 'sports_athlete_participation_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(SportsModality::class, 'sports_modality_id');
    }

    public function calculatedAgeGroup(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'calculated_age_group_id');
    }

    public function officialAgeGroup(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'official_age_group_id');
    }
}
