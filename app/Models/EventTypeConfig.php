<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventTypeConfig extends Model
{
    use HasUuids;

    protected $table = 'event_type_configs';

    protected $fillable = [
        'nome',
        'cor',
        'icon',
        'ativo',
        'gera_taxa',
        'requer_convocatoria',
        'requer_transporte',
        'visibilidade_default',
    ];

    protected $casts = [
        'gera_taxa' => 'boolean',
        'requer_convocatoria' => 'boolean',
        'requer_transporte' => 'boolean',
        'ativo' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'tipo_config_id');
    }
}
