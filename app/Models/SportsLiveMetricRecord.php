<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SportsLiveMetricRecord extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','training_id','training_series_id','training_athlete_id','user_id','metric_definition_id','metric_code','metric_name','unit_snapshot','value','value_number','note','live_measurement_id','recorded_at','recorded_by','voided_at','voided_by'];
    protected $casts = ['value_number'=>'decimal:4','recorded_at'=>'datetime','voided_at'=>'datetime'];

    public function definition(): BelongsTo { return $this->belongsTo(SportsLiveMetricDefinition::class, 'metric_definition_id'); }
    public function series(): BelongsTo { return $this->belongsTo(TrainingSeries::class, 'training_series_id'); }
    public function trainingAthlete(): BelongsTo { return $this->belongsTo(TrainingAthlete::class, 'training_athlete_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function measurement(): BelongsTo { return $this->belongsTo(SportsLiveMeasurement::class, 'live_measurement_id'); }
}
