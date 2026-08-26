<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluationCampaign extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','evaluation_model_version_id','season_id','name','description','starts_at','due_at','state','created_by','published_at','closed_at','cancelled_at'];
    protected $casts = ['starts_at'=>'date','due_at'=>'date','published_at'=>'datetime','closed_at'=>'datetime','cancelled_at'=>'datetime'];

    public function version(): BelongsTo { return $this->belongsTo(SportsEvaluationModelVersion::class, 'evaluation_model_version_id'); }
    public function groups(): BelongsToMany { return $this->belongsToMany(TrainingGroup::class, 'sports_evaluation_campaign_groups', 'campaign_id', 'training_group_id')->withTimestamps(); }
    public function athletes(): HasMany { return $this->hasMany(SportsEvaluationCampaignAthlete::class, 'campaign_id'); }
    public function evaluations(): HasMany { return $this->hasMany(SportsEvaluation::class, 'campaign_id'); }
}
