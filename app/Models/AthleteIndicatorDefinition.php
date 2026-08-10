<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AthleteIndicatorDefinition extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'club_id',
        'code',
        'name',
        'description',
        'data_type',
        'unit',
        'category',
        'version',
        'active',
        'shareable_by_default',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'active' => 'boolean',
        'shareable_by_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(AthleteIndicatorRecord::class, 'indicator_definition_id');
    }

    public function scopeForClub($query, string $clubId)
    {
        return $query->where('club_id', $clubId);
    }
}
