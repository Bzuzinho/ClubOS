<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SportsLiveFreeClassification extends Model
{
    use HasUuids;

    protected $fillable = ['measurement_athlete_id','total_distance_m','segment_count','segment_distance_m','sports_stroke_id','stroke_label','classified_at','classified_by'];
    protected $casts = ['total_distance_m'=>'integer','segment_count'=>'integer','segment_distance_m'=>'decimal:2','classified_at'=>'datetime'];

    public function measurementAthlete(): BelongsTo { return $this->belongsTo(SportsLiveMeasurementAthlete::class, 'measurement_athlete_id'); }
    public function stroke(): BelongsTo { return $this->belongsTo(SportsStroke::class, 'sports_stroke_id'); }
}
