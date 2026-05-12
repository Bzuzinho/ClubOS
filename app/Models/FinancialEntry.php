<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialEntry extends Model
{
    use HasUuids;

    protected $table = 'financial_entries';

    protected $fillable = [
        'data',
        'tipo',
        'categoria',
        'descricao',
        'documento_ref',
        'valor',
        'valor_pago',
        'valor_em_aberto',
        'estado',
        'data_pagamento',
        'centro_custo_id',
        'user_id',
        'fatura_id',
        'payment_id',
        'bank_statement_id',
        'origem_tipo',
        'origem_modulo',
        'origem_id',
        'entidade_nome',
        'documento_original',
        'metodo_pagamento',
        'comprovativo',
        'fiscal_document_request_id',
    ];

    protected $casts = [
        'data' => 'date',
        'valor' => 'decimal:2',
        'valor_pago' => 'decimal:2',
        'valor_em_aberto' => 'decimal:2',
        'data_pagamento' => 'date',
    ];

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fatura_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function fiscalDocumentRequest(): BelongsTo
    {
        return $this->belongsTo(FiscalDocumentRequest::class, 'fiscal_document_request_id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'financial_entry_id');
    }

    public function reconciliationMap(): HasOne
    {
        return $this->hasOne(MapaConciliacao::class, 'lancamento_id');
    }

    public function bankStatements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'lancamento_id');
    }
}
