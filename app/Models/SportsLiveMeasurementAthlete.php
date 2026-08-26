<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SportsLiveMeasurementAthlete extends Model
{
    use HasUuids;

    protected $fillable = ['measurement_id','monitoring_athlete_id','training_athlete_id','user_id','state','duration_ms','stopped_at'];
    protected $casts = ['duration_ms'=>'integer','stopped_at'=>'datetime'];

    public function measurement(): BelongsTo { return $this->belongsTo(SportsLiveMeasurement::class, 'measurement_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function events(): HasMany { return $this->hasMany(SportsLiveMeasurementEvent::class, 'measurement_athlete_id')->orderBy('sequence'); }
    public function classification(): HasOne { return $this->hasOne(SportsLiveFreeClassification::class, 'measurement_athlete_id'); }
}
