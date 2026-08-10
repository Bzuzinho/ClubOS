<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingPoolDeckSyncConflict extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_id',
        'entity_type',
        'entity_id',
        'field',
        'client_value',
        'server_value',
        'client_version',
        'server_version',
        'client_event_id',
        'recorded_by',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'client_value' => 'array',
        'server_value' => 'array',
        'client_version' => 'integer',
        'server_version' => 'integer',
        'resolved_at' => 'datetime',
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