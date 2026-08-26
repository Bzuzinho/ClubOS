<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movement extends Model
{
    use HasUuids;


    protected $fillable = [
        'user_id',
        'supplier_id',
        'nome_manual',
        'nif_manual',
        'morada_manual',
        'classificacao',
        'categoria',
        'data_emissao',
        'data_vencimento',
        'valor_total',
        'estado_pagamento',
        'estado_conciliacao',
        'estado_documental',
        'document_control_status',
        'numero_recibo',
        'referencia_pagamento',
        'metodo_pagamento',
        'comprovativo',
        'documento_original',
        'centro_custo_id',
        'tipo',
        'origem_tipo',
        'origem_id',
        'observacoes',
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_vencimento' => 'date',
        'valor_total' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'origem_id')
            ->where('origem_tipo', 'movement');
    }

    public function latestFinancialEntry(): HasOne
    {
        return $this->hasOne(FinancialEntry::class, 'origem_id')
            ->where('origem_tipo', 'movement')
            ->orderByDesc('created_at');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MovementItem::class, 'movimento_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MovementDocument::class, 'movement_id');
    }
}
