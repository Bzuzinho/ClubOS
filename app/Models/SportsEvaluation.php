<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluation extends Model
{
    use HasUuids;

    protected $fillable = ['campaign_id','campaign_athlete_id','athlete_user_id','evaluator_user_id','state','summary','objectives','overall_score','started_at','completed_at','reopened_at','reopened_by','reopen_reason'];
    protected $casts = ['overall_score'=>'decimal:4','started_at'=>'datetime','completed_at'=>'datetime','reopened_at'=>'datetime'];

    public function campaign(): BelongsTo { return $this->belongsTo(SportsEvaluationCampaign::class, 'campaign_id'); }
    public function campaignAthlete(): BelongsTo { return $this->belongsTo(SportsEvaluationCampaignAthlete::class, 'campaign_athlete_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'athlete_user_id'); }
    public function evaluator(): BelongsTo { return $this->belongsTo(User::class, 'evaluator_user_id'); }
    public function answers(): HasMany { return $this->hasMany(SportsEvaluationAnswer::class, 'evaluation_id'); }
}
