<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SportsLiveMeasurementEvent extends Model
{
    use HasUuids;

    protected $fillable = ['measurement_id','measurement_athlete_id','event_type','sequence','elapsed_ms','occurred_at','client_event_id','recorded_by'];
    protected $casts = ['sequence'=>'integer','elapsed_ms'=>'integer','occurred_at'=>'datetime'];

    public function measurement(): BelongsTo { return $this->belongsTo(SportsLiveMeasurement::class, 'measurement_id'); }
    public function measurementAthlete(): BelongsTo { return $this->belongsTo(SportsLiveMeasurementAthlete::class, 'measurement_athlete_id'); }
}
