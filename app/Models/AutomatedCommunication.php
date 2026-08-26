<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;

class AutomatedCommunication extends Model
{
    use HasUuids;

    protected $table = 'automated_communications';

    protected $fillable = [
        'nome',
        'tipo_trigger',
        'tipo_comunicacao',
        'assunto',
        'template_mensagem',
        'ativo',
        'condicoes',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'condicoes' => 'array',
    ];
}
