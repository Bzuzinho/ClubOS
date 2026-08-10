<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'code',
        'name',
        'description',
        'modality',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(TrainingGroupMembership::class, 'training_group_id');
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(TrainingGroupCoach::class, 'training_group_id');
    }

    public function sessionAssignments(): HasMany
    {
        return $this->hasMany(TrainingSessionGroup::class, 'training_group_id');
    }

    public function recurrenceAssignments(): HasMany
    {
        return $this->hasMany(TrainingRecurrenceGroup::class, 'training_group_id');
    }

    public function ageGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            AgeGroup::class,
            'training_group_age_groups',
            'training_group_id',
            'age_group_id'
        )->withPivot(['club_id'])->withTimestamps();
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
