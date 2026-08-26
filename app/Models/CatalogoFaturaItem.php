<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class CatalogoFaturaItem extends Model
{
    use HasUuids;

    protected $table = 'catalogo_fatura_itens';

    protected $fillable = [
        'descricao',
        'valor_unitario',
        'imposto_percentual',
        'tipo',
        'ativo',
    ];

    protected $casts = [
        'valor_unitario' => 'decimal:2',
        'imposto_percentual' => 'decimal:2',
        'ativo' => 'boolean',
    ];
}
