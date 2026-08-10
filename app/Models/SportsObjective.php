<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SportsObjective extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'target_type',
        'target_id',
        'modality',
        'status',
        'current_version',
        'starts_at',
        'due_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'current_version' => 'integer',
        'starts_at' => 'date',
        'due_at' => 'date',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(SportsObjectiveVersion::class, 'sports_objective_id')->orderBy('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(SportsObjectiveVersion::class, 'sports_objective_id')->ofMany('version', 'max');
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }
}
