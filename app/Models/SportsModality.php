<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsModality extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','code','name','description','active','archived_at','created_by','updated_by'];
    protected $casts = ['active' => 'boolean', 'archived_at' => 'datetime'];

    public function programs(): HasMany { return $this->hasMany(SportsProgram::class, 'sports_modality_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}
