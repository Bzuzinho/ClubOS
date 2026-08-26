<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasUuids;

    protected $fillable = [
        'codigo',
        'nome',
        'descricao',
        'requer_linha_bancaria',
        'ativo',
        'ordem',
    ];

    protected $casts = [
        'requer_linha_bancaria' => 'boolean',
        'ativo' => 'boolean',
        'ordem' => 'integer',
    ];

    public function scopeAtivo($query)
    {
        return $query->where('ativo', true);
    }

    public function scopeOrdenado($query)
    {
        return $query->orderBy('ordem')->orderBy('nome');
    }
}
