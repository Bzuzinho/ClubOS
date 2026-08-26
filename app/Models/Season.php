<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasUuids;

    /**
     * F2 is expand-first: keep every legacy planning attribute writable until
     * Planeamento is explicitly cut over to the canonical sports structure.
     */
    protected $fillable = [
        'nome',
        'ano_temporada',
        'data_inicio',
        'data_fim',
        'tipo',
        'estado',
        'ativa',
        'piscina_principal',
        'escaloes_abrangidos',
        'descricao',
        'provas_alvo',
        'volume_total_previsto',
        'volume_medio_semanal',
        'num_semanas_previsto',
        'num_competicoes_previstas',
        'objetivos_performance',
        'objetivos_tecnicos',
        'objetivo_principal',
        'objetivo_secundario',
        'created_by',
        'updated_by',
        'club_id',
        'sports_modality_id',
        'status',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'ativa' => 'boolean',
        'escaloes_abrangidos' => 'array',
        'provas_alvo' => 'array',
        'volume_total_previsto' => 'integer',
        'volume_medio_semanal' => 'integer',
        'num_semanas_previsto' => 'integer',
        'num_competicoes_previstas' => 'integer',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function modality(): BelongsTo
    {
        return $this->belongsTo(SportsModality::class, 'sports_modality_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(SeasonProgram::class);
    }

    public function ageGroupRules(): HasMany
    {
        return $this->hasMany(SeasonAgeGroupRule::class);
    }

    public function groupConfigurations(): HasMany
    {
        return $this->hasMany(TrainingGroupSeason::class);
    }

    public function macrocycles(): HasMany
    {
        return $this->hasMany(Macrocycle::class, 'epoca_id');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class, 'epoca_id');
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }
}
