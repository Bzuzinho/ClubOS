<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AuditUsersLegacyFieldMapCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-field-map
        {--json : Devolve o relatorio em JSON}
        {--fail-on-unknown : Falha com codigo 1 se existir coluna fisica nao classificada}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita o mapa tecnico versionado dos campos legacy de users sem escrever dados';

    public function handle(): int
    {
        $config = config('member_user_legacy_fields');
        if (!is_array($config)) {
            $payload = [
                'summary' => [
                    'total_users_columns' => 0,
                    'classified_columns' => 0,
                    'unknown_columns' => [],
                    'missing_configured_columns' => [],
                    'categories_count' => 0,
                ],
                'columns_by_category' => [],
                'unknown_columns' => [],
                'missing_configured_columns' => [],
                'passed' => false,
                'failure_reason' => 'Configuracao member_user_legacy_fields inexistente ou invalida.',
            ];

            $this->writeReportFileIfRequested($payload);
            $this->outputPayload($payload);

            return self::FAILURE;
        }

        $categories = is_array($config['categories'] ?? null) ? $config['categories'] : [];
        $fieldToCategory = is_array($config['field_to_category'] ?? null) ? $config['field_to_category'] : [];

        $columns = array_values(array_unique(Schema::getColumnListing('users')));

        $columnsByCategory = [];
        foreach ($categories as $categoryName => $categoryDefinition) {
            $columnsByCategory[$categoryName] = [];
            $fields = is_array($categoryDefinition['fields'] ?? null) ? $categoryDefinition['fields'] : [];

            foreach ($fields as $field) {
                if (in_array($field, $columns, true)) {
                    $columnsByCategory[$categoryName][] = $field;
                }
            }
        }

        $unknownColumns = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $fieldToCategory)) {
                $unknownColumns[] = $column;
            }
        }

        $missingConfiguredColumns = [];
        foreach ($fieldToCategory as $field => $category) {
            if (!in_array($field, $columns, true)) {
                $missingConfiguredColumns[] = $field;
            }
        }

        sort($unknownColumns);
        sort($missingConfiguredColumns);

        $passed = !((bool) $this->option('fail-on-unknown') && $unknownColumns !== []);
        $failureReason = $passed ? null : 'Existem colunas fisicas nao classificadas em users.';

        $payload = [
            'version' => $config['version'] ?? null,
            'generated_context' => $config['generated_context'] ?? [],
            'summary' => [
                'total_users_columns' => count($columns),
                'classified_columns' => count($columns) - count($unknownColumns),
                'unknown_columns' => $unknownColumns,
                'missing_configured_columns' => $missingConfiguredColumns,
                'categories_count' => count($categories),
            ],
            'columns_by_category' => $columnsByCategory,
            'unknown_columns' => $unknownColumns,
            'missing_configured_columns' => $missingConfiguredColumns,
            'passed' => $passed,
            'failure_reason' => $failureReason,
        ];

        $this->writeReportFileIfRequested($payload);
        $this->outputPayload($payload);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function outputPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return;
        }

        $this->info(sprintf('Mapa legacy users %s', $payload['passed'] ? 'aprovado' : 'com observacoes'));
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['version', $payload['version'] ?? ''],
                ['total_users_columns', $payload['summary']['total_users_columns']],
                ['classified_columns', $payload['summary']['classified_columns']],
                ['unknown_columns', count($payload['unknown_columns'])],
                ['missing_configured_columns', count($payload['missing_configured_columns'])],
                ['categories_count', $payload['summary']['categories_count']],
                ['passed', $payload['passed'] ? 'true' : 'false'],
            ]
        );

        if ($payload['unknown_columns'] !== []) {
            $this->warn('Colunas nao classificadas: ' . implode(', ', $payload['unknown_columns']));
        }

        if ($payload['missing_configured_columns'] !== []) {
            $this->line('Colunas configuradas em falta no schema: ' . implode(', ', $payload['missing_configured_columns']));
        }

        if ($payload['failure_reason'] !== null) {
            $this->error((string) $payload['failure_reason']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $path = base_path($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}