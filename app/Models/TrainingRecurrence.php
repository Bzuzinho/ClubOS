<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingRecurrence extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'name', 'starts_on', 'ends_on', 'frequency', 'interval', 'weekdays',
        'start_time', 'end_time', 'season_id', 'macrocycle_id', 'mesocycle_id', 'microcycle_id',
        'sports_venue_id', 'sports_pool_id', 'local_snapshot', 'responsavel_id',
        'training_plan_version_id', 'instruction', 'training_type', 'session_status_template',
        'active', 'last_generated_until', 'created_by', 'updated_by', 'archived_at',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'weekdays' => 'array',
        'interval' => 'integer',
        'active' => 'boolean',
        'last_generated_until' => 'date',
        'archived_at' => 'datetime',
    ];

    public function season(): BelongsTo { return $this->belongsTo(Season::class, 'season_id'); }
    public function macrocycle(): BelongsTo { return $this->belongsTo(Macrocycle::class, 'macrocycle_id'); }
    public function mesocycle(): BelongsTo { return $this->belongsTo(Mesocycle::class, 'mesocycle_id'); }
    public function microcycle(): BelongsTo { return $this->belongsTo(Microcycle::class, 'microcycle_id'); }
    public function venue(): BelongsTo { return $this->belongsTo(SportsVenue::class, 'sports_venue_id'); }
    public function pool(): BelongsTo { return $this->belongsTo(SportsPool::class, 'sports_pool_id'); }
    public function responsibleCoach(): BelongsTo { return $this->belongsTo(User::class, 'responsavel_id'); }
    public function planVersion(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id'); }
    public function groups(): HasMany { return $this->hasMany(TrainingRecurrenceGroup::class, 'training_recurrence_id')->orderBy('sort_order'); }
    public function sessions(): HasMany { return $this->hasMany(Training::class, 'training_recurrence_id')->orderBy('data')->orderBy('hora_inicio'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
