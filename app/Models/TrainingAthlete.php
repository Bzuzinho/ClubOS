<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingAthlete extends Model
{
    use HasUuids;

    protected $table = 'training_athletes';

    protected $fillable = [
        'treino_id',
        'user_id',
        'presente',
        'estado',
        'volume_real_m',
        'rpe',
        'observacoes_tecnicas',
        'registado_por',
        'registado_em',
        'atualizado_por_utilizador_em',
        'atualizado_por',
        'cais_version',
        'cais_status_source',
        'cais_last_modified_at',
        'cais_last_modified_by',
    ];

    protected $casts = [
        'presente' => 'boolean',
        'volume_real_m' => 'integer',
        'rpe' => 'integer',
        'registado_em' => 'datetime',
        'atualizado_por_utilizador_em' => 'datetime',
        'cais_version' => 'integer',
        'cais_last_modified_at' => 'datetime',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'treino_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function atleta(): BelongsTo
    {
        return $this->athlete();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(TrainingMetric::class, 'training_athlete_id');
    }

    public function poolDeckTimers(): HasMany
    {
        return $this->hasMany(TrainingPoolDeckTimer::class, 'training_athlete_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registado_por');
    }

    public function caisLastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cais_last_modified_by');
    }
}