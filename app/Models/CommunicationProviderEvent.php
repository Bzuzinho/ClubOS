<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunicationProviderEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'external_event_id',
        'provider_message_id',
        'event_type',
        'occurred_at',
        'received_at',
        'payload_hash',
        'status',
        'recipient_id',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CommunicationDeliveryRecipient::class, 'recipient_id');
    }
}
