<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsLiveMonitoring extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','training_id','training_series_id','type','state','current_repetition','current_round','created_by','completed_at','cancelled_at'];
    protected $casts = ['current_repetition'=>'integer','current_round'=>'integer','completed_at'=>'datetime','cancelled_at'=>'datetime'];

    public function training(): BelongsTo { return $this->belongsTo(Training::class, 'training_id'); }
    public function series(): BelongsTo { return $this->belongsTo(TrainingSeries::class, 'training_series_id'); }
    public function athletes(): HasMany { return $this->hasMany(SportsLiveMonitoringAthlete::class, 'monitoring_id'); }
    public function measurements(): HasMany { return $this->hasMany(SportsLiveMeasurement::class, 'monitoring_id')->orderBy('created_at'); }
}
