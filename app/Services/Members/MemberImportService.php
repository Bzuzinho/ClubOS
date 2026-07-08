<?php

namespace App\Services\Members;

use App\Models\AgeGroup;
use App\Models\CostCenter;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MemberCostCenterSyncService;
use App\Services\Financeiro\MemberMonthlyFeeSyncService;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class MemberImportService
{
    private const PREVIEW_LIMIT = 20;

    public function __construct(
        private readonly MemberDataWriteService $memberDataWriteService,
        private readonly MemberCostCenterSyncService $memberCostCenterSyncService,
        private readonly MemberMonthlyFeeSyncService $memberMonthlyFeeSyncService,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string|null>  $mapping
     * @return array<string, mixed>
     */
    public function preview(array $rows, array $mapping): array
    {
        return $this->buildPreview($rows, $mapping);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string|null>  $mapping
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function import(array $rows, array $mapping, array $options = []): array
    {
        $preview = $this->buildPreview($rows, $mapping);
        $preparedRows = $preview['prepared_rows'];
        $createdIds = [];
        $errors = $preview['errors'];
        $warnings = $preview['warnings'];
        $importOnlyValidRows = (bool) ($options['import_only_valid_rows'] ?? true);

        if (!$importOnlyValidRows && $preview['summary']['error_rows'] > 0) {
            return [
                'created_count' => 0,
                'skipped_count' => $preview['summary']['total_rows'],
                'error_count' => $preview['summary']['error_rows'],
                'created_ids' => [],
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        foreach ($preparedRows as $preparedRow) {
            if (!$preparedRow['is_valid']) {
                continue;
            }

            try {
                DB::transaction(function () use ($preparedRow, &$createdIds): void {
                    $member = $this->createMemberFromPreparedRow($preparedRow['normalized']);
                    $createdIds[] = $member->id;
                });
            } catch (Throwable $exception) {
                $errors[] = [
                    'row' => $preparedRow['row'],
                    'field' => 'import',
                    'message' => $this->formatImportException($exception),
                ];
            }
        }

        if ($createdIds !== []) {
            Cache::forget('membros:list');
            Cache::forget('membros:stats');
            Cache::forget('dashboard:stats');
        }

        return [
            'created_count' => count($createdIds),
            'skipped_count' => count($preparedRows) - count($createdIds),
            'error_count' => count($errors),
            'created_ids' => $createdIds,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string|null>  $mapping
     * @return array<string, mixed>
     */
    private function buildPreview(array $rows, array $mapping): array
    {
        $catalogs = $this->buildCatalogs();
        $preparedRows = [];
        $errors = [];
        $warnings = [];
        $batchEmails = [];
        $batchMemberNumbers = [];

        $existingEmails = User::query()
            ->whereNotNull('email_utilizador')
            ->pluck('email_utilizador')
            ->map(fn ($value) => mb_strtolower((string) $value))
            ->flip();

        $existingMemberNumbers = User::query()
            ->whereNotNull('numero_socio')
            ->pluck('numero_socio')
            ->map(fn ($value) => mb_strtolower((string) $value))
            ->flip();

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 2;
            $normalized = [];
            $rowErrors = [];
            $rowWarnings = [];

            foreach ($mapping as $field => $sourceColumn) {
                if (!$sourceColumn) {
                    continue;
                }

                if ($field === 'conta_corrente_manual') {
                    $rowWarnings[] = $this->message(
                        'conta_corrente_manual',
                        'Campo legado ignorado; ajustes de conta corrente devem ser feitos por Movimentos manuais.'
                    );

                    continue;
                }

                $normalized[$field] = $this->normalizeFieldValue(
                    $field,
                    Arr::get($row, $sourceColumn),
                    $catalogs,
                    $rowErrors,
                    $rowWarnings
                );
            }

            $this->finalizeNormalizedRow($normalized, $rowErrors, $rowWarnings);

            $email = isset($normalized['email_utilizador'])
                ? mb_strtolower((string) $normalized['email_utilizador'])
                : null;
            if ($email) {
                if (isset($existingEmails[$email])) {
                    $rowErrors[] = $this->message('email_utilizador', 'Email ja existe no sistema.');
                }
                if (isset($batchEmails[$email])) {
                    $rowErrors[] = $this->message('email_utilizador', 'Email duplicado no ficheiro.');
                }
                $batchEmails[$email] = true;
            }

            $memberNumber = isset($normalized['numero_socio'])
                ? mb_strtolower((string) $normalized['numero_socio'])
                : null;
            if ($memberNumber) {
                if (isset($existingMemberNumbers[$memberNumber])) {
                    $rowErrors[] = $this->message('numero_socio', 'Numero de socio ja existe no sistema.');
                }
                if (isset($batchMemberNumbers[$memberNumber])) {
                    $rowErrors[] = $this->message('numero_socio', 'Numero de socio duplicado no ficheiro.');
                }
                $batchMemberNumbers[$memberNumber] = true;
            } else {
                $rowWarnings[] = $this->message('numero_socio', 'Numero de socio sera gerado automaticamente.');
            }

            $preparedRows[] = [
                'row' => $rowNumber,
                'is_valid' => $rowErrors === [],
                'errors' => $rowErrors,
                'warnings' => $rowWarnings,
                'normalized' => $normalized,
                'display' => $this->buildDisplayRow($normalized),
            ];

            foreach ($rowErrors as $error) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => $error['field'],
                    'message' => $error['message'],
                ];
            }

            foreach ($rowWarnings as $warning) {
                $warnings[] = [
                    'row' => $rowNumber,
                    'field' => $warning['field'],
                    'message' => $warning['message'],
                ];
            }
        }

        $validRows = array_values(array_filter($preparedRows, fn ($row) => $row['is_valid']));
        $warningRows = array_values(array_filter($preparedRows, fn ($row) => $row['warnings'] !== []));

        return [
            'summary' => [
                'total_rows' => count($preparedRows),
                'valid_rows' => count($validRows),
                'warning_rows' => count($warningRows),
                'error_rows' => count($preparedRows) - count($validRows),
            ],
            'rows' => array_slice($preparedRows, 0, self::PREVIEW_LIMIT),
            'errors' => $errors,
            'warnings' => $warnings,
            'prepared_rows' => $preparedRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCatalogs(): array
    {
        return [
            'age_groups' => AgeGroup::query()
                ->select('id', 'nome')
                ->get()
                ->mapWithKeys(fn ($group) => [$this->normalizeLookupKey($group->nome) => $group->id])
                ->all(),
            'monthly_fees' => MonthlyFee::query()
                ->select('id', 'designacao')
                ->get()
                ->mapWithKeys(fn ($fee) => [$this->normalizeLookupKey($fee->designacao) => $fee->id])
                ->all(),
            'cost_centers' => CostCenter::query()
                ->select('id', 'nome')
                ->get()
                ->mapWithKeys(fn ($center) => [$this->normalizeLookupKey($center->nome) => $center->id])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogs
     * @param  array<int, array<string, string>>  $errors
     * @param  array<int, array<string, string>>  $warnings
     */
    private function normalizeFieldValue(
        string $field,
        mixed $value,
        array $catalogs,
        array &$errors,
        array &$warnings
    ): mixed {
        $value = $this->normalizeScalarValue($value);

        if ($value === null) {
            return null;
        }

        return match ($field) {
            'sexo' => $this->normalizeSexo($field, $value, $errors),
            'estado' => $this->normalizeEnum($field, $value, ['ativo', 'inativo', 'suspenso'], $errors),
            'perfil' => $this->normalizePerfil($field, $value, $warnings),
            'tipo_membro' => $this->normalizeTipoMembro($field, $value, $errors),
            'escalao' => $this->normalizeCatalogReference($field, $value, $catalogs['age_groups'], $errors),
            'tipo_mensalidade' => $this->normalizeCatalogReference($field, $value, $catalogs['monthly_fees'], $errors),
            'centro_custo' => $this->normalizeCatalogReference($field, $value, $catalogs['cost_centers'], $errors),
            'ativo_desportivo', 'rgpd', 'consentimento', 'afiliacao', 'declaracao_de_transporte' => $this->normalizeBoolean($field, $value, $errors),
            'data_nascimento', 'data_inscricao', 'data_atestado_medico', 'data_rgpd', 'data_consentimento', 'data_afiliacao' => $this->normalizeDate($field, $value, $errors),
            'numero_irmaos' => $this->normalizeInteger($field, $value, $errors),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<int, array<string, string>>  $errors
     * @param  array<int, array<string, string>>  $warnings
     */
    private function finalizeNormalizedRow(array &$normalized, array &$errors, array &$warnings): void
    {
        if (empty($normalized['nome_completo'])) {
            $errors[] = $this->message('nome_completo', 'Nome completo é obrigatório.');
        }

        foreach (['rgpd', 'consentimento', 'afiliacao', 'declaracao_de_transporte', 'ativo_desportivo'] as $booleanField) {
            if (!array_key_exists($booleanField, $normalized) || $normalized[$booleanField] === null) {
                $normalized[$booleanField] = false;
            }
        }

        if (!array_key_exists('menor', $normalized) || $normalized['menor'] === null) {
            $normalized['menor'] = false;
        }

        if (!isset($normalized['estado']) || $normalized['estado'] === null) {
            $normalized['estado'] = 'ativo';
            $warnings[] = $this->message('estado', 'Estado vazio; foi assumido ativo.');
        }

        if (isset($normalized['data_nascimento']) && $normalized['data_nascimento']) {
            $normalized['menor'] = Carbon::parse($normalized['data_nascimento'])->age < 18;
        }

        if (empty($normalized['perfil'])) {
            $normalized['perfil'] = $this->guessPerfil($normalized['tipo_membro'] ?? []);
            $warnings[] = $this->message('perfil', 'Perfil vazio; foi assumido automaticamente.');
        }

        if (!empty($normalized['escalao'])) {
            $normalized['escalao'] = [(string) $normalized['escalao']];
        }

        if (!empty($normalized['centro_custo'])) {
            $normalized['centro_custo'] = [(string) $normalized['centro_custo']];
        }

        if (!empty($normalized['tipo_membro']) && !is_array($normalized['tipo_membro'])) {
            $normalized['tipo_membro'] = [$normalized['tipo_membro']];
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function buildDisplayRow(array $normalized): array
    {
        return [
            'nome_completo' => $normalized['nome_completo'] ?? null,
            'numero_socio' => $normalized['numero_socio'] ?? null,
            'email_utilizador' => $normalized['email_utilizador'] ?? null,
            'tipo_membro' => isset($normalized['tipo_membro']) && is_array($normalized['tipo_membro'])
                ? implode(' | ', $normalized['tipo_membro'])
                : null,
            'estado' => $normalized['estado'] ?? null,
            'escalao' => isset($normalized['escalao'][0]) ? $normalized['escalao'][0] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function createMemberFromPreparedRow(array $normalized): User
    {
        $payload = $normalized;
        $payload['numero_socio'] = $payload['numero_socio'] ?? $this->generateMemberNumber();
        $payload['password'] = Hash::make('password123');
        $payload = $this->syncAuthIdentityFields($payload);

        $legacyUserPayload = $this->legacyUserPayloadForImportedMember($payload);

        $member = User::create($legacyUserPayload);

        $this->memberDataWriteService->persistFromMemberRequest($member, $payload, (string) $member->id);

        if (array_key_exists('tipo_mensalidade', $normalized)) {
            $this->memberMonthlyFeeSyncService->sync($member, $normalized['tipo_mensalidade']);
        }

        if (!empty($normalized['centro_custo']) && is_array($normalized['centro_custo'])) {
            $this->memberCostCenterSyncService->sync($member, $normalized['centro_custo']);
        }

        return $member->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function legacyUserPayloadForImportedMember(array $payload): array
    {
        return Arr::only($payload, [
            'name',
            'email',
            'email_utilizador',
            'password',
            'numero_socio',
            'estado',
            'perfil',
            'tipo_membro',
            'escalao',
            'ativo_desportivo',
            'data_inscricao',
            'data_atestado_medico',
            'informacoes_medicas',
            'menor',
            'foto_perfil',
            'cartao_federacao',
            'arquivo_rgpd',
            'arquivo_consentimento',
            'arquivo_afiliacao',
            'declaracao_transporte',
        ]);
    }

    private function syncAuthIdentityFields(array $data): array
    {
        if (!empty($data['nome_completo'])) {
            $data['name'] = $data['nome_completo'];
        } elseif (empty($data['name'])) {
            $data['name'] = 'Membro';
        }

        $emailUtilizador = trim((string) ($data['email_utilizador'] ?? ''));
        $data['email_utilizador'] = $emailUtilizador !== '' ? $emailUtilizador : null;
        $data['email'] = $emailUtilizador !== ''
            ? $emailUtilizador
            : ('member+' . Str::uuid() . '@local.test');

        return $data;
    }

    private function generateMemberNumber(): string
    {
        $currentYear = now()->format('Y');
        $yearPrefix = $currentYear . '-';

        $yearNumbers = User::whereNotNull('numero_socio')
            ->where('numero_socio', 'like', $yearPrefix . '%')
            ->pluck('numero_socio');

        $maxYearSuffix = 0;
        foreach ($yearNumbers as $numero) {
            if (preg_match('/^' . $currentYear . '-(\d+)$/', (string) $numero, $matches)) {
                $maxYearSuffix = max($maxYearSuffix, (int) $matches[1]);
            }
        }

        $nextSuffix = $maxYearSuffix + 1;

        return $currentYear . '-' . str_pad((string) $nextSuffix, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeScalarValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeSexo(string $field, mixed $value, array &$errors): ?string
    {
        $normalized = $this->normalizeLookupKey((string) $value);
        return match ($normalized) {
            'm', 'masculino', 'male' => 'masculino',
            'f', 'feminino', 'female' => 'feminino',
            default => $this->pushErrorAndReturnNull($field, 'Sexo invalido.', $errors),
        };
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeEnum(string $field, mixed $value, array $allowed, array &$errors): ?string
    {
        $normalized = $this->normalizeLookupKey((string) $value);
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        return $this->pushErrorAndReturnNull($field, 'Valor invalido.', $errors);
    }

    /** @param array<int, array<string, string>> $warnings */
    private function normalizePerfil(string $field, mixed $value, array &$warnings): ?string
    {
        $normalized = $this->normalizeLookupKey((string) $value);

        return match ($normalized) {
            'admin', 'administrador' => 'admin',
            'encarregado', 'encarregado_educacao' => 'encarregado',
            'atleta' => 'atleta',
            'staff', 'treinador', 'dirigente', 'funcionario' => 'staff',
            default => null,
        };
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeTipoMembro(string $field, mixed $value, array &$errors): array
    {
        $parts = preg_split('/[|,;]/', (string) $value) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $slug = $this->normalizeLookupKey($part);
            $slug = match ($slug) {
                'encarregado', 'encarregado_de_educacao' => 'encarregado_educacao',
                'funcionário', 'funcionario' => 'funcionario',
                default => $slug,
            };

            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            $errors[] = $this->message($field, 'Tipo de membro invalido.');
        }

        return $normalized;
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeCatalogReference(string $field, mixed $value, array $catalog, array &$errors): ?string
    {
        $key = $this->normalizeLookupKey((string) $value);
        if ($key === '') {
            return null;
        }

        if (isset($catalog[$key])) {
            return (string) $catalog[$key];
        }

        return $this->pushErrorAndReturnNull($field, 'Referencia nao encontrada.', $errors);
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeBoolean(string $field, mixed $value, array &$errors): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = $this->normalizeLookupKey((string) $value);
        return match ($normalized) {
            '1', 'true', 'sim', 'yes' => true,
            '0', 'false', 'nao', 'no' => false,
            default => $this->pushErrorAndReturnNull($field, 'Booleano invalido.', $errors),
        };
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeDate(string $field, mixed $value, array &$errors): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd/m/Y'];
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $stringValue);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }

        return $this->pushErrorAndReturnNull($field, 'Data invalida.', $errors);
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeInteger(string $field, mixed $value, array &$errors): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        if (filter_var($stringValue, FILTER_VALIDATE_INT) !== false) {
            return (int) $stringValue;
        }

        return $this->pushErrorAndReturnNull($field, 'Numero inteiro invalido.', $errors);
    }

    /** @param array<int, array<string, string>> $errors */
    private function normalizeNumber(string $field, mixed $value, array &$errors): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $stringValue = str_replace(',', '.', trim((string) $value));
        if ($stringValue === '') {
            return null;
        }

        if (is_numeric($stringValue)) {
            return (float) $stringValue;
        }

        return $this->pushErrorAndReturnNull($field, 'Numero invalido.', $errors);
    }

    /** @param array<int, string> $memberTypes */
    private function guessPerfil(array $memberTypes): string
    {
        if (in_array('atleta', $memberTypes, true)) {
            return 'atleta';
        }

        if (in_array('encarregado_educacao', $memberTypes, true)) {
            return 'encarregado';
        }

        return 'staff';
    }

    private function normalizeLookupKey(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    /** @return array<string, string> */
    private function message(string $field, string $message): array
    {
        return [
            'field' => $field,
            'message' => $message,
        ];
    }

    /** @param array<int, array<string, string>> $errors */
    private function pushErrorAndReturnNull(string $field, string $message, array &$errors): null
    {
        $errors[] = $this->message($field, $message);

        return null;
    }

    private function formatImportException(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'Falha inesperada ao criar o membro.';
        }

        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return Str::limit($message, 180);
    }
}