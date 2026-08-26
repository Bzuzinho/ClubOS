<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPlanSeries extends Model
{
    use HasUuids;

    protected $table = 'training_plan_series';
    protected $fillable = ['club_id','training_plan_version_id','training_plan_block_id','ordem','bloco','repeticoes','distancia_m','distancia_total_m','exercicio','estilo','sports_stroke_id','zona_intensidade','training_zone_config_id','intervalo','saida','timing_mode','material','observacoes'];
    protected $casts = ['ordem' => 'integer','repeticoes' => 'integer','distancia_m' => 'integer','distancia_total_m' => 'integer','material' => 'array'];

    public function version(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id'); }
    public function block(): BelongsTo { return $this->belongsTo(TrainingPlanBlock::class, 'training_plan_block_id'); }
    public function zone(): BelongsTo { return $this->belongsTo(TrainingZoneConfig::class, 'training_zone_config_id'); }
    public function stroke(): BelongsTo { return $this->belongsTo(SportsStroke::class, 'sports_stroke_id'); }
    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(SportsTrainingMaterial::class, 'training_plan_series_materials', 'training_plan_series_id', 'sports_training_material_id')
            ->withPivot(['quantity', 'notes'])->withTimestamps();
    }
    public function sessionSeries(): HasMany { return $this->hasMany(TrainingSeries::class, 'training_plan_series_id'); }
}
