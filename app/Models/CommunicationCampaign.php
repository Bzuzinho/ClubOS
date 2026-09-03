<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationCampaign extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'codigo',
        'title',
        'description',
        'segment_id',
        'author_id',
        'status',
        'scheduled_at',
        'sent_at',
        'create_in_app_alert',
        'alert_title',
        'alert_message',
        'alert_link',
        'alert_type',
        'notes',
        'source_type',
        'source_id',
        'idempotency_key',
        'dispatch_requested_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'create_in_app_alert' => 'boolean',
        'dispatch_requested_at' => 'datetime',
    ];

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CommunicationSegment::class, 'segment_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(CommunicationCampaignChannel::class, 'campaign_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunicationDelivery::class, 'campaign_id');
    }

    public function inAppAlerts(): HasMany
    {
        return $this->hasMany(InAppAlert::class, 'campaign_id');
    }
}
