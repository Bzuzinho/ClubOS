<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementDocumentRequirement extends Model
{
    use HasUuids;

    protected $fillable = [
        'movement_classification',
        'movement_type',
        'category',
        'supplier_id',
        'requires_invoice',
        'requires_receipt',
        'requires_payment_proof',
        'requires_bank_match',
        'active',
    ];

    protected $casts = [
        'requires_invoice' => 'boolean',
        'requires_receipt' => 'boolean',
        'requires_payment_proof' => 'boolean',
        'requires_bank_match' => 'boolean',
        'active' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}