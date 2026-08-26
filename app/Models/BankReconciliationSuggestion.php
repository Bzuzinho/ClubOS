<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankReconciliationSuggestion extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const CONFIDENCE_VERY_HIGH = 'very_high';
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    protected $fillable = [
        'bank_statement_id',
        'user_id',
        'family_id',
        'status',
        'score',
        'confidence_label',
        'total_bank_amount',
        'total_allocated_amount',
        'unallocated_amount',
        'suggested_allocations',
        'matched_rules',
        'explanation',
        'confirmed_by',
        'confirmed_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'suggested_allocations' => 'array',
        'matched_rules' => 'array',
        'metadata' => 'array',
        'total_bank_amount' => 'decimal:2',
        'total_allocated_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'family_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}