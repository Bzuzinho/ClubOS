<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingPoolDeckTimerEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'timer_id',
        'training_id',
        'event_type',
        'elapsed_ms',
        'occurred_at',
        'client_event_id',
        'payload',
        'recorded_by',
    ];

    protected $casts = [
        'elapsed_ms' => 'integer',
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function timer(): BelongsTo
    {
        return $this->belongsTo(TrainingPoolDeckTimer::class, 'timer_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}