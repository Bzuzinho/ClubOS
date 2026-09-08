<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupplierPurchaseDeletionAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'supplier_purchase_id',
        'supplier_id',
        'supplier_name_snapshot',
        'invoice_reference',
        'invoice_date',
        'total_amount',
        'financial_movement_id',
        'deleted_by',
        'deletion_mode',
        'reason',
        'payload',
        'deleted_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total_amount' => 'decimal:2',
        'payload' => 'array',
        'deleted_at' => 'datetime',
    ];
}
