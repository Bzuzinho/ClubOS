<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MemberDataWriteService
{
    /** @var list<string> */
    private const PERSONAL_HASH_FIELDS = [
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
    ];

    /** @var list<string> */
    private const CONFIG_HASH_FIELDS = [
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
    ];

    /** @var array<string, list<string>> */
    private const PERSONAL_INPUT_MAP = [
        'nome_completo' => ['nome_completo'],
        'data_nascimento' => ['data_nascimento'],
        'sexo' => ['sexo'],
        'nif' => ['nif'],
        'documento_identificacao' => ['documento_identificacao', 'cc'],
        'tipo_documento' => ['tipo_documento'],
        'validade_documento' => ['validade_documento', 'data_validade_cc'],
        'nacionalidade' => ['nacionalidade'],
        'naturalidade' => ['naturalidade'],
        'morada' => ['morada'],
        'codigo_postal' => ['codigo_postal'],
        'localidade' => ['localidade'],
        'distrito' => ['distrito'],
        'concelho' => ['concelho'],
        'contacto' => ['contacto', 'telefone', 'telemovel'],
        'contacto_alternativo' => ['contacto_alternativo', 'contacto_telefonico'],
        'email_secundario' => ['email_secundario'],
        'tipo_utilizador' => ['tipo_utilizador', 'perfil'],
        'observacoes' => ['observacoes', 'notas'],
    ];

    /** @var array<string, list<string>> */
    private const CONFIG_INPUT_MAP = [
        'consentimento_rgpd' => ['consentimento_rgpd', 'rgpd'],
        'consentimento_rgpd_data' => ['consentimento_rgpd_data', 'data_rgpd'],
        'consentimento_imagem' => ['consentimento_imagem', 'consentimento'],
        'consentimento_imagem_data' => ['consentimento_imagem_data', 'data_consentimento'],
        'declaracao_transporte_data' => ['declaracao_transporte_data'],
        'afiliacao_federativa' => ['afiliacao_federativa', 'afiliacao'],
        'afiliacao_numero' => ['afiliacao_numero', 'num_federacao'],
        'afiliacao_data' => ['afiliacao_data', 'data_afiliacao'],
        'afiliacao_ficheiro' => ['afiliacao_ficheiro', 'arquivo_afiliacao'],
        'ficha_inscricao_ficheiro' => ['ficha_inscricao_ficheiro'],
        'documento_identificacao_ficheiro' => ['documento_identificacao_ficheiro'],
        'certificado_medico_ficheiro' => ['certificado_medico_ficheiro', 'arquivo_atestado_medico'],
        'autorizacao_parental_ficheiro' => ['autorizacao_parental_ficheiro'],
        'termos_aceites' => ['termos_aceites'],
        'termos_aceites_data' => ['termos_aceites_data'],
        'receber_comunicacoes' => ['receber_comunicacoes'],
        'acesso_portal_ativo' => ['acesso_portal_ativo'],
        'ultimo_envio_acessos_at' => ['ultimo_envio_acessos_at'],
        'configuracao_extra' => ['configuracao_extra'],
    ];

    /** @var array<string, list<string>> */
    private const PERSONAL_LEGACY_USER_MAP = [
        'nome_completo' => ['nome_completo', 'name'],
        'data_nascimento' => ['data_nascimento'],
        'sexo' => ['sexo'],
        'nif' => ['nif'],
        'documento_identificacao' => ['cc', 'documento_identificacao'],
        'tipo_documento' => ['tipo_documento'],
        'validade_documento' => ['data_validade_cc', 'validade_documento'],
        'nacionalidade' => ['nacionalidade'],
        'naturalidade' => ['naturalidade'],
        'morada' => ['morada'],
        'codigo_postal' => ['codigo_postal'],
        'localidade' => ['localidade'],
        'distrito' => ['distrito'],
        'concelho' => ['concelho'],
        'contacto' => ['contacto', 'telefone'],
        'contacto_alternativo' => ['contacto_alternativo', 'contacto_telefonico'],
        'email_secundario' => ['email_secundario'],
        'tipo_utilizador' => ['tipo_utilizador'],
        'observacoes' => ['observacoes', 'notas'],
    ];

    /** @var array<string, list<string>> */
    private const CONFIG_LEGACY_USER_MAP = [
        'consentimento_rgpd' => ['rgpd'],
        'consentimento_rgpd_data' => ['data_rgpd'],
        'consentimento_imagem' => ['consentimento'],
        'consentimento_imagem_data' => ['data_consentimento'],
        'declaracao_transporte' => ['declaracao_de_transporte'],
        'declaracao_transporte_ficheiro' => ['declaracao_transporte'],
        'afiliacao_federativa' => ['afiliacao'],
        'afiliacao_numero' => ['num_federacao'],
        'afiliacao_data' => ['data_afiliacao'],
        'afiliacao_ficheiro' => ['arquivo_afiliacao'],
        'ficha_inscricao_ficheiro' => ['ficha_inscricao_ficheiro'],
        'documento_identificacao_ficheiro' => ['documento_identificacao_ficheiro'],
        'certificado_medico_ficheiro' => ['arquivo_atestado_medico', 'certificado_medico_ficheiro'],
        'autorizacao_parental_ficheiro' => ['autorizacao_parental_ficheiro'],
        'termos_aceites' => ['termos_aceites'],
        'termos_aceites_data' => ['termos_aceites_data'],
        'receber_comunicacoes' => ['receber_comunicacoes'],
        'acesso_portal_ativo' => ['acesso_portal_ativo'],
        'ultimo_envio_acessos_at' => ['ultimo_envio_acessos_at'],
        'configuracao_extra' => ['configuracao_extra'],
    ];

    /** @var array<string, bool>|null */
    private static ?array $usersColumns = null;

    public function __construct(
        private readonly MemberDataMigrationService $migrationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistFromMemberRequest(User $user, array $payload, ?string $forcedUserId = null): void
    {
        $userId = $this->resolveUserId($user, $forcedUserId);

        DB::transaction(function () use ($user, $payload, $userId): void {
            $this->persistPersonalData($user, $payload, $userId);
            $this->persistConfigurationData($user, $payload, $userId);
            $this->syncLegacyUserPersonalFields($userId, $payload);
            $this->syncLegacyUserConfigurationFields($userId, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistPersonalData(User $user, array $payload, ?string $forcedUserId = null): DadosPessoais
    {
        $userId = $this->resolveUserId($user, $forcedUserId);
        $record = DadosPessoais::query()->firstOrNew(['user_id' => $userId]);

        $mappedPayload = $this->extractMappedPayload(self::PERSONAL_INPUT_MAP, $payload);

        foreach ($mappedPayload as $field => $value) {
            $record->setAttribute($field, $value);
        }

        if (!$record->exists && !$record->getAttribute('migrated_from_users_at')) {
            $record->setAttribute('migrated_from_users_at', now());
        }

        $signature = $this->buildSignaturePayload($record, self::PERSONAL_HASH_FIELDS);
        $record->setAttribute('migration_source_hash', $this->migrationService->calculatePayloadSignature($signature));

        if (!$record->exists || $record->isDirty()) {
            $record->save();
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persistConfigurationData(User $user, array $payload, ?string $forcedUserId = null): DadosConfiguracao
    {
        $userId = $this->resolveUserId($user, $forcedUserId);
        $record = DadosConfiguracao::query()->firstOrNew(['user_id' => $userId]);

        $mappedPayload = $this->extractMappedPayload(self::CONFIG_INPUT_MAP, $payload);

        if (array_key_exists('declaracao_de_transporte', $payload)) {
            $mappedPayload['declaracao_transporte'] = (bool) $payload['declaracao_de_transporte'];
        } elseif (array_key_exists('declaracao_transporte', $payload) && is_bool($payload['declaracao_transporte'])) {
            $mappedPayload['declaracao_transporte'] = (bool) $payload['declaracao_transporte'];
        }

        if (array_key_exists('declaracao_transporte_ficheiro', $payload)) {
            $mappedPayload['declaracao_transporte_ficheiro'] = $payload['declaracao_transporte_ficheiro'];
        } elseif (array_key_exists('declaracao_transporte', $payload) && !is_bool($payload['declaracao_transporte'])) {
            $mappedPayload['declaracao_transporte_ficheiro'] = $payload['declaracao_transporte'];
        }

        foreach ($mappedPayload as $field => $value) {
            $record->setAttribute($field, $value);
        }

        if (!$record->exists && !$record->getAttribute('migrated_from_users_at')) {
            $record->setAttribute('migrated_from_users_at', now());
        }

        $signature = $this->buildSignaturePayload($record, self::CONFIG_HASH_FIELDS);
        $record->setAttribute('migration_source_hash', $this->migrationService->calculatePayloadSignature($signature));

        if (!$record->exists || $record->isDirty()) {
            $record->save();
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncLegacyUserPersonalFields(string $userId, array $payload): void
    {
        $mappedPayload = $this->extractMappedPayload(self::PERSONAL_INPUT_MAP, $payload);
        $updates = [];

        foreach (self::PERSONAL_LEGACY_USER_MAP as $sourceField => $legacyColumns) {
            if (!array_key_exists($sourceField, $mappedPayload)) {
                continue;
            }

            foreach ($legacyColumns as $column) {
                if ($this->usersTableHasColumn($column)) {
                    $updates[$column] = $mappedPayload[$sourceField];
                }
            }
        }

        if ($updates !== []) {
            User::query()->whereKey($userId)->update($updates);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncLegacyUserConfigurationFields(string $userId, array $payload): void
    {
        $mappedPayload = $this->extractMappedPayload(self::CONFIG_INPUT_MAP, $payload);
        $updates = [];

        if (array_key_exists('declaracao_de_transporte', $payload)) {
            $mappedPayload['declaracao_transporte'] = (bool) $payload['declaracao_de_transporte'];
        }

        if (array_key_exists('declaracao_transporte_ficheiro', $payload)) {
            $mappedPayload['declaracao_transporte_ficheiro'] = $payload['declaracao_transporte_ficheiro'];
        } elseif (array_key_exists('declaracao_transporte', $payload) && !is_bool($payload['declaracao_transporte'])) {
            $mappedPayload['declaracao_transporte_ficheiro'] = $payload['declaracao_transporte'];
        }

        foreach (self::CONFIG_LEGACY_USER_MAP as $sourceField => $legacyColumns) {
            if (!array_key_exists($sourceField, $mappedPayload)) {
                continue;
            }

            foreach ($legacyColumns as $column) {
                if ($this->usersTableHasColumn($column)) {
                    $updates[$column] = $mappedPayload[$sourceField];
                }
            }
        }

        if ($updates !== []) {
            User::query()->whereKey($userId)->update($updates);
        }
    }

    /**
     * @param  array<string, list<string>>  $map
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractMappedPayload(array $map, array $payload): array
    {
        $result = [];

        foreach ($map as $targetField => $sourceCandidates) {
            foreach ($sourceCandidates as $sourceField) {
                if (!array_key_exists($sourceField, $payload)) {
                    continue;
                }

                $result[$targetField] = $payload[$sourceField];
                break;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function buildSignaturePayload(Model $model, array $fields): array
    {
        $payload = [];

        foreach ($fields as $field) {
            $value = $model->getAttribute($field);

            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $payload[$field] = $value;
        }

        return $payload;
    }

    private function usersTableHasColumn(string $column): bool
    {
        if (self::$usersColumns === null) {
            self::$usersColumns = [];
            foreach (Schema::getColumnListing('users') as $name) {
                self::$usersColumns[$name] = true;
            }
        }

        return isset(self::$usersColumns[$column]);
    }

    private function resolveUserId(User $user, ?string $forcedUserId = null): string
    {
        $candidate = trim((string) ($forcedUserId ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim((string) ($user->getKey() ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim((string) ($user->getOriginal('id') ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $candidate = trim((string) ($user->getAttribute('id') ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        throw new \RuntimeException('Nao foi possivel resolver user_id para persistencia de dados do membro.');
    }
}
