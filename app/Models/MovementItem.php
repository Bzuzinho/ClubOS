<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementItem extends Model
{
    use HasUuids;

    protected $table = 'movement_items';

    protected $fillable = [
        'movimento_id',
        'descricao',
        'valor_unitario',
        'quantidade',
        'imposto_percentual',
        'total_linha',
        'produto_id',
        'centro_custo_id',
        'fatura_id',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'valor_unitario' => 'decimal:2',
        'imposto_percentual' => 'decimal:2',
        'total_linha' => 'decimal:2',
    ];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'movimento_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }
}
