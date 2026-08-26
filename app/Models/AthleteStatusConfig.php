<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class AthleteStatusConfig extends Model
{
    use HasUuids;

    protected $table = 'athlete_status_configs';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'nome_en',
        'descricao',
        'cor',
        'ativo',
        'ordem',
        'counts_as_present',
        'requires_reason',
        'allows_training',
        'allows_competition',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'counts_as_present' => 'boolean',
        'requires_reason' => 'boolean',
        'allows_training' => 'boolean',
        'allows_competition' => 'boolean',
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

    public function isPresente(): bool
    {
        return (bool) $this->counts_as_present;
    }

    public function requerJustificacao(): bool
    {
        return (bool) $this->requires_reason;
    }
}
