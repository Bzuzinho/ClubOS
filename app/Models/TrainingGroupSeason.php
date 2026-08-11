<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingGroupSeason extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','training_group_id','season_id','sports_program_id','active','settings_json','notes','created_by','updated_by'];
    protected $casts = ['active'=>'boolean','settings_json'=>'array'];
    public function group(): BelongsTo { return $this->belongsTo(TrainingGroup::class, 'training_group_id'); }
    public function season(): BelongsTo { return $this->belongsTo(Season::class); }
    public function program(): BelongsTo { return $this->belongsTo(SportsProgram::class, 'sports_program_id'); }
}
