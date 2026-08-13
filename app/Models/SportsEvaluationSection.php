<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluationSection extends Model
{
    use HasUuids;

    protected $fillable = ['evaluation_model_version_id','name','description','weight','sort_order','active','archived_at'];
    protected $casts = ['weight'=>'decimal:3','sort_order'=>'integer','active'=>'boolean','archived_at'=>'datetime'];

    public function version(): BelongsTo { return $this->belongsTo(SportsEvaluationModelVersion::class, 'evaluation_model_version_id'); }
    public function criteria(): HasMany { return $this->hasMany(SportsEvaluationCriterion::class, 'evaluation_section_id')->orderBy('sort_order'); }
}
