<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'sender_id',
        'parent_id',
        'subject',
        'message',
        'type',
        'sender_deleted_at',
    ];

    protected $casts = [
        'sender_deleted_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(InternalMessageRecipient::class, 'internal_message_id');
    }
}