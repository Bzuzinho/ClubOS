<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationDeliveryAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'recipient_id',
        'attempt_number',
        'status',
        'provider',
        'provider_message_id',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
        'next_retry_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CommunicationDeliveryRecipient::class, 'recipient_id');
    }
}
