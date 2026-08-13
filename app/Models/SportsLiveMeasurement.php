<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsLiveMeasurement extends Model
{
    use HasUuids;

    protected $fillable = ['monitoring_id','training_id','training_series_id','repetition_number','round_number','state','started_at','ended_at','started_by','client_measurement_id'];
    protected $casts = ['repetition_number'=>'integer','round_number'=>'integer','started_at'=>'datetime','ended_at'=>'datetime'];

    public function monitoring(): BelongsTo { return $this->belongsTo(SportsLiveMonitoring::class, 'monitoring_id'); }
    public function series(): BelongsTo { return $this->belongsTo(TrainingSeries::class, 'training_series_id'); }
    public function athletes(): HasMany { return $this->hasMany(SportsLiveMeasurementAthlete::class, 'measurement_id'); }
    public function events(): HasMany { return $this->hasMany(SportsLiveMeasurementEvent::class, 'measurement_id')->orderBy('sequence'); }
}
