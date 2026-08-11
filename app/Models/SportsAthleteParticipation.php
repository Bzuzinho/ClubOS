<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsAthleteParticipation extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'user_id',
        'sports_modality_id',
        'active',
        'current_slot',
        'starts_at',
        'ends_at',
        'source',
        'start_reason',
        'end_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(SportsModality::class, 'sports_modality_id');
    }

    public function seasonProfiles(): HasMany
    {
        return $this->hasMany(SportsAthleteSeasonProfile::class, 'sports_athlete_participation_id');
    }

    public function federationAffiliations(): HasMany
    {
        return $this->hasMany(SportsAthleteFederationAffiliation::class, 'sports_athlete_participation_id');
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->where('current_slot', 'current')->whereNull('ends_at');
    }
}
