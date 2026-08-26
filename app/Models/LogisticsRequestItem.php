<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsRequestItem extends Model
{
    use HasUuids;

    protected $table = 'logistics_request_items';

    protected $fillable = [
        'logistics_request_id',
        'article_id',
        'article_name_snapshot',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LogisticsRequest::class, 'logistics_request_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article_id');
    }
}
