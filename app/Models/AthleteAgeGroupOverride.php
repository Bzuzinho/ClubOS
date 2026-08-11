<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteAgeGroupOverride extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','user_id','season_id','sports_modality_id','age_group_id','reason','active','effective_at','ended_at','created_by','ended_by'];
    protected $casts = ['active'=>'boolean','effective_at'=>'datetime','ended_at'=>'datetime'];
    public function ageGroup(): BelongsTo { return $this->belongsTo(AgeGroup::class); }
}
