<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsVenueLane extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'sports_venue_id',
        'code',
        'name',
        'lane_number',
        'capacity',
        'active',
        'metadata',
    ];

    protected $casts = [
        'lane_number' => 'integer',
        'capacity' => 'integer',
        'active' => 'boolean',
        'metadata' => 'array',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(SportsVenue::class, 'sports_venue_id');
    }

    public function closures(): HasMany
    {
        return $this->hasMany(SportsVenueClosure::class, 'sports_venue_lane_id');
    }

    public function sessionGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            TrainingSessionGroup::class,
            'training_session_group_lanes',
            'sports_venue_lane_id',
            'training_session_group_id'
        )->withPivot(['club_id', 'planned_capacity'])->withTimestamps();
    }

    public function recurrenceGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            TrainingRecurrenceGroup::class,
            'training_recurrence_group_lanes',
            'sports_venue_lane_id',
            'training_recurrence_group_id'
        )->withPivot(['club_id', 'planned_capacity'])->withTimestamps();
    }
}
