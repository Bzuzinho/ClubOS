<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSeries extends Model
{
    use HasUuids;

    protected $table = 'training_series';

    protected $fillable = [
        'treino_id',
        'ordem',
        'descricao_texto',
        'distancia_total_m',
        'zona_intensidade',
        'estilo',
        'repeticoes',
        'intervalo',
        'observacoes',
        'training_plan_version_id',
        'training_plan_series_id',
        'source',
        'bloco',
        'distancia_m',
        'saida',
        'material',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'distancia_total_m' => 'integer',
        'distancia_m' => 'integer',
        'repeticoes' => 'integer',
        'material' => 'array',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'treino_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id');
    }

    public function planSeries(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanSeries::class, 'training_plan_series_id');
    }
}
