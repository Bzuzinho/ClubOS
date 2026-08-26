<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallUp extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'team_id',
        'called_up_athletes',
        'attendances',
        'notes',
    ];

    protected $casts = [
        'called_up_athletes' => 'array',
        'attendances' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
