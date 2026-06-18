<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DadosPessoais extends Model
{
    use HasUuids;

    protected $table = 'dados_pessoais';

    protected $fillable = [
        'user_id',
        'nome_completo',
        'data_nascimento',
        'sexo',
        'nif',
        'documento_identificacao',
        'tipo_documento',
        'validade_documento',
        'nacionalidade',
        'naturalidade',
        'morada',
        'codigo_postal',
        'localidade',
        'distrito',
        'concelho',
        'contacto',
        'contacto_alternativo',
        'email_secundario',
        'tipo_utilizador',
        'observacoes',
        'migrated_from_users_at',
        'migration_source_hash',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'validade_documento' => 'date',
        'migrated_from_users_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
