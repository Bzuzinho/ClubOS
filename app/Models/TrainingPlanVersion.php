<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPlanVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_plan_id',
        'version',
        'nome_snapshot',
        'tipo_treino',
        'descricao_treino',
        'notas_gerais',
        'volume_planeado_m',
        'instrucao',
        'motivo_revisao',
        'metadados',
        'criado_por',
        'publicado_em',
    ];

    protected $casts = [
        'version' => 'integer',
        'volume_planeado_m' => 'integer',
        'metadados' => 'array',
        'publicado_em' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'training_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function series(): HasMany
    {
        return $this->hasMany(TrainingPlanSeries::class, 'training_plan_version_id')
            ->orderBy('ordem');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Training::class, 'training_plan_version_id');
    }
}
