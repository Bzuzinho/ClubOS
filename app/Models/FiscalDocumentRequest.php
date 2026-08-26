<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FiscalDocumentRequest extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const PROVIDER_WINTOUCH = 'wintouch';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_ERROR_DATA = 'error_data';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_API_ERROR = 'api_error';

    public const DOCUMENT_TYPE_INVOICE = 'invoice';
    public const DOCUMENT_TYPE_RECEIPT = 'receipt';
    public const DOCUMENT_TYPE_INVOICE_RECEIPT = 'invoice_receipt';
    public const DOCUMENT_TYPE_CREDIT_NOTE = 'credit_note';
    public const DOCUMENT_TYPE_OTHER = 'other';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ISSUED,
        self::STATUS_ERROR_DATA,
        self::STATUS_CANCELLED,
        self::STATUS_NOT_APPLICABLE,
        self::STATUS_API_ERROR,
    ];

    public const DOCUMENT_TYPES = [
        self::DOCUMENT_TYPE_INVOICE,
        self::DOCUMENT_TYPE_RECEIPT,
        self::DOCUMENT_TYPE_INVOICE_RECEIPT,
        self::DOCUMENT_TYPE_CREDIT_NOTE,
        self::DOCUMENT_TYPE_OTHER,
    ];

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    protected $fillable = [
        'invoice_id',
        'user_id',
        'bank_statement_id',
        'mapa_conciliacao_id',
        'financial_entry_id',
        'provider',
        'document_type',
        'status',
        'priority',
        'amount',
        'paid_at',
        'due_at',
        'customer_name',
        'customer_tax_number',
        'customer_email',
        'customer_address',
        'description',
        'internal_reference',
        'cost_center_id',
        'external_document_number',
        'external_document_id',
        'external_document_url',
        'external_series',
        'issued_at',
        'issued_by',
        'handled_by',
        'handled_at',
        'last_error',
        'notes',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
        'due_at' => 'date',
        'issued_at' => 'datetime',
        'handled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function mapaConciliacao(): BelongsTo
    {
        return $this->belongsTo(MapaConciliacao::class, 'mapa_conciliacao_id');
    }

    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'financial_entry_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])
            ->whereJsonContains('metadata->internal_due_at_explicit', true)
            ->whereDate('due_at', '<', now()->toDateString());
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function isOverdue(): bool
    {
        if (!$this->due_at || ($this->metadata['internal_due_at_explicit'] ?? false) !== true) {
            return false;
        }

        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true)
            && $this->due_at->isBefore(today());
    }
}
