<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyFieldRemovalReadinessAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditUsersLegacyFieldRemovalReadinessCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-field-removal-readiness
        {--json : Devolve o relatorio em JSON}
        {--fail-on-unknown : Falha com codigo 1 quando existem campos desconhecidos sem classificacao segura}
        {--fail-on-unclassified : Alias de --fail-on-unknown para manter compatibilidade sem quebrar producao}
        {--fail-on-active-legacy-read : Falha com codigo 1 quando existe leitura legacy ativa por campo}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita readiness de remocao/isolamento dos campos legacy de membro em users (read-only)';

    public function __construct(private readonly UsersLegacyFieldRemovalReadinessAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $audit = $this->auditor->audit();
        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];

        $unknownFlagEnabled = (bool) $this->option('fail-on-unknown') || (bool) $this->option('fail-on-unclassified');
        $activeReadFlagEnabled = (bool) $this->option('fail-on-active-legacy-read');

        $hasUnknownWithoutJustification = ((int) ($summary['unknown_without_justification_count'] ?? 0)) > 0;
        $hasUnclassifiedSchemaFields = ((int) ($summary['unclassified_schema_fields_count'] ?? 0)) > 0;
        $hasActiveLegacyReads = ((int) ($summary['active_legacy_read_fields_count'] ?? 0)) > 0;

        $failureReasons = [];

        if ($unknownFlagEnabled && ($hasUnknownWithoutJustification || $hasUnclassifiedSchemaFields)) {
            $failureReasons[] = 'Readiness audit failed. Existem campos desconhecidos sem classificacao segura.';
        }

        if ($activeReadFlagEnabled && $hasActiveLegacyReads) {
            $failureReasons[] = 'Readiness audit failed. Existem campos com leituras legacy diretas ativas.';
        }

        $passed = $failureReasons === [];
        $failureReason = $passed ? null : implode(' ', $failureReasons);

        $payload = [
            'version' => $audit['version'] ?? 'M4.11',
            'summary' => array_merge($summary, [
                'passed' => $passed,
                'failure_reason' => $failureReason,
            ]),
            'fields' => $audit['fields'] ?? [],
            'grouped_summary' => $audit['grouped_summary'] ?? [
                'by_category' => [],
                'by_removal_status' => [],
                'by_risk' => [],
            ],
        ];

        $this->writeReportFileIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHumanReport($payload);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->info('Audit users legacy field removal readiness (read-only)');
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['version', (string) ($payload['version'] ?? 'M4.11')],
                ['total_configured_fields', (int) ($summary['total_configured_fields'] ?? 0)],
                ['fields_existing_in_schema', (int) ($summary['fields_existing_in_schema'] ?? 0)],
                ['fields_not_in_schema', (int) ($summary['fields_not_in_schema'] ?? 0)],
                ['candidate_after_legacy_write_cleanup_count', (int) ($summary['candidate_after_legacy_write_cleanup_count'] ?? 0)],
                ['keep_operational_count', (int) ($summary['keep_operational_count'] ?? 0)],
                ['needs_review_count', (int) ($summary['needs_review_count'] ?? 0)],
                ['unknown_count', (int) ($summary['unknown_count'] ?? 0)],
                ['active_legacy_read_fields_count', (int) ($summary['active_legacy_read_fields_count'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ]
        );

        $grouped = is_array($payload['grouped_summary'] ?? null) ? $payload['grouped_summary'] : [];

        $this->newLine();
        $this->info('Resumo por category');
        $this->renderKeyCountTable(is_array($grouped['by_category'] ?? null) ? $grouped['by_category'] : []);

        $this->newLine();
        $this->info('Resumo por removal_status');
        $this->renderKeyCountTable(is_array($grouped['by_removal_status'] ?? null) ? $grouped['by_removal_status'] : []);

        $this->newLine();
        $this->info('Resumo por risk');
        $this->renderKeyCountTable(is_array($grouped['by_risk'] ?? null) ? $grouped['by_risk'] : []);

        $failureReason = is_string($summary['failure_reason'] ?? null) ? $summary['failure_reason'] : null;
        if ($failureReason !== null && trim($failureReason) !== '') {
            $this->newLine();
            $this->error($failureReason);
        }
    }

    /**
     * @param array<string,int> $counts
     */
    private function renderKeyCountTable(array $counts): void
    {
        if ($counts === []) {
            $this->table(['key', 'count'], [['(none)', 0]]);

            return;
        }

        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [(string) $key, (int) $count];
        }

        $this->table(['key', 'count'], $rows);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $path = base_path($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
