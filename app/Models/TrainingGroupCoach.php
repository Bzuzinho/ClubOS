<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingGroupCoach extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'training_group_id',
        'user_id',
        'role',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
