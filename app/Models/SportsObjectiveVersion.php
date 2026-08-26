<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsObjectiveVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'sports_objective_id',
        'version',
        'title',
        'description',
        'objective_type',
        'indicator_key',
        'target_value',
        'target_text',
        'target_unit',
        'visibility',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'target_value' => 'decimal:4',
        'visibility' => 'array',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(SportsObjective::class, 'sports_objective_id');
    }
}
