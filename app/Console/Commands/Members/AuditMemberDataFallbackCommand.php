<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

final class AuditMemberDataFallbackCommand extends Command
{
    protected $signature = 'members:audit-data-fallback
        {--json : Devolve o relatorio em JSON}
        {--fail-on-fallback : Falha com codigo 1 se existir fallback legacy em uso}
        {--user-id= : Audita apenas um utilizador}
        {--limit= : Limita o numero de users analisados}
        {--only= : Filtra area: personal|configuration}
        {--with-empty-canonical : Inclui users com campos vazios (canonical+fallback) na lista}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita dependencias de fallback legacy em users sem escrever dados';

    /** @var array<string, string|list<string>|null> */
    private const PERSONAL_FALLBACK_MAP = [
        'nome_completo' => ['nome_completo', 'name'],
        'data_nascimento' => 'data_nascimento',
        'sexo' => 'sexo',
        'nif' => 'nif',
        'documento_identificacao' => ['documento_identificacao', 'cc'],
        'validade_documento' => 'data_validade_cc',
        'nacionalidade' => 'nacionalidade',
        'morada' => 'morada',
        'codigo_postal' => 'codigo_postal',
        'localidade' => 'localidade',
        'contacto' => ['contacto', 'telemovel', 'contacto_telefonico'],
        'contacto_alternativo' => ['contacto_alternativo', 'contacto_telefonico'],
        'email_secundario' => 'email_secundario',
        'tipo_utilizador' => ['tipo_utilizador', 'perfil'],
        'observacoes' => 'observacoes',
    ];

    /** @var array<string, string|list<string>|null> */
    private const CONFIGURATION_FALLBACK_MAP = [
        'consentimento_rgpd' => 'rgpd',
        'consentimento_rgpd_data' => 'data_rgpd',
        'consentimento_imagem' => 'consentimento',
        'consentimento_imagem_data' => 'data_consentimento',
        'declaracao_transporte' => 'declaracao_de_transporte',
        'afiliacao_federativa' => 'afiliacao',
        'afiliacao_numero' => 'num_federacao',
        'afiliacao_data' => 'data_afiliacao',
        'afiliacao_ficheiro' => 'arquivo_afiliacao',
        'certificado_medico_ficheiro' => 'arquivo_atestado_medico',
    ];

    public function handle(): int
    {
        $area = is_string($this->option('only')) ? trim((string) $this->option('only')) : '';
        if ($area !== '' && !in_array($area, ['personal', 'configuration'], true)) {
            $this->error('Opcao --only invalida. Use: personal|configuration');

            return 2;
        }

        $includePersonal = $area === '' || $area === 'personal';
        $includeConfiguration = $area === '' || $area === 'configuration';

        $users = $this->buildQuery()
            ->when($this->option('user-id'), fn (Builder $query, mixed $id): Builder => $query->where('id', $id))
            ->when($this->option('limit'), function (Builder $query, mixed $limit): Builder {
                $parsed = (int) $limit;
                if ($parsed > 0) {
                    $query->limit($parsed);
                }

                return $query;
            })
            ->get();

        $report = $this->buildReport($users, $includePersonal, $includeConfiguration);
        [$guardRailPassed, $guardRailFailureReason] = $this->evaluateGuardRail($report);

        $report['passed'] = $guardRailPassed;
        $report['failure_reason'] = $guardRailFailureReason;

        $this->writeReportFileIfRequested($report);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($report));

            return $this->exitCodeForGuardRail($guardRailPassed);
        }

        $this->renderHumanReadableReport($report, $includePersonal, $includeConfiguration, $guardRailPassed);

        return $this->exitCodeForGuardRail($guardRailPassed);
    }

    private function buildQuery(): Builder
    {
        return User::query()
            ->with(['dadosPessoais', 'dadosConfiguracao'])
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param \Illuminate\Support\Collection<int, User> $users
     * @return array<string, mixed>
     */
    private function buildReport($users, bool $includePersonal, bool $includeConfiguration): array
    {
        $personalFieldMetrics = $this->initializeFieldMetrics(self::PERSONAL_FALLBACK_MAP);
        $configurationFieldMetrics = $this->initializeFieldMetrics(self::CONFIGURATION_FALLBACK_MAP);

        $summary = [
            'total_users_analyzed' => $users->count(),
            'personal_canonical_values' => 0,
            'personal_fallback_values' => 0,
            'personal_empty_values' => 0,
            'configuration_canonical_values' => 0,
            'configuration_fallback_values' => 0,
            'configuration_empty_values' => 0,
            'users_with_any_personal_fallback' => 0,
            'users_with_any_configuration_fallback' => 0,
            'users_without_dados_pessoais' => 0,
            'users_without_dados_configuracao' => 0,
        ];

        $userRows = [];

        foreach ($users as $user) {
            if ($user->dadosPessoais === null) {
                $summary['users_without_dados_pessoais']++;
            }

            if ($user->dadosConfiguracao === null) {
                $summary['users_without_dados_configuracao']++;
            }

            $personalFallbackFields = [];
            $personalEmptyFields = [];
            $configurationFallbackFields = [];
            $configurationEmptyFields = [];

            if ($includePersonal) {
                $this->auditArea(
                    user: $user,
                    canonicalModel: $user->dadosPessoais,
                    fieldMap: self::PERSONAL_FALLBACK_MAP,
                    fieldMetrics: $personalFieldMetrics,
                    canonicalCounter: $summary['personal_canonical_values'],
                    fallbackCounter: $summary['personal_fallback_values'],
                    emptyCounter: $summary['personal_empty_values'],
                    fallbackFieldsOut: $personalFallbackFields,
                    emptyFieldsOut: $personalEmptyFields,
                );
            }

            if ($includeConfiguration) {
                $this->auditArea(
                    user: $user,
                    canonicalModel: $user->dadosConfiguracao,
                    fieldMap: self::CONFIGURATION_FALLBACK_MAP,
                    fieldMetrics: $configurationFieldMetrics,
                    canonicalCounter: $summary['configuration_canonical_values'],
                    fallbackCounter: $summary['configuration_fallback_values'],
                    emptyCounter: $summary['configuration_empty_values'],
                    fallbackFieldsOut: $configurationFallbackFields,
                    emptyFieldsOut: $configurationEmptyFields,
                );
            }

            if ($includePersonal && $personalFallbackFields !== []) {
                $summary['users_with_any_personal_fallback']++;
            }

            if ($includeConfiguration && $configurationFallbackFields !== []) {
                $summary['users_with_any_configuration_fallback']++;
            }

            $includeUser = ($personalFallbackFields !== [] || $configurationFallbackFields !== []);

            if ((bool) $this->option('with-empty-canonical')) {
                $includeUser = $includeUser || $personalEmptyFields !== [] || $configurationEmptyFields !== [];
            }

            if ($includeUser) {
                $userRows[] = [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'numero_socio' => $user->numero_socio,
                    'personal_fallback_fields' => array_values($personalFallbackFields),
                    'configuration_fallback_fields' => array_values($configurationFallbackFields),
                    'personal_empty_fields' => array_values($personalEmptyFields),
                    'configuration_empty_fields' => array_values($configurationEmptyFields),
                ];
            }
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'options' => [
                'json' => (bool) $this->option('json'),
                'user_id' => $this->option('user-id'),
                'limit' => $this->option('limit'),
                'only' => $this->option('only'),
                'with_empty_canonical' => (bool) $this->option('with-empty-canonical'),
                'report_path' => $this->option('report-path'),
            ],
            'summary' => $summary,
            'fields' => [
                'personal' => $personalFieldMetrics,
                'configuration' => $configurationFieldMetrics,
            ],
            'users' => $userRows,
        ];
    }

    /**
     * @param array<string, string|list<string>|null> $fieldMap
     * @param array<string, array<string, int>> $fieldMetrics
     * @param array<int, string> $fallbackFieldsOut
     * @param array<int, string> $emptyFieldsOut
     */
    private function auditArea(
        User $user,
        mixed $canonicalModel,
        array $fieldMap,
        array &$fieldMetrics,
        int &$canonicalCounter,
        int &$fallbackCounter,
        int &$emptyCounter,
        array &$fallbackFieldsOut,
        array &$emptyFieldsOut,
    ): void {
        foreach ($fieldMap as $field => $fallbackSource) {
            $canonicalValue = $canonicalModel?->getAttribute($field);

            if ($this->hasValue($canonicalValue)) {
                $canonicalCounter++;
                $fieldMetrics[$field]['canonical_count']++;

                continue;
            }

            $fallbackValue = $this->firstFallbackValueFromUser($user, $fallbackSource);
            if ($this->hasValue($fallbackValue)) {
                $fallbackCounter++;
                $fieldMetrics[$field]['fallback_count']++;
                $fallbackFieldsOut[] = $field;

                continue;
            }

            $emptyCounter++;
            $fieldMetrics[$field]['empty_count']++;
            $emptyFieldsOut[] = $field;
        }
    }

    /**
     * @param array<string, string|list<string>|null> $fieldMap
     * @return array<string, array<string, int>>
     */
    private function initializeFieldMetrics(array $fieldMap): array
    {
        $metrics = [];

        foreach ($fieldMap as $field => $_) {
            $metrics[$field] = [
                'canonical_count' => 0,
                'fallback_count' => 0,
                'empty_count' => 0,
            ];
        }

        return $metrics;
    }

    private function renderHumanReadableReport(array $report, bool $includePersonal, bool $includeConfiguration, bool $guardRailPassed): void
    {
        $summary = $report['summary'];

        $this->info('Auditoria de fallback legacy users (read-only)');
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_users_analyzed', $summary['total_users_analyzed']],
                ['personal_canonical_values', $summary['personal_canonical_values']],
                ['personal_fallback_values', $summary['personal_fallback_values']],
                ['personal_empty_values', $summary['personal_empty_values']],
                ['configuration_canonical_values', $summary['configuration_canonical_values']],
                ['configuration_fallback_values', $summary['configuration_fallback_values']],
                ['configuration_empty_values', $summary['configuration_empty_values']],
                ['users_with_any_personal_fallback', $summary['users_with_any_personal_fallback']],
                ['users_with_any_configuration_fallback', $summary['users_with_any_configuration_fallback']],
                ['users_without_dados_pessoais', $summary['users_without_dados_pessoais']],
                ['users_without_dados_configuracao', $summary['users_without_dados_configuracao']],
            ]
        );

        $this->newLine();
        $this->line('Top fields with fallback:');

        $topRows = $this->buildTopFallbackRows($report['fields'], $includePersonal, $includeConfiguration);

        if ($topRows === []) {
            $this->line('Sem campos com fallback ou vazio para os filtros selecionados.');
        } else {
            $this->table(['field', 'area', 'fallback_count', 'empty_count'], $topRows);
        }

        if (is_string($this->option('report-path')) && trim((string) $this->option('report-path')) !== '') {
            $this->newLine();
            $this->line('Relatorio JSON gravado em: ' . $this->resolveReportPath(trim((string) $this->option('report-path'))));
        }

        if ((bool) $this->option('fail-on-fallback')) {
            $this->newLine();

            if ($guardRailPassed) {
                $this->info('Guard rail OK: nenhum fallback legacy em uso.');

                return;
            }

            $this->error('Guard rail falhou: ainda existem valores lidos por fallback legacy em users.');
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return array{0: bool, 1: string|null}
     */
    private function evaluateGuardRail(array $report): array
    {
        $summary = $report['summary'] ?? [];

        $personalFallbackValues = (int) ($summary['personal_fallback_values'] ?? 0);
        $configurationFallbackValues = (int) ($summary['configuration_fallback_values'] ?? 0);

        if ($personalFallbackValues === 0 && $configurationFallbackValues === 0) {
            return [true, null];
        }

        return [false, 'Guard rail falhou: ainda existem valores lidos por fallback legacy em users.'];
    }

    private function exitCodeForGuardRail(bool $guardRailPassed): int
    {
        if (!(bool) $this->option('fail-on-fallback')) {
            return self::SUCCESS;
        }

        return $guardRailPassed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<int, int|string>>
     */
    private function buildTopFallbackRows(array $fields, bool $includePersonal, bool $includeConfiguration): array
    {
        $rows = [];

        if ($includePersonal) {
            foreach ($fields['personal'] as $field => $metrics) {
                $rows[] = [
                    'field' => $field,
                    'area' => 'personal',
                    'fallback_count' => (int) $metrics['fallback_count'],
                    'empty_count' => (int) $metrics['empty_count'],
                ];
            }
        }

        if ($includeConfiguration) {
            foreach ($fields['configuration'] as $field => $metrics) {
                $rows[] = [
                    'field' => $field,
                    'area' => 'configuration',
                    'fallback_count' => (int) $metrics['fallback_count'],
                    'empty_count' => (int) $metrics['empty_count'],
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $fallbackOrder = $b['fallback_count'] <=> $a['fallback_count'];
            if ($fallbackOrder !== 0) {
                return $fallbackOrder;
            }

            $emptyOrder = $b['empty_count'] <=> $a['empty_count'];
            if ($emptyOrder !== 0) {
                return $emptyOrder;
            }

            return strcmp((string) $a['field'], (string) $b['field']);
        });

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['fallback_count'] > 0 || $row['empty_count'] > 0
        ));
    }

    /**
     * @param string|list<string>|null $fallbackSource
     */
    private function firstFallbackValueFromUser(User $user, string|array|null $fallbackSource): mixed
    {
        if ($fallbackSource === null) {
            return null;
        }

        foreach ((array) $fallbackSource as $field) {
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

    private function writeReportFileIfRequested(array $report): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $absolutePath = $this->resolveReportPath($reportPath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $this->toJson($report));
    }

    private function resolveReportPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
