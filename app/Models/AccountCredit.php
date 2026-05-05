<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountCredit extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_PARTIALLY_USED = 'partially_used';
    public const STATUS_USED = 'used';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'family_id',
        'payment_id',
        'amount',
        'remaining_amount',
        'source',
        'status',
        'description',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'family_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_AVAILABLE, self::STATUS_PARTIALLY_USED]);
    }
}