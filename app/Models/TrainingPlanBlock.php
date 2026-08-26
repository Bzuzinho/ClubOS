<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPlanBlock extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','training_plan_version_id','sort_order','name','rounds','notes'];
    protected $casts = ['sort_order' => 'integer', 'rounds' => 'integer'];

    public function version(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id'); }
    public function series(): HasMany { return $this->hasMany(TrainingPlanSeries::class, 'training_plan_block_id')->orderBy('ordem'); }
}
