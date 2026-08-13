<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SportsCaisMetricDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id', 'codigo', 'nome', 'input_type', 'unit', 'options_json',
        'quick_action', 'ativo', 'ordem', 'archived_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'options_json' => 'array',
        'quick_action' => 'boolean',
        'ativo' => 'boolean',
        'ordem' => 'integer',
        'archived_at' => 'datetime',
    ];
}
