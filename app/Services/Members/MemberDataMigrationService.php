<?php

namespace App\Services\Members;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MemberDataMigrationService
{
    /**
     * @return array<string, mixed>
     */
    public function buildAuditReport(array $filters = []): array
    {
        $userId = $this->normalizeUserId($filters['user_id'] ?? null);
        $limit = $this->normalizeLimit($filters['limit'] ?? null);

        $usersColumns = Schema::hasTable('users') ? Schema::getColumnListing('users') : [];
        $usersColumnsMap = array_fill_keys($usersColumns, true);

        $query = User::query()
            ->with(['dadosPessoais', 'dadosConfiguracao'])
            ->orderBy('id');

        if ($userId !== null) {
            $query->whereKey($userId);
        }

        $analyses = [];
        $processed = 0;

        foreach ($query->cursor() as $user) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $analyses[] = $this->analyzeUser($user, $usersColumnsMap);
            $processed++;
        }

        $summary = $this->buildSummary($analyses);

        return [
            'generated_at' => now()->toIso8601String(),
            'filters' => [
                'user_id' => $userId,
                'limit' => $limit,
            ],
            'summary' => $summary,
            'users' => $analyses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBackfillDryRunReport(array $filters = []): array
    {
        $audit = $this->buildAuditReport($filters);
        $users = $audit['users'];

        $wouldCreateDadosPessoais = 0;
        $wouldUpdateDadosPessoais = 0;
        $wouldCreateDadosConfiguracao = 0;
        $wouldUpdateDadosConfiguracao = 0;

        foreach ($users as &$user) {
            $personalAction = $this->plannedAction($user['personal_analysis']);
            $configurationAction = $this->plannedAction($user['configuration_analysis']);

            if ($personalAction === 'create') {
                $wouldCreateDadosPessoais++;
            }

            if ($personalAction === 'update') {
                $wouldUpdateDadosPessoais++;
            }

            if ($configurationAction === 'create') {
                $wouldCreateDadosConfiguracao++;
            }

            if ($configurationAction === 'update') {
                $wouldUpdateDadosConfiguracao++;
            }

            $user['dry_run'] = [
                'dados_pessoais_action' => $personalAction,
                'dados_configuracao_action' => $configurationAction,
            ];
        }
        unset($user);

        $summary = $audit['summary'];
        $summary['would_create_dados_pessoais'] = $wouldCreateDadosPessoais;
        $summary['would_update_dados_pessoais'] = $wouldUpdateDadosPessoais;
        $summary['would_create_dados_configuracao'] = $wouldCreateDadosConfiguracao;
        $summary['would_update_dados_configuracao'] = $wouldUpdateDadosConfiguracao;

        return [
            'mode' => 'dry-run',
            'generated_at' => $audit['generated_at'],
            'filters' => $audit['filters'],
            'summary' => $summary,
            'users' => $users,
        ];
    }

    /**
     * @param  array<string, bool>  $usersColumnsMap
     * @return array<string, mixed>
     */
    public function analyzeUser(User $user, array $usersColumnsMap): array
    {
        $personalBuild = $this->buildPayloadFromMap(
            $user,
            $this->personalFieldMap(),
            $usersColumnsMap,
            'dados_pessoais'
        );

        $configurationBuild = $this->buildPayloadFromMap(
            $user,
            $this->configurationFieldMap(),
            $usersColumnsMap,
            'dados_configuracao'
        );

        $personalComparison = $this->compareWithExisting(
            $user->dadosPessoais,
            $personalBuild['payload'],
            $personalBuild['source_hash']
        );

        $configurationComparison = $this->compareWithExisting(
            $user->dadosConfiguracao,
            $configurationBuild['payload'],
            $configurationBuild['source_hash']
        );

        return [
            'user_id' => (string) $user->id,
            'name' => (string) ($user->nome_completo ?: $user->name ?: ''),
            'personal_analysis' => [
                'has_payload' => $personalBuild['has_payload'],
                'payload' => $personalBuild['payload'],
                'source_hash' => $personalBuild['source_hash'],
                'existing' => $personalComparison,
                'missing_target_record' => $personalBuild['has_payload'] && !$personalComparison['exists'],
                'absent_source_fields' => $personalBuild['absent_source_fields'],
                'empty_source_fields' => $personalBuild['empty_source_fields'],
                'suspicious_values' => $personalBuild['suspicious_values'],
            ],
            'configuration_analysis' => [
                'has_payload' => $configurationBuild['has_payload'],
                'payload' => $configurationBuild['payload'],
                'source_hash' => $configurationBuild['source_hash'],
                'existing' => $configurationComparison,
                'missing_target_record' => $configurationBuild['has_payload'] && !$configurationComparison['exists'],
                'absent_source_fields' => $configurationBuild['absent_source_fields'],
                'empty_source_fields' => $configurationBuild['empty_source_fields'],
                'suspicious_values' => $configurationBuild['suspicious_values'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function calculatePayloadSignature(array $payload): string
    {
        return hash(
            'sha256',
            json_encode($this->stableSort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function personalFieldMap(): array
    {
        return [
            'nome_completo' => ['sources' => ['nome_completo'], 'type' => 'string'],
            'data_nascimento' => ['sources' => ['data_nascimento'], 'type' => 'date'],
            'sexo' => ['sources' => ['sexo'], 'type' => 'sexo'],
            'nif' => ['sources' => ['nif'], 'type' => 'nif'],
            'documento_identificacao' => ['sources' => ['documento_identificacao', 'cc', 'numero_cartao_cidadao'], 'type' => 'string'],
            'tipo_documento' => ['sources' => ['tipo_documento'], 'type' => 'string'],
            'validade_documento' => ['sources' => ['validade_documento', 'data_validade_cc', 'validade_cartao_cidadao'], 'type' => 'date'],
            'nacionalidade' => ['sources' => ['nacionalidade'], 'type' => 'string'],
            'naturalidade' => ['sources' => ['naturalidade'], 'type' => 'string'],
            'morada' => ['sources' => ['morada'], 'type' => 'string'],
            'codigo_postal' => ['sources' => ['codigo_postal'], 'type' => 'postal_code'],
            'localidade' => ['sources' => ['localidade'], 'type' => 'string'],
            'distrito' => ['sources' => ['distrito'], 'type' => 'string'],
            'concelho' => ['sources' => ['concelho'], 'type' => 'string'],
            'contacto' => ['sources' => ['contacto', 'telemovel', 'telefone'], 'type' => 'string'],
            'contacto_alternativo' => ['sources' => ['contacto_alternativo', 'contacto_telefonico'], 'type' => 'string'],
            'email_secundario' => ['sources' => ['email_secundario'], 'type' => 'email'],
            'tipo_utilizador' => ['sources' => ['tipo_utilizador', 'perfil'], 'type' => 'string'],
            'observacoes' => ['sources' => ['observacoes'], 'type' => 'string'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configurationFieldMap(): array
    {
        return [
            'consentimento_rgpd' => ['sources' => ['consentimento_rgpd', 'rgpd'], 'type' => 'bool'],
            'consentimento_rgpd_data' => ['sources' => ['consentimento_rgpd_data', 'data_rgpd'], 'type' => 'datetime'],
            'consentimento_imagem' => ['sources' => ['consentimento_imagem', 'consentimento'], 'type' => 'bool'],
            'consentimento_imagem_data' => ['sources' => ['consentimento_imagem_data', 'data_consentimento'], 'type' => 'datetime'],
            'declaracao_transporte' => ['sources' => ['declaracao_transporte', 'declaracao_de_transporte'], 'type' => 'bool'],
            'declaracao_transporte_data' => ['sources' => ['declaracao_transporte_data'], 'type' => 'datetime'],
            'declaracao_transporte_ficheiro' => ['sources' => ['declaracao_transporte_ficheiro', 'declaracao_transporte'], 'type' => 'string'],
            'afiliacao_federativa' => ['sources' => ['afiliacao_federativa', 'afiliacao'], 'type' => 'bool'],
            'afiliacao_numero' => ['sources' => ['afiliacao_numero', 'num_federacao'], 'type' => 'string'],
            'afiliacao_data' => ['sources' => ['afiliacao_data', 'data_afiliacao'], 'type' => 'date'],
            'afiliacao_ficheiro' => ['sources' => ['afiliacao_ficheiro', 'arquivo_afiliacao'], 'type' => 'string'],
            'ficha_inscricao_ficheiro' => ['sources' => ['ficha_inscricao_ficheiro'], 'type' => 'string'],
            'documento_identificacao_ficheiro' => ['sources' => ['documento_identificacao_ficheiro'], 'type' => 'string'],
            'certificado_medico_ficheiro' => ['sources' => ['certificado_medico_ficheiro', 'arquivo_atestado_medico'], 'type' => 'json_or_string'],
            'autorizacao_parental_ficheiro' => ['sources' => ['autorizacao_parental_ficheiro'], 'type' => 'string'],
            'termos_aceites' => ['sources' => ['termos_aceites', 'consentimento'], 'type' => 'bool'],
            'termos_aceites_data' => ['sources' => ['termos_aceites_data', 'data_consentimento'], 'type' => 'datetime'],
            'receber_comunicacoes' => ['sources' => ['receber_comunicacoes', 'consentimento'], 'type' => 'bool'],
            'acesso_portal_ativo' => ['sources' => ['acesso_portal_ativo', 'estado'], 'type' => 'portal_access'],
            'ultimo_envio_acessos_at' => ['sources' => ['ultimo_envio_acessos_at'], 'type' => 'datetime'],
            'configuracao_extra' => ['sources' => ['configuracao_extra'], 'type' => 'json_or_string'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldMap
     * @param  array<string, bool>  $usersColumnsMap
     * @return array<string, mixed>
     */
    private function buildPayloadFromMap(User $user, array $fieldMap, array $usersColumnsMap, string $scope): array
    {
        $payload = [];
        $absentSourceFields = [];
        $emptySourceFields = [];
        $suspiciousValues = [];

        foreach ($fieldMap as $targetField => $definition) {
            $sources = array_values(array_filter((array) ($definition['sources'] ?? [])));
            $type = (string) ($definition['type'] ?? 'string');

            $availableSources = array_values(array_filter(
                $sources,
                fn (string $source): bool => isset($usersColumnsMap[$source])
            ));

            if ($availableSources === []) {
                $absentSourceFields[] = [
                    'scope' => $scope,
                    'target_field' => $targetField,
                    'source_candidates' => $sources,
                ];

                continue;
            }

            $selectedSource = null;
            $selectedRawValue = null;

            foreach ($availableSources as $source) {
                $rawValue = $user->getRawOriginal($source);

                if (!$this->isEmptyValue($rawValue)) {
                    $selectedSource = $source;
                    $selectedRawValue = $rawValue;
                    break;
                }

                if ($selectedSource === null) {
                    $selectedSource = $source;
                    $selectedRawValue = $rawValue;
                }
            }

            if ($selectedSource === null) {
                continue;
            }

            if ($this->isEmptyValue($selectedRawValue)) {
                $emptySourceFields[] = [
                    'scope' => $scope,
                    'target_field' => $targetField,
                    'source_field' => $selectedSource,
                ];

                continue;
            }

            $normalized = $this->normalizeValue(
                $selectedRawValue,
                $type,
                $targetField,
                $selectedSource,
                $scope,
                (string) $user->id,
                $suspiciousValues
            );

            if ($normalized === null) {
                continue;
            }

            $payload[$targetField] = $normalized;
        }

        ksort($payload);

        return [
            'payload' => $payload,
            'has_payload' => $payload !== [],
            'source_hash' => $this->calculatePayloadSignature($payload),
            'absent_source_fields' => $absentSourceFields,
            'empty_source_fields' => $emptySourceFields,
            'suspicious_values' => $suspiciousValues,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $suspiciousValues
     */
    private function normalizeValue(
        mixed $value,
        string $type,
        string $targetField,
        string $sourceField,
        string $scope,
        string $userId,
        array &$suspiciousValues
    ): mixed {
        if ($type === 'json_or_string') {
            if (is_array($value)) {
                return $this->stableSort($value);
            }

            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed === '') {
                    return null;
                }

                $decoded = json_decode($trimmed, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $this->stableSort($decoded);
                }

                return $trimmed;
            }

            return null;
        }

        if ($type === 'bool') {
            $normalizedBool = $this->normalizeBoolean($value);

            if ($normalizedBool === null) {
                $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'invalid_boolean');
            }

            return $normalizedBool;
        }

        if ($type === 'portal_access') {
            $normalizedPortal = $this->normalizePortalAccess($value);

            if ($normalizedPortal === null) {
                $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'invalid_portal_access');
            }

            return $normalizedPortal;
        }

        if ($type === 'date' || $type === 'datetime') {
            $normalizedDate = $this->normalizeDateLike($value, $type === 'datetime');

            if ($normalizedDate === null) {
                $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'invalid_date');
            }

            return $normalizedDate;
        }

        $normalizedString = $this->normalizeString($value);

        if ($normalizedString === null) {
            return null;
        }

        if ($type === 'email' && !filter_var($normalizedString, FILTER_VALIDATE_EMAIL)) {
            $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'invalid_email');
        }

        if ($type === 'nif') {
            $digits = preg_replace('/\D+/', '', $normalizedString) ?? '';

            if (strlen($digits) !== 9) {
                $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'suspicious_nif');
            }
        }

        if ($type === 'postal_code' && !preg_match('/^\d{4}-\d{3}$/', $normalizedString)) {
            $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'suspicious_postal_code');
        }

        if ($type === 'sexo' && !in_array(mb_strtolower($normalizedString), ['masculino', 'feminino', 'male', 'female', 'm', 'f'], true)) {
            $this->markSuspicious($suspiciousValues, $scope, $targetField, $sourceField, $userId, $value, 'unexpected_sex_value');
        }

        return $normalizedString;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            if ((int) $value === 1) {
                return true;
            }

            if ((int) $value === 0) {
                return false;
            }

            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'sim', 'yes', 'y', 'ativo' => true,
            '0', 'false', 'nao', 'não', 'no', 'n', 'inativo' => false,
            default => null,
        };
    }

    private function normalizePortalAccess(mixed $value): ?bool
    {
        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            if (in_array($normalized, ['ativo', 'active', 'enabled'], true)) {
                return true;
            }

            if (in_array($normalized, ['inativo', 'inactive', 'disabled', 'suspenso'], true)) {
                return false;
            }
        }

        return $this->normalizeBoolean($value);
    }

    private function normalizeDateLike(mixed $value, bool $withTime): ?string
    {
        if ($value instanceof Carbon) {
            return $withTime ? $value->format('Y-m-d H:i:s') : $value->format('Y-m-d');
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            $date = Carbon::parse($raw);

            return $withTime ? $date->format('Y-m-d H:i:s') : $date->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $suspiciousValues
     */
    private function markSuspicious(
        array &$suspiciousValues,
        string $scope,
        string $targetField,
        string $sourceField,
        string $userId,
        mixed $value,
        string $reason
    ): void {
        $suspiciousValues[] = [
            'scope' => $scope,
            'target_field' => $targetField,
            'source_field' => $sourceField,
            'user_id' => $userId,
            'value' => is_scalar($value) || $value === null ? $value : json_encode($value),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compareWithExisting(?Model $existingRecord, array $payload, string $payloadHash): array
    {
        if ($existingRecord === null) {
            return [
                'exists' => false,
                'is_conflict' => false,
                'hash_diverged' => false,
                'is_incomplete' => false,
                'differences' => [],
            ];
        }

        $array = $existingRecord->toArray();
        $differences = [];

        foreach ($payload as $field => $value) {
            $existingValue = $this->normalizeComparableValue($array[$field] ?? null);
            $expectedValue = $this->normalizeComparableValue($value);

            if ($existingValue !== $expectedValue) {
                $differences[] = [
                    'field' => $field,
                    'expected' => $value,
                    'existing' => $array[$field] ?? null,
                ];
            }
        }

        $isIncomplete = false;

        foreach (array_keys($payload) as $field) {
            if ($this->isEmptyValue($array[$field] ?? null)) {
                $isIncomplete = true;
                break;
            }
        }

        $storedHash = $array['migration_source_hash'] ?? null;
        $hashDiverged = is_string($storedHash) && $storedHash !== '' && $storedHash !== $payloadHash;

        return [
            'exists' => true,
            'is_conflict' => $differences !== [] || $hashDiverged || $isIncomplete,
            'hash_diverged' => $hashDiverged,
            'is_incomplete' => $isIncomplete,
            'differences' => $differences,
        ];
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return $this->stableSort($value);
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $analyses
     * @return array<string, mixed>
     */
    private function buildSummary(array $analyses): array
    {
        $usersWithPersonalPayload = 0;
        $usersWithConfigurationPayload = 0;
        $usersWithDadosPessoais = 0;
        $usersWithDadosConfiguracao = 0;
        $missingDadosPessoais = 0;
        $missingDadosConfiguracao = 0;
        $conflictsDadosPessoais = 0;
        $conflictsDadosConfiguracao = 0;

        $absentSourceFields = [];
        $emptyFields = [];
        $suspiciousValues = [];
        $possibleDuplicates = [
            'nif' => [],
            'documento_identificacao' => [],
            'email_secundario' => [],
            'afiliacao_numero' => [],
            'signature_dados_pessoais' => [],
            'signature_dados_configuracao' => [],
        ];

        foreach ($analyses as $analysis) {
            $personal = $analysis['personal_analysis'];
            $configuration = $analysis['configuration_analysis'];

            if ($personal['has_payload']) {
                $usersWithPersonalPayload++;
                $this->collectDuplicate($possibleDuplicates['signature_dados_pessoais'], $personal['source_hash'], $analysis['user_id']);
                $this->collectDuplicate($possibleDuplicates['nif'], $personal['payload']['nif'] ?? null, $analysis['user_id']);
                $this->collectDuplicate($possibleDuplicates['documento_identificacao'], $personal['payload']['documento_identificacao'] ?? null, $analysis['user_id']);
                $this->collectDuplicate($possibleDuplicates['email_secundario'], $personal['payload']['email_secundario'] ?? null, $analysis['user_id']);
            }

            if ($configuration['has_payload']) {
                $usersWithConfigurationPayload++;
                $this->collectDuplicate($possibleDuplicates['signature_dados_configuracao'], $configuration['source_hash'], $analysis['user_id']);
                $this->collectDuplicate($possibleDuplicates['afiliacao_numero'], $configuration['payload']['afiliacao_numero'] ?? null, $analysis['user_id']);
            }

            if ($personal['existing']['exists']) {
                $usersWithDadosPessoais++;
            }

            if ($configuration['existing']['exists']) {
                $usersWithDadosConfiguracao++;
            }

            if ($personal['missing_target_record']) {
                $missingDadosPessoais++;
            }

            if ($configuration['missing_target_record']) {
                $missingDadosConfiguracao++;
            }

            if ($personal['existing']['is_conflict']) {
                $conflictsDadosPessoais++;
            }

            if ($configuration['existing']['is_conflict']) {
                $conflictsDadosConfiguracao++;
            }

            foreach ($personal['absent_source_fields'] as $absent) {
                $absentSourceFields[$absent['scope'] . '.' . $absent['target_field']] = $absent;
            }

            foreach ($configuration['absent_source_fields'] as $absent) {
                $absentSourceFields[$absent['scope'] . '.' . $absent['target_field']] = $absent;
            }

            foreach ($personal['empty_source_fields'] as $empty) {
                $key = $empty['scope'] . '.' . $empty['target_field'];
                $emptyFields[$key] = ($emptyFields[$key] ?? 0) + 1;
            }

            foreach ($configuration['empty_source_fields'] as $empty) {
                $key = $empty['scope'] . '.' . $empty['target_field'];
                $emptyFields[$key] = ($emptyFields[$key] ?? 0) + 1;
            }

            foreach ($personal['suspicious_values'] as $suspicious) {
                $suspiciousValues[] = $suspicious;
            }

            foreach ($configuration['suspicious_values'] as $suspicious) {
                $suspiciousValues[] = $suspicious;
            }
        }

        return [
            'total_users' => count($analyses),
            'users_with_personal_payload' => $usersWithPersonalPayload,
            'users_with_configuration_payload' => $usersWithConfigurationPayload,
            'users_with_dados_pessoais' => $usersWithDadosPessoais,
            'users_with_dados_configuracao' => $usersWithDadosConfiguracao,
            'missing_dados_pessoais' => $missingDadosPessoais,
            'missing_dados_configuracao' => $missingDadosConfiguracao,
            'conflicts_dados_pessoais' => $conflictsDadosPessoais,
            'conflicts_dados_configuracao' => $conflictsDadosConfiguracao,
            'absent_source_fields' => count($absentSourceFields),
            'suspicious_values' => count($suspiciousValues),
            'absent_source_fields_details' => array_values($absentSourceFields),
            'empty_fields' => $emptyFields,
            'suspicious_values_details' => $suspiciousValues,
            'possible_duplications' => [
                'nif' => $this->formatDuplicates($possibleDuplicates['nif']),
                'documento_identificacao' => $this->formatDuplicates($possibleDuplicates['documento_identificacao']),
                'email_secundario' => $this->formatDuplicates($possibleDuplicates['email_secundario']),
                'afiliacao_numero' => $this->formatDuplicates($possibleDuplicates['afiliacao_numero']),
                'signature_dados_pessoais' => $this->formatDuplicates($possibleDuplicates['signature_dados_pessoais']),
                'signature_dados_configuracao' => $this->formatDuplicates($possibleDuplicates['signature_dados_configuracao']),
            ],
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $bucket
     */
    private function collectDuplicate(array &$bucket, mixed $value, string $userId): void
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return;
        }

        $key = mb_strtolower(trim((string) $value));

        if (!isset($bucket[$key])) {
            $bucket[$key] = [];
        }

        $bucket[$key][] = $userId;
    }

    /**
     * @param  array<string, array<int, string>>  $bucket
     * @return array<int, array<string, mixed>>
     */
    private function formatDuplicates(array $bucket): array
    {
        $duplicates = [];

        foreach ($bucket as $value => $users) {
            $uniqueUsers = array_values(array_unique($users));

            if (count($uniqueUsers) < 2) {
                continue;
            }

            $duplicates[] = [
                'value' => $value,
                'user_ids' => $uniqueUsers,
                'count' => count($uniqueUsers),
            ];
        }

        usort($duplicates, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $duplicates;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function stableSort(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->stableSort($value);
            }
        }

        ksort($array);

        return $array;
    }

    private function normalizeUserId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function plannedAction(array $analysis): string
    {
        if (!($analysis['has_payload'] ?? false)) {
            return 'skip';
        }

        if (!(($analysis['existing'] ?? [])['exists'] ?? false)) {
            return 'create';
        }

        if ((($analysis['existing'] ?? [])['is_conflict'] ?? false)) {
            return 'update';
        }

        return 'noop';
    }
}
