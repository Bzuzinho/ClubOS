<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DadosConfiguracao extends Model
{
    use HasUuids;

    protected $table = 'dados_configuracao';

    protected $fillable = [
        'user_id',
        'consentimento_rgpd',
        'consentimento_rgpd_data',
        'consentimento_imagem',
        'consentimento_imagem_data',
        'declaracao_transporte',
        'declaracao_transporte_data',
        'declaracao_transporte_ficheiro',
        'afiliacao_federativa',
        'afiliacao_numero',
        'afiliacao_data',
        'afiliacao_ficheiro',
        'ficha_inscricao_ficheiro',
        'documento_identificacao_ficheiro',
        'certificado_medico_ficheiro',
        'autorizacao_parental_ficheiro',
        'termos_aceites',
        'termos_aceites_data',
        'receber_comunicacoes',
        'acesso_portal_ativo',
        'ultimo_envio_acessos_at',
        'configuracao_extra',
        'migrated_from_users_at',
        'migration_source_hash',
    ];

    protected $casts = [
        'consentimento_rgpd' => 'boolean',
        'consentimento_rgpd_data' => 'datetime',
        'consentimento_imagem' => 'boolean',
        'consentimento_imagem_data' => 'datetime',
        'declaracao_transporte' => 'boolean',
        'declaracao_transporte_data' => 'datetime',
        'afiliacao_federativa' => 'boolean',
        'afiliacao_data' => 'date',
        'termos_aceites' => 'boolean',
        'termos_aceites_data' => 'datetime',
        'receber_comunicacoes' => 'boolean',
        'acesso_portal_ativo' => 'boolean',
        'ultimo_envio_acessos_at' => 'datetime',
        'configuracao_extra' => 'array',
        'migrated_from_users_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
