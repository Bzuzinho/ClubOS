<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PoolTypeConfig extends Model
{
    use HasUuids;

    protected $table = 'pool_type_configs';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'comprimento_m',
        'is_open_water',
        'ativo',
        'ordem',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'comprimento_m' => 'integer',
        'is_open_water' => 'boolean',
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

    public function isOlimpica(): bool
    {
        return !$this->is_open_water && $this->comprimento_m === 50;
    }

    public function isPiscinaCurta(): bool
    {
        return !$this->is_open_water && $this->comprimento_m === 25;
    }

    public function isAguaAberta(): bool
    {
        return (bool) $this->is_open_water;
    }

    public function calcularVoltasParaDistancia(int $distanciaMetros): ?int
    {
        if ($this->is_open_water || !$this->comprimento_m) {
            return null;
        }

        return (int) ceil($distanciaMetros / $this->comprimento_m);
    }
}
