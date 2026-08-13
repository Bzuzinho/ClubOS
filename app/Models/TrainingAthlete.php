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
    protected $fillable = ['treino_id','user_id','presente','estado','volume_real_m','rpe','observacoes_tecnicas','registado_por','registado_em','atualizado_por_utilizador_em','atualizado_por'];
    protected $casts = ['presente'=>'boolean','volume_real_m'=>'integer','rpe'=>'integer','registado_em'=>'datetime','atualizado_por_utilizador_em'=>'datetime'];

    public function training(): BelongsTo { return $this->belongsTo(Training::class, 'treino_id'); }
    public function treino(): BelongsTo { return $this->training(); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function atleta(): BelongsTo { return $this->athlete(); }
    public function metrics(): HasMany { return $this->hasMany(TrainingMetric::class, 'training_athlete_id'); }
    public function registeredBy(): BelongsTo { return $this->belongsTo(User::class, 'registado_por'); }
}
