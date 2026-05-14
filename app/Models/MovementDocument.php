<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'movement_id',
        'supplier_id',
        'document_type',
        'source_type',
        'source_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'sha256_hash',
        'document_number',
        'issue_date',
        'due_date',
        'amount',
        'vat_amount',
        'status',
        'is_required',
        'validated_at',
        'validated_by',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'is_required' => 'boolean',
        'validated_at' => 'datetime',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'movement_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}