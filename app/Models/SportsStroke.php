<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsStroke extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','code','name','active','sort_order','archived_at'];
    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer', 'archived_at' => 'datetime'];

    public function planSeries(): HasMany { return $this->hasMany(TrainingPlanSeries::class, 'sports_stroke_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
    public function scopeActive($query) { return $query->where('active', true)->whereNull('archived_at'); }
}
