<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SportsEvaluationAnswer extends Model
{
    use HasUuids;

    protected $fillable = ['evaluation_id','criterion_id','criterion_name_snapshot','section_name_snapshot','response_type_snapshot','weight_snapshot','min_value_snapshot','max_value_snapshot','options_snapshot','value_number','value_text','value_boolean','value_choice','comment'];
    protected $casts = ['weight_snapshot'=>'decimal:3','min_value_snapshot'=>'decimal:3','max_value_snapshot'=>'decimal:3','options_snapshot'=>'array','value_number'=>'decimal:4','value_boolean'=>'boolean'];

    public function evaluation(): BelongsTo { return $this->belongsTo(SportsEvaluation::class, 'evaluation_id'); }
    public function criterion(): BelongsTo { return $this->belongsTo(SportsEvaluationCriterion::class, 'criterion_id'); }
}
