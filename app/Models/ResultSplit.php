<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultSplit extends Model
{
    use HasUuids;

    protected $table = 'result_splits';

    protected $fillable = [
        'resultado_id',
        'distancia_parcial_m',
        'tempo_parcial',
    ];

    protected $casts = [
        'distancia_parcial_m' => 'integer',
        'tempo_parcial' => 'decimal:2',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'resultado_id');
    }
}
