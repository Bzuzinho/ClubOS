<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SportsEvaluationCampaignAthlete extends Model
{
    use HasUuids;
    protected $fillable = ['campaign_id','user_id','state','exclusion_reason','included_at'];
    protected $casts = ['included_at'=>'datetime'];
    public function campaign(): BelongsTo { return $this->belongsTo(SportsEvaluationCampaign::class, 'campaign_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function evaluation(): HasOne { return $this->hasOne(SportsEvaluation::class, 'campaign_athlete_id'); }
}
