<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SportsLiveMonitoringAthlete extends Model
{
    use HasUuids;

    protected $fillable = ['monitoring_id','training_athlete_id','user_id','active'];
    protected $casts = ['active'=>'boolean'];

    public function monitoring(): BelongsTo { return $this->belongsTo(SportsLiveMonitoring::class, 'monitoring_id'); }
    public function trainingAthlete(): BelongsTo { return $this->belongsTo(TrainingAthlete::class, 'training_athlete_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
