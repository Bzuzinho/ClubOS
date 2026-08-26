<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasUuids, HasFactory;


    protected $fillable = [
        'produto_id',
        'cliente_id',
        'vendedor_id',
        'quantidade',
        'preco_unitario',
        'total',
        'data',
        'metodo_pagamento',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'preco_unitario' => 'decimal:2',
        'total' => 'decimal:2',
        'data' => 'datetime',
    ];

    // XFIN8: Legacy Sale financial side effects disabled (2026-07-09)
    // Sale no longer automatically creates Invoice, InvoiceItem, FinancialEntry or modifies stock.
    // Historical data remains readable via relationships.
    // Current store flow: LojaEncomenda -> LojaEncomendaService -> LojaFinanceiroService

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produto_id');
    }

    public function comprador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cliente_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }
}
