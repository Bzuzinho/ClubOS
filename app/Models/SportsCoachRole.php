<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SportsCoachRole extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','code','name','description','active','archived_at','created_by','updated_by'];
    protected $casts = ['active'=>'boolean','archived_at'=>'datetime'];
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
