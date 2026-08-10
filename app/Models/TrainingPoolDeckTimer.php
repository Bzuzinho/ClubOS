<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPoolDeckTimer extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_id',
        'training_athlete_id',
        'user_id',
        'training_series_id',
        'subject_type',
        'subject_key',
        'exercise_label',
        'repetition_number',
        'timer_state',
        'elapsed_ms',
        'started_at',
        'last_resumed_at',
        'stopped_at',
        'client_timer_id',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'repetition_number' => 'integer',
        'elapsed_ms' => 'integer',
        'version' => 'integer',
        'started_at' => 'datetime',
        'last_resumed_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    public function trainingAthlete(): BelongsTo
    {
        return $this->belongsTo(TrainingAthlete::class, 'training_athlete_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(TrainingSeries::class, 'training_series_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrainingPoolDeckTimerEvent::class, 'timer_id')->orderBy('occurred_at');
    }
}