<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunicationDeliveryRecipient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'delivery_id',
        'user_id',
        'member_id',
        'contact_email',
        'contact_phone',
        'push_token',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'idempotency_key',
        'attempt_count',
        'max_attempts',
        'provider',
        'provider_message_id',
        'processing_at',
        'last_attempt_at',
        'next_attempt_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'processing_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(CommunicationDelivery::class, 'delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CommunicationDeliveryAttempt::class, 'recipient_id');
    }
}
