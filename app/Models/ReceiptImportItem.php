<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptImportItem extends Model
{
    use HasUuids;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_NEEDS_USER = 'needs_user';
    public const STATUS_NEEDS_INVOICE = 'needs_invoice';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IMPORTED = 'imported';

    protected $fillable = [
        'batch_id',
        'user_id',
        'invoice_id',
        'bank_statement_id',
        'duplicate_of_item_id',
        'status',
        'confidence_score',
        'file_name',
        'storage_path',
        'file_hash',
        'numero_recibo',
        'recibo_emitido_em',
        'valor',
        'extracted_name',
        'extracted_nif',
        'extracted_member_number',
        'extracted_email',
        'extracted_period_label',
        'extracted_period_start',
        'extracted_period_end',
        'extracted_text',
        'extraction_payload',
        'match_candidates',
        'metadata',
        'failure_reason',
        'committed_at',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'valor' => 'decimal:2',
        'recibo_emitido_em' => 'date',
        'extracted_period_start' => 'date',
        'extracted_period_end' => 'date',
        'extraction_payload' => 'array',
        'match_candidates' => 'array',
        'metadata' => 'array',
        'committed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReceiptImportBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_item_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_item_id');
    }

    public function bankTransactionAllocations(): HasMany
    {
        return $this->hasMany(BankTransactionAllocation::class, 'receipt_import_item_id');
    }
}