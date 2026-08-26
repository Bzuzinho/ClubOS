<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsLiveMetricDefinition extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','codigo','nome','input_type','unit','options_json','ativo','ordem','archived_at','created_by','updated_by'];
    protected $casts = ['options_json'=>'array','ativo'=>'boolean','ordem'=>'integer','archived_at'=>'datetime'];

    public function records(): HasMany { return $this->hasMany(SportsLiveMetricRecord::class, 'metric_definition_id'); }
}
