<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingMetric extends Model
{
    use HasUuids;

    protected $table = 'training_metrics';

    protected $fillable = [
        'treino_id',
        'training_id',
        'training_athlete_id',
        'user_id',
        'ordem',
        'metrica',
        'valor',
        'tempo',
        'recorded_at',
        'observacao',
        'registado_por',
        'atualizado_por',
        'club_id',
        'training_series_id',
        'measurement_type',
        'total_distance_m',
        'repetition_mode',
        'repetition_number',
        'duration_ms',
        'splits_json',
        'source',
        'client_event_id',
        'client_recorded_at',
        'captured_by',
        'server_version',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'recorded_at' => 'datetime',
        'total_distance_m' => 'integer',
        'repetition_number' => 'integer',
        'duration_ms' => 'integer',
        'splits_json' => 'array',
        'client_recorded_at' => 'datetime',
        'server_version' => 'integer',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'treino_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function trainingAthlete(): BelongsTo
    {
        return $this->belongsTo(TrainingAthlete::class, 'training_athlete_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(TrainingSeries::class, 'training_series_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}