<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsPool extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','sports_venue_id','pool_type_config_id','code','name','length_m','indoor','capacity','active','archived_at','metadata_json','created_by','updated_by'];
    protected $casts = ['length_m'=>'decimal:2','indoor'=>'boolean','active'=>'boolean','archived_at'=>'datetime','metadata_json'=>'array'];
    public function venue(): BelongsTo { return $this->belongsTo(SportsVenue::class, 'sports_venue_id'); }
    public function lanes(): HasMany { return $this->hasMany(SportsPoolLane::class, 'sports_pool_id')->orderBy('lane_number'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
