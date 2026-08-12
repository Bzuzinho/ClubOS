<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SportsCommunicationIntent extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'source_type',
        'source_id',
        'source_version',
        'intent_type',
        'idempotency_key',
        'status',
        'campaign_id',
        'payload_json',
        'requested_by',
        'requested_at',
        'dispatched_at',
        'failure_reason',
    ];

    protected $casts = [
        'source_version' => 'integer',
        'payload_json' => 'array',
        'requested_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];
}
