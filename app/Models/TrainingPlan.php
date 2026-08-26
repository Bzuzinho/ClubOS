<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TrainingPlan extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['club_id','nome','codigo','descricao','modalidade','sports_modality_id','tags','estado','criado_por'];
    protected $casts = ['tags' => 'array'];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'criado_por'); }
    public function modality(): BelongsTo { return $this->belongsTo(SportsModality::class, 'sports_modality_id'); }
    public function versions(): HasMany { return $this->hasMany(TrainingPlanVersion::class, 'training_plan_id')->orderBy('version'); }
    public function currentVersion(): HasOne { return $this->hasOne(TrainingPlanVersion::class, 'training_plan_id')->ofMany('version', 'max'); }
}
