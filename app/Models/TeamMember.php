<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'user_id',
        'position',
        'jersey_number',
        'join_date',
        'leave_date',
    ];

    protected $casts = [
        'join_date' => 'date',
        'leave_date' => 'date',
        'jersey_number' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
