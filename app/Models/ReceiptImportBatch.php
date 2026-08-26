<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptImportBatch extends Model
{
    use HasUuids;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source_type',
        'source_name',
        'source_path',
        'status',
        'items_count',
        'processed_count',
        'imported_count',
        'notes',
        'metadata',
        'created_by',
        'committed_by',
        'committed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'committed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptImportItem::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}