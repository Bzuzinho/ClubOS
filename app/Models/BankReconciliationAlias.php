<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationAlias extends Model
{
    use HasUuids;

    public const TYPES = [
        'payer_name',
        'description_text',
        'mb_reference',
        'nif',
        'member_number',
        'iban_partial',
        'phone_mbway',
        'other',
    ];

    protected $fillable = [
        'user_id',
        'family_id',
        'type',
        'value',
        'normalized_value',
        'is_confirmed',
        'confidence',
        'source',
        'last_matched_at',
        'match_count',
        'created_by',
    ];

    protected $casts = [
        'is_confirmed' => 'boolean',
        'confidence' => 'integer',
        'match_count' => 'integer',
        'last_matched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'family_id');
    }
}