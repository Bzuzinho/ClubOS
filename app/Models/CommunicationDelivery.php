<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CommunicationDelivery extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'campaign_id',
        'channel',
        'segment_id',
        'status',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'success_count',
        'failed_count',
        'pending_count',
        'result_summary',
        'error_message',
        'executed_by',
        'idempotency_key',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CommunicationCampaign::class, 'campaign_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CommunicationSegment::class, 'segment_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CommunicationDeliveryRecipient::class, 'delivery_id');
    }

    public function attempts(): HasManyThrough
    {
        return $this->hasManyThrough(
            CommunicationDeliveryAttempt::class,
            CommunicationDeliveryRecipient::class,
            'delivery_id',
            'recipient_id',
        );
    }
}
