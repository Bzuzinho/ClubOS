<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingTypeConfig extends Model
{
    use HasUuids;

    protected $table = 'training_type_configs';

    protected $fillable = [
        'club_id',
        'codigo',
        'nome',
        'nome_en',
        'descricao',
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

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class, 'tipo_treino', 'codigo');
    }

    public function isRecovery(): bool
    {
        return (bool) $this->is_recovery;
    }

    public function isHighIntensity(): bool
    {
        return (bool) $this->is_high_intensity;
    }
}
