<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPurchase extends Model
{
    use HasUuids;

    protected $table = 'supplier_purchases';

    protected $fillable = [
        'supplier_id',
        'supplier_name_snapshot',
        'invoice_reference',
        'invoice_date',
        'total_amount',
        'financial_movement_id',
        // Legacy compatibility only: new SupplierPurchase lifecycle should not write this field.
        'financial_entry_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }

    public function financialEntry(): BelongsTo
    {
        // Legacy relationship retained for historical data reads.
        return $this->belongsTo(FinancialEntry::class, 'financial_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierPurchaseItem::class, 'supplier_purchase_id');
    }
}
