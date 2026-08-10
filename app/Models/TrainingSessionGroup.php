<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TrainingSessionGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_id',
        'training_group_id',
        'training_plan_version_id',
        'instruction',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id');
    }

    public function lanes(): BelongsToMany
    {
        return $this->belongsToMany(
            SportsVenueLane::class,
            'training_session_group_lanes',
            'training_session_group_id',
            'sports_venue_lane_id'
        )->withPivot(['club_id', 'planned_capacity'])->withTimestamps();
    }
}
