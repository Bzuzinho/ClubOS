<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluationCriterion extends Model
{
    use HasUuids;

    protected $fillable = ['evaluation_section_id','name','description','response_type','min_value','max_value','options_json','weight','required','allow_comment','sort_order','active','archived_at'];
    protected $casts = ['min_value'=>'decimal:3','max_value'=>'decimal:3','options_json'=>'array','weight'=>'decimal:3','required'=>'boolean','allow_comment'=>'boolean','sort_order'=>'integer','active'=>'boolean','archived_at'=>'datetime'];

    public function section(): BelongsTo { return $this->belongsTo(SportsEvaluationSection::class, 'evaluation_section_id'); }
    public function answers(): HasMany { return $this->hasMany(SportsEvaluationAnswer::class, 'criterion_id'); }
}
