<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonAgeGroupRule extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','season_id','sports_modality_id','age_group_id','gender','birth_year_min','birth_year_max','age_min','age_max','reference_date','priority','active','created_by','updated_by'];
    protected $casts = ['reference_date'=>'date','priority'=>'integer','active'=>'boolean'];
    public function ageGroup(): BelongsTo { return $this->belongsTo(AgeGroup::class); }
    public function season(): BelongsTo { return $this->belongsTo(Season::class); }
    public function modality(): BelongsTo { return $this->belongsTo(SportsModality::class, 'sports_modality_id'); }
}
