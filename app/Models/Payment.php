<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BANK_STATEMENT = 'bank_statement';
    public const SOURCE_RECONCILIATION = 'reconciliation';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_ADJUSTMENT = 'adjustment';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'family_id',
        'bank_statement_id',
        'amount',
        'allocated_amount',
        'unallocated_amount',
        'payment_date',
        'method',
        'reference',
        'description',
        'source',
        'status',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
        'payment_date' => 'date',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'family_id');
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'payment_allocations', 'payment_id', 'invoice_id')
            ->using(PaymentAllocation::class)
            ->withPivot(['id', 'amount', 'status', 'allocated_at', 'notes', 'metadata'])
            ->withTimestamps();
    }

    public function financialEntries(): BelongsToMany
    {
        return $this->belongsToMany(FinancialEntry::class, 'payment_allocations', 'payment_id', 'financial_entry_id')
            ->using(PaymentAllocation::class)
            ->withPivot(['id', 'amount', 'status', 'allocated_at', 'notes', 'metadata'])
            ->withTimestamps();
    }

    public function credits(): HasMany
    {
        return $this->hasMany(AccountCredit::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }
}