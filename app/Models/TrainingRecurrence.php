<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingRecurrence extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'name',
        'starts_on',
        'ends_on',
        'frequency',
        'interval',
        'weekdays',
        'start_time',
        'end_time',
        'sports_venue_id',
        'local_snapshot',
        'responsavel_id',
        'training_plan_version_id',
        'instruction',
        'training_type',
        'session_status_template',
        'active',
        'last_generated_until',
        'created_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'weekdays' => 'array',
        'interval' => 'integer',
        'active' => 'boolean',
        'last_generated_until' => 'date',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(SportsVenue::class, 'sports_venue_id');
    }

    public function responsibleCoach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TrainingRecurrenceGroup::class, 'training_recurrence_id')->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Training::class, 'training_recurrence_id')->orderBy('data')->orderBy('hora_inicio');
    }
}
