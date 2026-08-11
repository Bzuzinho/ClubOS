<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingGroupCoach extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','training_group_id','training_group_season_id','user_id','role','sports_coach_role_id','starts_at','ends_at','created_by'];
    protected $casts = ['starts_at'=>'date','ends_at'=>'date'];
    public function group(): BelongsTo { return $this->belongsTo(TrainingGroup::class, 'training_group_id'); }
    public function seasonContext(): BelongsTo { return $this->belongsTo(TrainingGroupSeason::class, 'training_group_season_id'); }
    public function roleDefinition(): BelongsTo { return $this->belongsTo(SportsCoachRole::class, 'sports_coach_role_id'); }
    public function coach(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
