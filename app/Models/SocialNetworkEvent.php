<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SocialNetworkEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider', 'external_event_id', 'event_type', 'provider_message_id', 'recipient_id',
        'payload_hash', 'status', 'occurred_at', 'received_at', 'processed_at', 'error_message',
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
