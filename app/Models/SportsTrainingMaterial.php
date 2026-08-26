<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SportsTrainingMaterial extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','code','name','description','active','sort_order','archived_at'];
    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer', 'archived_at' => 'datetime'];

    public function planSeries(): BelongsToMany
    {
        return $this->belongsToMany(TrainingPlanSeries::class, 'training_plan_series_materials', 'sports_training_material_id', 'training_plan_series_id')
            ->withPivot(['quantity', 'notes'])->withTimestamps();
    }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
    public function scopeActive($query) { return $query->where('active', true)->whereNull('archived_at'); }
}
