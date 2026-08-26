<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasUuids;


    protected $fillable = [
        'nome',
        'descricao',
        'categoria',
        'cor',
        'icon',
        'visibilidade_default',
        'gera_taxa',
        'permite_convocatoria',
        'gera_presencas',
        'requer_transporte',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'gera_taxa' => 'boolean',
        'permite_convocatoria' => 'boolean',
        'gera_presencas' => 'boolean',
        'requer_transporte' => 'boolean',
    ];
}
