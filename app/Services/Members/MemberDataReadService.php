<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\AthleteSportsData;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Carbon\Carbon;

/**
 * Sprint M2.3 — Camada de leitura canónica com fallback.
 *
 * Lê dados pessoais e de configuração preferencialmente de
 * dados_pessoais / dados_configuracao, com fallback para users.
 * Os dados desportivos passam a preferir athlete_sports_data.
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
        'estado_civil'            => 'estado_civil',
        'numero_irmaos'           => null,
        'tipo_utilizador'         => ['tipo_utilizador', 'perfil'],
        'observacoes'             => 'observacoes',
    ];

    /** @var array<string, string|list<string>|null> */
    private const CONFIGURATION_FALLBACK_MAP = [
        'consentimento_rgpd'               => 'rgpd',
        'consentimento_rgpd_data'          => 'data_rgpd',
        'consentimento_imagem'             => 'consentimento',
        'consentimento_imagem_data'        => 'data_consentimento',
        'declaracao_transporte'            => 'declaracao_de_transporte',
        'declaracao_transporte_data'       => null,
        'declaracao_transporte_ficheiro'   => null,
        'afiliacao_federativa'             => 'afiliacao',
        'afiliacao_numero'                 => 'num_federacao',
        'afiliacao_data'                   => 'data_afiliacao',
        'afiliacao_ficheiro'               => 'arquivo_afiliacao',
        'ficha_inscricao_ficheiro'         => null,
        'documento_identificacao_ficheiro' => null,
        'certificado_medico_ficheiro'      => 'arquivo_atestado_medico',
        'autorizacao_parental_ficheiro'    => null,
        'termos_aceites'                   => null,
        'termos_aceites_data'              => null,
        'receber_comunicacoes'             => null,
        'acesso_portal_ativo'              => null,
        'ultimo_envio_acessos_at'          => null,
        'configuracao_extra'               => null,
    ];

    /** @return array<string, mixed> */
    public function personalPayload(User $user): array
    {
        $dp = $user->dadosPessoais;
        $payload = [];

        foreach (self::PERSONAL_FALLBACK_MAP as $newField => $fallback) {
            $payload[$newField] = $this->resolvePersonal($dp, $user, $newField, $fallback);
        }

        foreach (['data_nascimento', 'validade_documento'] as $dateField) {
            if (isset($payload[$dateField])) {
                $payload[$dateField] = $this->formatDate($payload[$dateField]);
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function configurationPayload(User $user): array
    {
        $dc = $user->dadosConfiguracao;
        $payload = [];

        foreach (self::CONFIGURATION_FALLBACK_MAP as $newField => $fallback) {
            $payload[$newField] = $this->resolveConfiguration($dc, $user, $newField, $fallback);
        }

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
     * Perfil desportivo canónico. Quando ainda não existe athlete_sports_data,
     * dados_configuracao é o primeiro fallback para informação que já tinha
     * sido migrada para a camada canónica de Membros; users é a última rede de
     * compatibilidade de transição.
     *
     * @return array<string, mixed>
     */
    public function sportsPayload(User $user): array
    {
        /** @var AthleteSportsData|null $sports */
        $sports = $user->relationLoaded('athleteSportsData')
            ? $user->getRelation('athleteSportsData')
            : $user->athleteSportsData()->first();

        if ($sports === null) {
            $configuration = $this->configurationPayload($user);
            $configurationExtra = is_array($configuration['configuracao_extra'] ?? null)
                ? $configuration['configuracao_extra']
                : [];
            $legacyAgeGroup = collect($user->escalao ?? [])
                ->map(static fn (mixed $value): string => trim((string) $value))
                ->first(static fn (string $value): bool => $value !== '');

            return [
                'num_federacao' => $configuration['afiliacao_numero']
                    ?? $user->getAttribute('num_federacao'),
                'cartao_federacao' => $configuration['afiliacao_ficheiro']
                    ?? $user->getAttribute('cartao_federacao'),
                'numero_pmb' => $user->getAttribute('numero_pmb'),
                'data_inscricao' => $this->formatDate($user->getAttribute('data_inscricao')),
                'escalao_id' => $legacyAgeGroup ?: null,
                'escalao_calculado_id' => $legacyAgeGroup ?: null,
                'escalao_manual_override' => $legacyAgeGroup !== null,
                'data_atestado_medico' => $this->formatDate($user->getAttribute('data_atestado_medico')),
                'arquivo_atestado_medico' => $configuration['certificado_medico_ficheiro']
                    ?? $user->getAttribute('arquivo_atestado_medico'),
                'informacoes_medicas' => $configurationExtra['informacoes_medicas']
                    ?? $user->getAttribute('informacoes_medicas'),
                'ativo' => (bool) ($user->ativo_desportivo ?? false),
            ];
        }

        return [
            'num_federacao' => $sports->num_federacao,
            'cartao_federacao' => $sports->cartao_federacao,
            'numero_pmb' => $sports->numero_pmb,
            'data_inscricao' => $this->formatDate($sports->data_inscricao),
            'escalao_id' => $sports->escalao_id,
            'escalao_calculado_id' => $sports->escalao_calculado_id,
            'escalao_manual_override' => (bool) $sports->escalao_manual_override,
            'data_atestado_medico' => $this->formatDate($sports->data_atestado_medico),
            'arquivo_atestado_medico' => $sports->arquivo_atestado_medico,
            'informacoes_medicas' => $sports->informacoes_medicas,
            'ativo' => (bool) $sports->ativo,
        ];
    }

    /**
     * @param  array<string, mixed> $baseMemberData
     * @return array<string, mixed>
     */
    public function mergedMemberPayload(User $user, array $baseMemberData): array
    {
        $personal = $this->personalPayload($user);
        $config = $this->configurationPayload($user);
        $sports = $this->sportsPayload($user);

        $baseMemberData['nome_completo'] = $personal['nome_completo'];
        $baseMemberData['data_nascimento'] = $personal['data_nascimento'];
        $baseMemberData['sexo'] = $personal['sexo'];
        $baseMemberData['nif'] = $personal['nif'];
        $baseMemberData['cc'] = $personal['documento_identificacao'];
        $baseMemberData['documento_identificacao'] = $personal['documento_identificacao'];
        $baseMemberData['nacionalidade'] = $personal['nacionalidade'];
        $baseMemberData['morada'] = $personal['morada'];
        $baseMemberData['codigo_postal'] = $personal['codigo_postal'];
        $baseMemberData['localidade'] = $personal['localidade'];
        $baseMemberData['contacto'] = $personal['contacto'];
        $baseMemberData['contacto_alternativo'] = $personal['contacto_alternativo'];
        $baseMemberData['email_secundario'] = $personal['email_secundario'];
        $baseMemberData['estado_civil'] = $personal['estado_civil'];
        $baseMemberData['observacoes'] = $personal['observacoes'];

        if (array_key_exists('numero_irmaos', $personal)) {
            $baseMemberData['numero_irmaos'] = $personal['numero_irmaos'];
        }

        $baseMemberData['rgpd'] = $config['consentimento_rgpd'];
        $baseMemberData['data_rgpd'] = $config['consentimento_rgpd_data'];
        $baseMemberData['arquivo_rgpd'] = $baseMemberData['arquivo_rgpd'] ?? $user->arquivo_rgpd;
        $baseMemberData['consentimento'] = $config['consentimento_imagem'];
        $baseMemberData['data_consentimento'] = $config['consentimento_imagem_data'];
        $baseMemberData['arquivo_consentimento'] = $baseMemberData['arquivo_consentimento'] ?? $user->arquivo_consentimento;
        $baseMemberData['declaracao_de_transporte'] = $config['declaracao_transporte'];
        $baseMemberData['declaracao_transporte'] = $config['declaracao_transporte_ficheiro'];
        $baseMemberData['afiliacao'] = $config['afiliacao_federativa'];
        $baseMemberData['data_afiliacao'] = $config['afiliacao_data'];
        $baseMemberData['arquivo_afiliacao'] = $config['afiliacao_ficheiro'] ?? $user->arquivo_afiliacao;

        // A ficha desportiva é dona destes campos. Configuração/users ficam apenas como fallback.
        $baseMemberData['num_federacao'] = $sports['num_federacao'] ?? $config['afiliacao_numero'];
        $baseMemberData['cartao_federacao'] = $sports['cartao_federacao'];
        $baseMemberData['numero_pmb'] = $sports['numero_pmb'];
        $baseMemberData['data_inscricao'] = $sports['data_inscricao'];
        $baseMemberData['escalao_id'] = $sports['escalao_id'];
        $baseMemberData['escalao'] = $sports['escalao_id'] ? [(string) $sports['escalao_id']] : [];
        $baseMemberData['escalao_calculado_id'] = $sports['escalao_calculado_id'];
        $baseMemberData['escalao_manual_override'] = $sports['escalao_manual_override'];
        $baseMemberData['data_atestado_medico'] = $sports['data_atestado_medico'];
        $baseMemberData['arquivo_atestado_medico'] = $sports['arquivo_atestado_medico'];
        $baseMemberData['informacoes_medicas'] = $sports['informacoes_medicas'];
        $baseMemberData['ativo_desportivo'] = $sports['ativo'];

        $baseMemberData['email_utilizador'] = $user->email_utilizador ?? $user->email;
        $baseMemberData['estado'] = $user->estado;
        $baseMemberData['numero_socio'] = $user->numero_socio;
        $baseMemberData['perfil'] = $user->perfil;

        return $baseMemberData;
    }

    public function valueFromPersonal(
        User $user,
        string $newField,
        string|array|null $fallbackUserField = null,
    ): mixed {
        return $this->resolvePersonal($user->dadosPessoais, $user, $newField, $fallbackUserField);
    }

    public function valueFromConfiguration(
        User $user,
        string $newField,
        string|array|null $fallbackUserField = null,
    ): mixed {
        return $this->resolveConfiguration($user->dadosConfiguracao, $user, $newField, $fallbackUserField);
    }

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

            if (is_bool($value)) {
                return $value;
            }

            if ($this->hasValue($value)) {
                return $value;
            }
        }

        return $this->firstValueFromUser($user, $fallbackUserField);
    }

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
