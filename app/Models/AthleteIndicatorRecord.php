<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AthleteIndicatorRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'indicator_definition_id',
        'user_id',
        'definition_version',
        'indicator_code',
        'indicator_name',
        'indicator_unit',
        'indicator_category',
        'data_type',
        'value_numeric',
        'value_text',
        'value_boolean',
        'value_date',
        'value_milliseconds',
        'value_json',
        'recorded_at',
        'notes',
        'shareable',
        'recorded_by',
    ];

    protected $casts = [
        'definition_version' => 'integer',
        'value_numeric' => 'decimal:6',
        'value_boolean' => 'boolean',
        'value_date' => 'date',
        'value_milliseconds' => 'integer',
        'value_json' => 'array',
        'recorded_at' => 'datetime',
        'shareable' => 'boolean',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AthleteIndicatorDefinition::class, 'indicator_definition_id')->withTrashed();
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
