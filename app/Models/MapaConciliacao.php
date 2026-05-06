<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MapaConciliacao extends Model
{
    use HasUuids;

    protected $table = 'mapa_conciliacao';

    protected $fillable = [
        'extrato_id',
        'lancamento_id',
        'fatura_id',
        'movimento_id',
        'payment_id',
        'payment_allocation_id',
        'bank_reconciliation_suggestion_id',
        'estado_fatura_anterior',
        'estado_movimento_anterior',
        'valor_conciliado',
        'status',
        'regra_usada',
        'score',
        'metadata',
    ];

    protected $casts = [
        'valor_conciliado' => 'decimal:2',
        'score' => 'integer',
        'metadata' => 'array',
    ];

    public function extrato(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'extrato_id');
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class, 'lancamento_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'fatura_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationSuggestion::class, 'bank_reconciliation_suggestion_id');
    }
}
