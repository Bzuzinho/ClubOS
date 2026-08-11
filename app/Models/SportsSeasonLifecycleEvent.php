<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsSeasonLifecycleEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'season_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
