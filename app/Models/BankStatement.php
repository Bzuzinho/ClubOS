<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    use HasUuids;

    protected $table = 'bank_statements';

    protected $fillable = [
        'conta',
        'data_movimento',
        'descricao',
        'valor',
        'saldo',
        'referencia',
        'ficheiro_id',
        'centro_custo_id',
        'conciliado',
        'valor_conciliado',
        'valor_por_conciliar',
        'conciliacao_status',
        'lancamento_id',
    ];

    protected $casts = [
        'data_movimento' => 'date',
        'valor' => 'decimal:2',
        'saldo' => 'decimal:2',
        'conciliado' => 'boolean',
        'valor_conciliado' => 'decimal:2',
        'valor_por_conciliar' => 'decimal:2',
    ];

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'lancamento_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_statement_id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(BankReconciliationSuggestion::class, 'bank_statement_id');
    }

    public function reconciliationMaps(): HasMany
    {
        return $this->hasMany(MapaConciliacao::class, 'extrato_id');
    }
}
