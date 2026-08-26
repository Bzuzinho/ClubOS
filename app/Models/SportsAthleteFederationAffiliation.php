<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsAthleteFederationAffiliation extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'user_id',
        'sports_athlete_participation_id',
        'sports_modality_id',
        'sports_federation_id',
        'membership_number',
        'license_number',
        'starts_at',
        'ends_at',
        'active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'active' => 'boolean',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(SportsAthleteParticipation::class, 'sports_athlete_participation_id');
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(SportsModality::class, 'sports_modality_id');
    }

    public function federation(): BelongsTo
    {
        return $this->belongsTo(SportsFederation::class, 'sports_federation_id');
    }
}
