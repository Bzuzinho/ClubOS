<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsAthleteLimitation extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'user_id',
        'sports_modality_id',
        'sports_limitation_type_id',
        'starts_at',
        'ends_at',
        'operational_instruction',
        'allows_training',
        'allows_competition',
        'active',
        'created_by',
        'ended_by',
        'ended_at',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'allows_training' => 'boolean',
        'allows_competition' => 'boolean',
        'active' => 'boolean',
        'ended_at' => 'datetime',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function modality(): BelongsTo
    {
        return $this->belongsTo(SportsModality::class, 'sports_modality_id');
    }

    public function limitationType(): BelongsTo
    {
        return $this->belongsTo(SportsLimitationType::class, 'sports_limitation_type_id');
    }
}
