<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPlanSeries extends Model
{
    use HasUuids;

    protected $table = 'training_plan_series';

    protected $fillable = [
        'club_id',
        'training_plan_version_id',
        'ordem',
        'bloco',
        'repeticoes',
        'distancia_m',
        'distancia_total_m',
        'exercicio',
        'estilo',
        'zona_intensidade',
        'intervalo',
        'saida',
        'material',
        'observacoes',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'repeticoes' => 'integer',
        'distancia_m' => 'integer',
        'distancia_total_m' => 'integer',
        'material' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id');
    }

    public function sessionSeries(): HasMany
    {
        return $this->hasMany(TrainingSeries::class, 'training_plan_series_id');
    }
}
