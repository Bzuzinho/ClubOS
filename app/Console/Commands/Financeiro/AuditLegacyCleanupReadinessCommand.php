<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FinanceLegacyCleanupReadinessAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditLegacyCleanupReadinessCommand extends Command
{
    protected $signature = 'finance:audit-legacy-cleanup-readiness
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--field= : Limita a auditoria a um campo (centro_custo|tipo_mensalidade|conta_corrente)}
        {--fail-on-not-ready : Falha com codigo 1 quando existir campo nao ready_for_cleanup}';

    protected $description = 'Audita readiness para cleanup fisico de campos financeiros legacy em users (read-only)';

    public function __construct(private readonly FinanceLegacyCleanupReadinessAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $field = is_string($this->option('field')) ? trim((string) $this->option('field')) : '';

        try {
            $payload = $this->auditor->audit($field !== '' ? $field : null);
        } catch (\InvalidArgumentException $exception) {
            $payload = [
                'version' => 'fc1-finance-legacy-cleanup-readiness-v1',
                'generated_at' => now()->toIso8601String(),
                'scope' => ['field' => $field !== '' ? $field : 'all'],
                'summary' => [
                    'total_fields' => 0,
                    'ready_fields_count' => 0,
                    'not_ready_fields_count' => 0,
                    'prohibited_read_findings_count' => 0,
                    'prohibited_write_findings_count' => 0,
                    'unknown_findings_count' => 0,
                    'passed' => false,
                    'failure_reason' => $exception->getMessage(),
                ],
                'fields' => [],
                'code_findings' => [],
            ];

            $this->writeReportFileIfRequested($payload);
            $this->renderOutput($payload);

            return self::FAILURE;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $hasNotReady = (int) ($summary['not_ready_fields_count'] ?? 0) > 0;
        $shouldFail = (bool) $this->option('fail-on-not-ready') && $hasNotReady;

        $payload['summary'] = array_merge($summary, [
            'passed' => !$shouldFail,
            'failure_reason' => $shouldFail
                ? 'Finance legacy cleanup readiness audit failed. Existem campos ainda nao ready_for_cleanup.'
                : null,
        ]);

        $this->writeReportFileIfRequested($payload);
        $this->renderOutput($payload);

        return $shouldFail ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPathOption = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPathOption === '') {
            return;
        }

        $reportPath = str_starts_with($reportPathOption, '/')
            ? $reportPathOption
            : base_path($reportPathOption);

        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, $this->toJson($payload));

        $this->line(sprintf('Report written to: %s', $reportPath));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        $this->info('Finance legacy cleanup readiness audit (FC1, read-only)');
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? 'fc1-finance-legacy-cleanup-readiness-v1')));

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_fields', (int) ($summary['total_fields'] ?? 0)],
                ['ready_fields_count', (int) ($summary['ready_fields_count'] ?? 0)],
                ['not_ready_fields_count', (int) ($summary['not_ready_fields_count'] ?? 0)],
                ['prohibited_read_findings_count', (int) ($summary['prohibited_read_findings_count'] ?? 0)],
                ['prohibited_write_findings_count', (int) ($summary['prohibited_write_findings_count'] ?? 0)],
                ['unknown_findings_count', (int) ($summary['unknown_findings_count'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ]
        );

        $this->newLine();
        $this->table(
            [
                'field',
                'legacy_column_exists',
                'fallback_count',
                'divergence_count',
                'invalid_count',
                'direct_reads',
                'direct_writes',
                'ready_for_cleanup',
            ],
            array_map(static function (array $fieldRow): array {
                $metrics = is_array($fieldRow['metrics'] ?? null) ? $fieldRow['metrics'] : [];

                return [
                    (string) ($fieldRow['field'] ?? ''),
                    ((bool) ($fieldRow['legacy_column_exists'] ?? false)) ? 'true' : 'false',
                    (int) ($metrics['fallback_count'] ?? 0),
                    (int) ($metrics['divergence_count'] ?? 0),
                    (int) ($metrics['invalid_count'] ?? 0),
                    (int) ($metrics['direct_read_findings_count'] ?? 0),
                    (int) ($metrics['direct_write_findings_count'] ?? 0),
                    ((bool) ($fieldRow['ready_for_cleanup'] ?? false)) ? 'true' : 'false',
                ];
            }, $fields)
        );

        $failureReason = is_string($summary['failure_reason'] ?? null) ? trim((string) $summary['failure_reason']) : '';
        if ($failureReason !== '') {
            $this->newLine();
            $this->error($failureReason);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
