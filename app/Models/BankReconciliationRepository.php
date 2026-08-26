<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationRepository extends Model
{
    use HasUuids;

    protected $fillable = [
        'signature',
        'conta',
        'descricao',
        'referencia',
        'normalized_description',
        'normalized_reference',
        'primary_user_id',
        'family_id',
        'matched_user_ids',
        'match_count',
        'last_reconciled_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'matched_user_ids' => 'array',
        'match_count' => 'integer',
        'last_reconciled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function primaryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'family_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}