<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TrainingRecurrenceGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'training_recurrence_id', 'training_group_id',
        'training_plan_version_id', 'instruction', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    public function recurrence(): BelongsTo { return $this->belongsTo(TrainingRecurrence::class, 'training_recurrence_id'); }
    public function group(): BelongsTo { return $this->belongsTo(TrainingGroup::class, 'training_group_id'); }
    public function planVersion(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id'); }

    public function lanes(): BelongsToMany
    {
        return $this->belongsToMany(
            SportsPoolLane::class,
            'training_recurrence_group_lanes',
            'training_recurrence_group_id',
            'sports_pool_lane_id'
        )->withPivot(['club_id', 'planned_capacity'])->withTimestamps();
    }
}
