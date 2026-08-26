<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUuids;


    protected $fillable = [
        'user_id',
        'data_fatura',
        'mes',
        'data_emissao',
        'data_vencimento',
        'valor_total',
        'valor_pago',
        'valor_em_aberto',
        'oculta',
        'estado_pagamento',
        'data_pagamento',
        'numero_recibo',
        'recibo_emitido_em',
        'recibo_pdf_path',
        'receipt_import_item_id',
        'referencia_pagamento',
        'metodo_pagamento',
        'centro_custo_id',
        'tipo',
        'origem_tipo',
        'origem_id',
        'observacoes',
        'pagamento_observacoes',
    ];

    protected $casts = [
        'data_fatura' => 'date',
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'data_pagamento' => 'date',
        'recibo_emitido_em' => 'date',
        'valor_total' => 'decimal:2',
        'valor_pago' => 'decimal:2',
        'valor_em_aberto' => 'decimal:2',
        'oculta' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'fatura_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'invoice_id');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations', 'invoice_id', 'payment_id')
            ->using(PaymentAllocation::class)
            ->withPivot(['id', 'amount', 'status', 'allocated_at', 'notes', 'metadata'])
            ->withTimestamps();
    }

    public function fiscalDocumentRequests(): HasMany
    {
        return $this->hasMany(FiscalDocumentRequest::class, 'invoice_id');
    }

    public function receiptImportItem(): BelongsTo
    {
        return $this->belongsTo(ReceiptImportItem::class, 'receipt_import_item_id');
    }

    public function bankTransactionAllocations(): HasMany
    {
        return $this->hasMany(BankTransactionAllocation::class, 'invoice_id');
    }
}
