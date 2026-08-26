<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsPoolLane extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','sports_pool_id','lane_number','name','capacity','active','legacy_sports_venue_lane_id','metadata_json','created_by','updated_by'];
    protected $casts = ['lane_number'=>'integer','capacity'=>'integer','active'=>'boolean','metadata_json'=>'array'];
    public function pool(): BelongsTo { return $this->belongsTo(SportsPool::class, 'sports_pool_id'); }
}
