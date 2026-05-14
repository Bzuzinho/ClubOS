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
        'raw_description',
        'extracted_after_de',
        'normalized_value',
        'normalized_alias',
        'is_confirmed',
        'confidence',
        'confidence_score',
        'source',
        'last_matched_at',
        'last_used_at',
        'match_count',
        'usage_count',
        'created_by',
    ];

    protected $casts = [
        'is_confirmed' => 'boolean',
        'confidence' => 'integer',
        'confidence_score' => 'decimal:2',
        'match_count' => 'integer',
        'usage_count' => 'integer',
        'last_matched_at' => 'datetime',
        'last_used_at' => 'datetime',
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