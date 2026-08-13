<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluationModelVersion extends Model
{
    use HasUuids;
    protected $fillable = ['evaluation_model_id','version_number','state','based_on_version_id','created_by','published_at','archived_at'];
    protected $casts = ['version_number'=>'integer','published_at'=>'datetime','archived_at'=>'datetime'];
    public function modelDefinition(): BelongsTo { return $this->belongsTo(SportsEvaluationModel::class, 'evaluation_model_id'); }
    public function sections(): HasMany { return $this->hasMany(SportsEvaluationSection::class, 'evaluation_model_version_id')->orderBy('sort_order'); }
    public function campaigns(): HasMany { return $this->hasMany(SportsEvaluationCampaign::class, 'evaluation_model_version_id'); }
}
