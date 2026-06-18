<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Carbon\Carbon;

/**
 * Sprint M2.3 — Camada de leitura canónica com fallback.
 *
 * Lê dados pessoais e de configuração preferencialmente de
 * dados_pessoais / dados_configuracao, com fallback para users.
 *
 * Regras:
 *  - null e string vazia caem para fallback.
 *  - Boolean false é valor válido — não cai para fallback.
 *  - String "0" é valor válido.
 *  - Não guarda nem altera nada na base de dados.
 *  - Não cria relações automaticamente.
 */
final class MemberDataReadService
{
    // -------------------------------------------------------------------------
    // Mapeamento: campo em dados_pessoais → campo(s) fallback em users
    // -------------------------------------------------------------------------

    /** @var array<string, string|list<string>|null> */
    private const PERSONAL_FALLBACK_MAP = [
        'nome_completo'           => ['nome_completo', 'name'],
        'data_nascimento'         => 'data_nascimento',
        'sexo'                    => 'sexo',
        'nif'                     => 'nif',
        'documento_identificacao' => ['documento_identificacao', 'cc'],
        'tipo_documento'          => null,
        'validade_documento'      => 'data_validade_cc',
        'nacionalidade'           => 'nacionalidade',
        'naturalidade'            => null,
        'morada'                  => 'morada',
        'codigo_postal'           => 'codigo_postal',
        'localidade'              => 'localidade',
        'distrito'                => null,
        'concelho'                => null,
        'contacto'                => ['contacto', 'telemovel', 'contacto_telefonico'],
        'contacto_alternativo'    => ['contacto_alternativo', 'contacto_telefonico'],
        'email_secundario'        => 'email_secundario',
        'tipo_utilizador'         => ['tipo_utilizador', 'perfil'],
        'observacoes'             => 'observacoes',
    ];

    // -------------------------------------------------------------------------
    // Mapeamento: campo em dados_configuracao → campo(s) fallback em users
    // -------------------------------------------------------------------------

    /** @var array<string, string|list<string>|null> */
    private const CONFIGURATION_FALLBACK_MAP = [
        'consentimento_rgpd'              => 'rgpd',
        'consentimento_rgpd_data'         => 'data_rgpd',
        'consentimento_imagem'            => 'consentimento',
        'consentimento_imagem_data'       => 'data_consentimento',
        'declaracao_transporte'           => 'declaracao_de_transporte',
        'declaracao_transporte_data'      => null,
        'declaracao_transporte_ficheiro'  => null,
        'afiliacao_federativa'            => 'afiliacao',
        'afiliacao_numero'                => 'num_federacao',
        'afiliacao_data'                  => 'data_afiliacao',
        'afiliacao_ficheiro'              => 'arquivo_afiliacao',
        'ficha_inscricao_ficheiro'        => null,
        'documento_identificacao_ficheiro' => null,
        'certificado_medico_ficheiro'     => 'arquivo_atestado_medico',
        'autorizacao_parental_ficheiro'   => null,
        'termos_aceites'                  => null,
        'termos_aceites_data'             => null,
        'receber_comunicacoes'            => null,
        'acesso_portal_ativo'             => null,
        'ultimo_envio_acessos_at'         => null,
        'configuracao_extra'              => null,
    ];

    // =========================================================================
    // Métodos públicos principais
    // =========================================================================

    /**
     * Payload de dados pessoais composto (nova tabela com fallback para users).
     *
     * @return array<string, mixed>
     */
    public function personalPayload(User $user): array
    {
        $dp = $user->dadosPessoais;

        $payload = [];
        foreach (self::PERSONAL_FALLBACK_MAP as $newField => $fallback) {
            $payload[$newField] = $this->resolvePersonal($dp, $user, $newField, $fallback);
        }

        // Normalizar datas para string Y-m-d (compatibilidade com payload atual)
        foreach (['data_nascimento', 'validade_documento'] as $dateField) {
            if (isset($payload[$dateField])) {
                $payload[$dateField] = $this->formatDate($payload[$dateField]);
            }
        }

        return $payload;
    }

    /**
     * Payload de configuração composto (nova tabela com fallback para users).
     *
     * @return array<string, mixed>
     */
    public function configurationPayload(User $user): array
    {
        $dc = $user->dadosConfiguracao;

        $payload = [];
        foreach (self::CONFIGURATION_FALLBACK_MAP as $newField => $fallback) {
            $payload[$newField] = $this->resolveConfiguration($dc, $user, $newField, $fallback);
        }

        // Normalizar datas para formato compatível com payload atual
        foreach (['consentimento_rgpd_data', 'consentimento_imagem_data', 'declaracao_transporte_data', 'afiliacao_data', 'termos_aceites_data', 'ultimo_envio_acessos_at'] as $dateField) {
            if (isset($payload[$dateField])) {
                $payload[$dateField] = $this->formatDatetime($payload[$dateField]);
            }
        }

        if (isset($payload['afiliacao_data'])) {
            $payload['afiliacao_data'] = $this->formatDate($payload['afiliacao_data']);
        }

        return $payload;
    }

    /**
     * Payload completo do membro composto — combina dados pessoais e configuração
     * sobre o array base do membro.
     *
     * Mantém as chaves esperadas pelo frontend atual.
     *
     * @param  array<string, mixed> $baseMemberData  Array obtido de $member->toArray() no controller
     * @return array<string, mixed>
     */
    public function mergedMemberPayload(User $user, array $baseMemberData): array
    {
        $personal = $this->personalPayload($user);
        $config   = $this->configurationPayload($user);

        // --- Campos pessoais com chaves canónicas do frontend ---
        $baseMemberData['nome_completo']           = $personal['nome_completo'];
        $baseMemberData['data_nascimento']         = $personal['data_nascimento'];
        $baseMemberData['sexo']                    = $personal['sexo'];
        $baseMemberData['nif']                     = $personal['nif'];
        $baseMemberData['cc']                      = $personal['documento_identificacao'];
        $baseMemberData['documento_identificacao'] = $personal['documento_identificacao'];
        $baseMemberData['nacionalidade']           = $personal['nacionalidade'];
        $baseMemberData['morada']                  = $personal['morada'];
        $baseMemberData['codigo_postal']           = $personal['codigo_postal'];
        $baseMemberData['localidade']              = $personal['localidade'];
        $baseMemberData['contacto']                = $personal['contacto'];
        $baseMemberData['contacto_alternativo']    = $personal['contacto_alternativo'];
        $baseMemberData['email_secundario']        = $personal['email_secundario'];
        $baseMemberData['observacoes']             = $personal['observacoes'];

        // --- Campos de configuração com chaves canónicas do frontend ---
        // RGPD — chaves legadas preservadas
        $baseMemberData['rgpd']             = $config['consentimento_rgpd'];
        $baseMemberData['data_rgpd']        = $config['consentimento_rgpd_data'];
        $baseMemberData['arquivo_rgpd']     = $baseMemberData['arquivo_rgpd'] ?? $user->arquivo_rgpd;

        // Consentimento imagem
        $baseMemberData['consentimento']      = $config['consentimento_imagem'];
        $baseMemberData['data_consentimento'] = $config['consentimento_imagem_data'];
        $baseMemberData['arquivo_consentimento'] = $baseMemberData['arquivo_consentimento'] ?? $user->arquivo_consentimento;

        // Declaração transporte
        $baseMemberData['declaracao_de_transporte'] = $config['declaracao_transporte'];
        $baseMemberData['declaracao_transporte']    = $config['declaracao_transporte_ficheiro'];

        // Afiliação federativa
        $baseMemberData['afiliacao']          = $config['afiliacao_federativa'];
        $baseMemberData['data_afiliacao']     = $config['afiliacao_data'];
        $baseMemberData['arquivo_afiliacao']  = $config['afiliacao_ficheiro'] ?? $user->arquivo_afiliacao;
        $baseMemberData['num_federacao']      = $config['afiliacao_numero'];

        // Email de autenticação — sempre de users
        $baseMemberData['email_utilizador'] = $user->email_utilizador ?? $user->email;

        // estado e numero_socio — sempre de users (campos operacionais)
        $baseMemberData['estado']        = $user->estado;
        $baseMemberData['numero_socio']  = $user->numero_socio;

        // perfil/role — sempre de users nesta fase
        $baseMemberData['perfil'] = $user->perfil;

        return $baseMemberData;
    }

    // =========================================================================
    // Métodos de leitura com fallback
    // =========================================================================

    /**
     * Lê um campo de dados_pessoais com fallback para users.
     *
     * @param string|list<string>|null $fallbackUserField
     */
    public function valueFromPersonal(
        User $user,
        string $newField,
        string|array|null $fallbackUserField = null,
    ): mixed {
        $dp = $user->dadosPessoais;

        return $this->resolvePersonal($dp, $user, $newField, $fallbackUserField);
    }

    /**
     * Lê um campo de dados_configuracao com fallback para users.
     *
     * @param string|list<string>|null $fallbackUserField
     */
    public function valueFromConfiguration(
        User $user,
        string $newField,
        string|array|null $fallbackUserField = null,
    ): mixed {
        $dc = $user->dadosConfiguracao;

        return $this->resolveConfiguration($dc, $user, $newField, $fallbackUserField);
    }

    // =========================================================================
    // Resolvers internos
    // =========================================================================

    private function resolvePersonal(
        ?DadosPessoais $dp,
        User $user,
        string $newField,
        string|array|null $fallbackUserField,
    ): mixed {
        if ($dp !== null && $this->hasValue($dp->getAttribute($newField))) {
            return $dp->getAttribute($newField);
        }

        return $this->firstValueFromUser($user, $fallbackUserField);
    }

    private function resolveConfiguration(
        ?DadosConfiguracao $dc,
        User $user,
        string $newField,
        string|array|null $fallbackUserField,
    ): mixed {
        if ($dc !== null) {
            $value = $dc->getAttribute($newField);

            // Boolean false é valor válido — não cair para fallback
            if (is_bool($value)) {
                return $value;
            }

            if ($this->hasValue($value)) {
                return $value;
            }
        }

        return $this->firstValueFromUser($user, $fallbackUserField);
    }

    /**
     * @param string|list<string>|null $fields
     */
    private function firstValueFromUser(User $user, string|array|null $fields): mixed
    {
        if ($fields === null) {
            return null;
        }

        foreach ((array) $fields as $field) {
            $value = $user->getAttribute($field);
            if ($this->hasValue($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determina se um valor é considerado "preenchido" (não cai para fallback).
     *
     * - null → fallback
     * - string vazia → fallback
     * - false booleano → valor válido (não fallback)
     * - "0" → valor válido (não fallback)
     * - qualquer outro valor → valor válido (não fallback)
     */
    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Formatação de datas
    // =========================================================================

    private function formatDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDatetime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
