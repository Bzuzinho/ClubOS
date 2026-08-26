<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingAthleteCaisMetric extends Model
{
    use HasUuids;

    protected $table = 'training_athlete_cais_metrics';

    protected $fillable = [
        'treino_id',
        'user_id',
        'ordem',
        'metrica',
        'valor',
        'tempo',
        'observacao',
        'registado_por',
        'atualizado_por',
    ];

    protected $casts = [
        'ordem' => 'integer',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'treino_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registado_por');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }
}
