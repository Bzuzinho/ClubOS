<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingGroupMembership extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','training_group_id','training_group_season_id','user_id','is_primary','starts_at','ends_at','notes','created_by'];
    protected $casts = ['is_primary'=>'boolean','starts_at'=>'date','ends_at'=>'date'];
    public function group(): BelongsTo { return $this->belongsTo(TrainingGroup::class, 'training_group_id'); }
    public function seasonContext(): BelongsTo { return $this->belongsTo(TrainingGroupSeason::class, 'training_group_season_id'); }
    public function athlete(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function scopeActiveOn($query, mixed $date) { return $query->whereDate('starts_at','<=',$date)->where(fn($q) => $q->whereNull('ends_at')->orWhereDate('ends_at','>=',$date)); }
}
