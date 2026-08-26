<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsFederation extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'code',
        'name',
        'country_code',
        'active',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function affiliations(): HasMany
    {
        return $this->hasMany(SportsAthleteFederationAffiliation::class, 'sports_federation_id');
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }
}
