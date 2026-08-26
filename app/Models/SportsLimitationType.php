<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class SportsLimitationType extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'descricao',
        'instrucao_padrao',
        'allows_training',
        'allows_competition',
        'requires_end_date',
        'ativo',
        'ordem',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'allows_training' => 'boolean',
        'allows_competition' => 'boolean',
        'requires_end_date' => 'boolean',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }

    public function scopeAtivo($query)
    {
        return $query->where('ativo', true)->whereNull('archived_at');
    }

    public function scopeOrdenado($query)
    {
        return $query->orderBy('ordem')->orderBy('nome');
    }
}
