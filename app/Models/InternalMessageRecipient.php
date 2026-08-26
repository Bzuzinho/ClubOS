<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMessageRecipient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'internal_message_id',
        'recipient_id',
        'in_app_alert_id',
        'is_read',
        'read_at',
        'deleted_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(InternalMessage::class, 'internal_message_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(InAppAlert::class, 'in_app_alert_id');
    }
}