<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsRequest extends Model
{
    use HasUuids;

    protected $table = 'logistics_requests';

    protected $fillable = [
        'requester_user_id',
        'requester_name_snapshot',
        'requester_area',
        'requester_type',
        'status',
        'approved_at',
        'delivered_at',
        'total_amount',
        'financial_invoice_id',
        'notes',
        'created_by',
        'source_type',
        'source_id',
        'idempotency_key',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'delivered_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function financialInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'financial_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LogisticsRequestItem::class, 'logistics_request_id');
    }
}
