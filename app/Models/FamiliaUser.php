<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class FamiliaUser extends Pivot
{
    use HasUuids;

    protected $table = 'familia_user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'familia_id',
        'user_id',
        'papel_na_familia',
        'pode_editar',
        'pode_ver_financeiro',
        'pode_ver_desportivo',
        'pode_ver_documentos',
        'pode_ver_comunicacoes',
    ];

    protected $casts = [
        'pode_editar' => 'boolean',
        'pode_ver_financeiro' => 'boolean',
        'pode_ver_desportivo' => 'boolean',
        'pode_ver_documentos' => 'boolean',
        'pode_ver_comunicacoes' => 'boolean',
    ];
}