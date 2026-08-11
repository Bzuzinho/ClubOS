<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsVenue extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','code','name','venue_type','address','active','archived_at','metadata','created_by','updated_by'];
    protected $casts = ['active'=>'boolean','archived_at'=>'datetime','metadata'=>'array'];
    public function pools(): HasMany { return $this->hasMany(SportsPool::class, 'sports_venue_id')->orderBy('name'); }
    public function lanes(): HasMany { return $this->hasMany(SportsVenueLane::class, 'sports_venue_id')->orderBy('lane_number')->orderBy('name'); }
    public function closures(): HasMany { return $this->hasMany(SportsVenueClosure::class, 'sports_venue_id')->orderBy('starts_at'); }
    public function trainings(): HasMany { return $this->hasMany(Training::class, 'sports_venue_id'); }
    public function recurrences(): HasMany { return $this->hasMany(TrainingRecurrence::class, 'sports_venue_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id',$clubId); }
}
