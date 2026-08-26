<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class AbsenceReasonConfig extends Model
{
    use HasUuids;

    protected $table = 'absence_reason_configs';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'nome_en',
        'descricao',
        'requer_justificacao',
        'health_related',
        'ativo',
        'ordem',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'requer_justificacao' => 'boolean',
        'health_related' => 'boolean',
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

    public function scopeRequerJustificacao($query)
    {
        return $query->where('requer_justificacao', true);
    }

    public function isHealthRelated(): bool
    {
        return (bool) $this->health_related;
    }
}
