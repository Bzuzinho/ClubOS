<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingMetric extends Model
{
    use HasUuids;

    protected $table = 'training_metrics';
    protected $fillable = ['treino_id','training_id','training_athlete_id','user_id','ordem','metrica','valor','tempo','recorded_at','observacao','registado_por','atualizado_por'];
    protected $casts = ['ordem'=>'integer','recorded_at'=>'datetime'];

    public function training(): BelongsTo { return $this->belongsTo(Training::class, 'treino_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function atleta(): BelongsTo { return $this->athlete(); }
    public function trainingAthlete(): BelongsTo { return $this->belongsTo(TrainingAthlete::class, 'training_athlete_id'); }
}
