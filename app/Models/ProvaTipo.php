<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProvaTipo extends Model
{
    use HasUuids;

    protected $table = 'prova_tipos';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'distancia',
        'unidade',
        'modalidade',
        'ativo',
        'ordem',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'distancia' => 'integer',
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
        return $query->orderBy('ordem')->orderBy('modalidade')->orderBy('distancia')->orderBy('nome');
    }
}
