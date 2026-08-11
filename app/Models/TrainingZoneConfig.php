<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TrainingZoneConfig extends Model
{
    use HasUuids;

    protected $table = 'training_zone_configs';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'descricao',
        'percentagem_min',
        'percentagem_max',
        'cor',
        'ativo',
        'ordem',
        'is_recovery',
        'is_high_intensity',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'percentagem_min' => 'integer',
        'percentagem_max' => 'integer',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'is_recovery' => 'boolean',
        'is_high_intensity' => 'boolean',
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

    public static function getZonaPorPercentagem(int $percentagem): ?self
    {
        return self::ativo()
            ->where('percentagem_min', '<=', $percentagem)
            ->where('percentagem_max', '>=', $percentagem)
            ->first();
    }

    public function isRecoveryZone(): bool
    {
        return (bool) $this->is_recovery;
    }

    public function isHighIntensityZone(): bool
    {
        return (bool) $this->is_high_intensity;
    }
}
