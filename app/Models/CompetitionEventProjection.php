<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionEventProjection extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'competition_id',
        'event_id',
        'legacy_event_id',
        'status',
        'manual_review_reason',
        'projected_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'projected_at' => 'datetime',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
