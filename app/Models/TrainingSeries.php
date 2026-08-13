<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSeries extends Model
{
    use HasUuids;

    protected $table = 'training_series';
    protected $fillable = ['treino_id','ordem','descricao_texto','distancia_total_m','zona_intensidade','training_zone_config_id','estilo','sports_stroke_id','repeticoes','intervalo','observacoes','training_plan_version_id','training_plan_series_id','source','bloco','block_name','block_order','block_rounds','distancia_m','saida','timing_mode','material'];
    protected $casts = ['ordem' => 'integer','distancia_total_m' => 'integer','distancia_m' => 'integer','repeticoes' => 'integer','block_order' => 'integer','block_rounds' => 'integer','material' => 'array'];

    public function training(): BelongsTo { return $this->belongsTo(Training::class, 'treino_id'); }
    public function planVersion(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id'); }
    public function planSeries(): BelongsTo { return $this->belongsTo(TrainingPlanSeries::class, 'training_plan_series_id'); }
    public function zone(): BelongsTo { return $this->belongsTo(TrainingZoneConfig::class, 'training_zone_config_id'); }
    public function stroke(): BelongsTo { return $this->belongsTo(SportsStroke::class, 'sports_stroke_id'); }
}
