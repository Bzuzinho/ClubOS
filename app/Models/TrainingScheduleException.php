<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingScheduleException extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_id',
        'exception_type',
        'before_state',
        'after_state',
        'reason',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
