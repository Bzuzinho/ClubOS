<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransactionAllocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_statement_id',
        'invoice_id',
        'user_id',
        'payment_id',
        'payment_allocation_id',
        'receipt_import_item_id',
        'mapa_conciliacao_id',
        'valor_alocado',
        'status',
        'origem',
        'metadata',
        'created_by',
        'committed_by',
        'committed_at',
    ];

    protected $casts = [
        'valor_alocado' => 'decimal:2',
        'metadata' => 'array',
        'committed_at' => 'datetime',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }

    public function receiptImportItem(): BelongsTo
    {
        return $this->belongsTo(ReceiptImportItem::class, 'receipt_import_item_id');
    }

    public function reconciliationMap(): BelongsTo
    {
        return $this->belongsTo(MapaConciliacao::class, 'mapa_conciliacao_id');
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