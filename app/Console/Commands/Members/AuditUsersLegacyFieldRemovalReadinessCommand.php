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
        {--show-decisions : Mostra detalhe por campo com decisao e origem}
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
        $hasUnclassifiedDecisions = ((int) ($summary['unclassified_count'] ?? 0)) > 0;
        $hasActiveLegacyReads = ((int) ($summary['active_legacy_read_fields_count'] ?? 0)) > 0;

        $failureReasons = [];

        if ($unknownFlagEnabled && ($hasUnknownWithoutJustification || $hasUnclassifiedSchemaFields || $hasUnclassifiedDecisions)) {
            $failureReasons[] = 'Readiness audit failed. Existem campos sem classificacao/decisao segura.';
        }

        if ($activeReadFlagEnabled && $hasActiveLegacyReads) {
            $failureReasons[] = 'Readiness audit failed. Existem campos com leituras legacy diretas ativas.';
        }

        $passed = $failureReasons === [];
        $failureReason = $passed ? null : implode(' ', $failureReasons);

        $payload = [
            'version' => $audit['version'] ?? 'M4.12',
            'summary' => array_merge($summary, [
                'passed' => $passed,
                'failure_reason' => $failureReason,
            ]),
            'fields' => $audit['fields'] ?? [],
            'grouped_summary' => $audit['grouped_summary'] ?? [
                'by_category' => [],
                'by_removal_status' => [],
                'by_decision' => [],
                'by_risk' => [],
            ],
            'decision_summary' => is_array($audit['grouped_summary']['by_decision'] ?? null)
                ? $audit['grouped_summary']['by_decision']
                : [],
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
                ['version', (string) ($payload['version'] ?? 'M4.12')],
                ['total_configured_fields', (int) ($summary['total_configured_fields'] ?? 0)],
                ['fields_existing_in_schema', (int) ($summary['fields_existing_in_schema'] ?? 0)],
                ['fields_not_in_schema', (int) ($summary['fields_not_in_schema'] ?? 0)],
                ['candidate_after_legacy_write_cleanup_count', (int) ($summary['candidate_after_legacy_write_cleanup_count'] ?? 0)],
                ['keep_operational_count', (int) ($summary['keep_operational_count'] ?? 0)],
                ['needs_review_count', (int) ($summary['needs_review_count'] ?? 0)],
                ['needs_business_decision_count', (int) ($summary['needs_business_decision_count'] ?? 0)],
                ['keep_until_module_refactor_count', (int) ($summary['keep_until_module_refactor_count'] ?? 0)],
                ['keep_historical_or_external_reference_count', (int) ($summary['keep_historical_or_external_reference_count'] ?? 0)],
                ['explicit_decisions_count', (int) ($summary['explicit_decisions_count'] ?? 0)],
                ['inferred_decisions_count', (int) ($summary['inferred_decisions_count'] ?? 0)],
                ['unclassified_count', (int) ($summary['unclassified_count'] ?? 0)],
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
        $this->info('Resumo por decision');
        $this->renderKeyCountTable(is_array($grouped['by_decision'] ?? null) ? $grouped['by_decision'] : []);

        $this->newLine();
        $this->info('Resumo por risk');
        $this->renderKeyCountTable(is_array($grouped['by_risk'] ?? null) ? $grouped['by_risk'] : []);

        if ((bool) $this->option('show-decisions')) {
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
            $this->newLine();
            $this->info('Decisoes por campo');
            $this->renderDecisionRows($fields);
        }

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

    /**
     * @param list<array<string,mixed>> $fields
     */
    private function renderDecisionRows(array $fields): void
    {
        if ($fields === []) {
            $this->table(['field', 'decision', 'source', 'risk', 'category', 'canonical_area'], [['(none)', '-', '-', '-', '-', '-']]);

            return;
        }

        $rows = [];
        foreach ($fields as $fieldRow) {
            if (!is_array($fieldRow)) {
                continue;
            }

            $rows[] = [
                (string) ($fieldRow['field'] ?? ''),
                (string) ($fieldRow['decision'] ?? ($fieldRow['removal_status'] ?? 'unknown')),
                (string) ($fieldRow['decision_source'] ?? 'inferred'),
                (string) ($fieldRow['risk'] ?? 'unknown'),
                (string) ($fieldRow['category'] ?? 'unknown'),
                (string) ($fieldRow['canonical_area'] ?? 'unknown'),
            ];
        }

        $this->table(['field', 'decision', 'source', 'risk', 'category', 'canonical_area'], $rows);
    }
}
