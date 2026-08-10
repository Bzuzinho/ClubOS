<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsVenueClosure extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'sports_venue_id',
        'sports_venue_lane_id',
        'starts_at',
        'ends_at',
        'reason',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(SportsVenue::class, 'sports_venue_id');
    }

    public function lane(): BelongsTo
    {
        return $this->belongsTo(SportsVenueLane::class, 'sports_venue_lane_id');
    }
}
