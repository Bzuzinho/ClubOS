<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SportsEvaluationModel extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','name','description','state','created_by','updated_by','archived_at'];
    protected $casts = ['archived_at'=>'datetime'];

    public function versions(): HasMany { return $this->hasMany(SportsEvaluationModelVersion::class, 'evaluation_model_id')->orderBy('version_number'); }
}
