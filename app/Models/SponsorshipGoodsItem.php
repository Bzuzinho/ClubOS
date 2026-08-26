<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipGoodsItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'sponsorship_id',
        'item_name',
        'item_id',
        'category',
        'quantity',
        'unit_value',
        'total_value',
        'stock_entry_id',
        'integration_status',
        'integration_message',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_value' => 'decimal:2',
        'total_value' => 'decimal:2',
    ];

    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'item_id');
    }

    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_entry_id');
    }
}