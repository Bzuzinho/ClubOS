<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyBackfillValidationAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditUsersLegacyBackfillValidationCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-backfill-validation
        {--json : Devolve o relatorio em JSON}
        {--field= : Limita a auditoria a um campo candidate_after_backfill_validation}
        {--fail-on-divergence : Falha com codigo 1 quando existem divergencias}
        {--fail-on-legacy-only : Falha com codigo 1 quando existem valores apenas em legacy}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita divergencias legacy vs canonico para campos candidate_after_backfill_validation (read-only)';

    public function __construct(private readonly UsersLegacyBackfillValidationAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fieldFilter = is_string($this->option('field')) ? trim((string) $this->option('field')) : '';
        $audit = $this->auditor->audit($fieldFilter !== '' ? $fieldFilter : null);

        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $fields = is_array($audit['fields'] ?? null) ? $audit['fields'] : [];

        $failureReasons = [];

        if ($fieldFilter !== '' && $fields === []) {
            $failureReasons[] = sprintf('Backfill validation audit failed. Campo candidate_after_backfill_validation nao encontrado: %s.', $fieldFilter);
        }

        if ((bool) $this->option('fail-on-divergence') && ((int) ($summary['total_divergent_count'] ?? 0)) > 0) {
            $failureReasons[] = 'Backfill validation audit failed. Existem divergencias entre legacy e canonico.';
        }

        if ((bool) $this->option('fail-on-legacy-only') && ((int) ($summary['total_legacy_only_count'] ?? 0)) > 0) {
            $failureReasons[] = 'Backfill validation audit failed. Existem valores apenas em legacy.';
        }

        $passed = $failureReasons === [];
        $failureReason = $passed ? null : implode(' ', $failureReasons);

        $payload = [
            'version' => $audit['version'] ?? 'M4.13',
            'summary' => array_merge($summary, [
                'passed' => $passed,
                'failure_reason' => $failureReason,
            ]),
            'fields' => $fields,
            'grouped_summary' => $audit['grouped_summary'] ?? [
                'by_readiness_status' => [],
                'by_canonical_area' => [],
                'by_category' => [],
            ],
        ];

        $this->writeReportFileIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHumanReport($payload, $fieldFilter);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload, string $fieldFilter): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $groupedSummary = is_array($payload['grouped_summary'] ?? null) ? $payload['grouped_summary'] : [];

        $this->info('Audit users legacy backfill validation (read-only)');
        if ($fieldFilter !== '') {
            $this->line(sprintf('Campo filtrado: %s', $fieldFilter));
        }

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['version', (string) ($payload['version'] ?? 'M4.13')],
                ['fields_analyzed', (int) ($summary['fields_analyzed'] ?? 0)],
                ['users_analyzed', (int) ($summary['users_analyzed'] ?? 0)],
                ['ready_for_cleanup_count', (int) ($summary['ready_for_cleanup_count'] ?? 0)],
                ['needs_backfill_count', (int) ($summary['needs_backfill_count'] ?? 0)],
                ['needs_manual_review_count', (int) ($summary['needs_manual_review_count'] ?? 0)],
                ['total_legacy_only_count', (int) ($summary['total_legacy_only_count'] ?? 0)],
                ['total_divergent_count', (int) ($summary['total_divergent_count'] ?? 0)],
                ['total_non_scalar_review_count', (int) ($summary['total_non_scalar_review_count'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ]
        );

        $this->newLine();
        $this->info('Resumo por field');
        $this->renderFieldTable($fields);

        $this->newLine();
        $this->info('Resumo por readiness_status');
        $this->renderKeyCountTable(is_array($groupedSummary['by_readiness_status'] ?? null) ? $groupedSummary['by_readiness_status'] : []);

        $this->newLine();
        $this->info('Resumo por canonical_area');
        $this->renderKeyCountTable(is_array($groupedSummary['by_canonical_area'] ?? null) ? $groupedSummary['by_canonical_area'] : []);

        $this->newLine();
        $this->info('Resumo por category');
        $this->renderKeyCountTable(is_array($groupedSummary['by_category'] ?? null) ? $groupedSummary['by_category'] : []);

        $this->renderSampleDifferences($fields);

        $failureReason = is_string($summary['failure_reason'] ?? null) ? $summary['failure_reason'] : null;
        if ($failureReason !== null && trim($failureReason) !== '') {
            $this->newLine();
            $this->error($failureReason);
        }
    }

    /**
     * @param list<array<string,mixed>> $fields
     */
    private function renderFieldTable(array $fields): void
    {
        if ($fields === []) {
            $this->table(['field', 'readiness_status', 'risk'], [['(none)', 'n/a', 'n/a']]);

            return;
        }

        $rows = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $rows[] = [
                (string) ($field['field'] ?? ''),
                (string) ($field['canonical_area'] ?? 'unknown'),
                (string) ($field['canonical_field'] ?? 'unknown'),
                (int) ($field['legacy_non_empty_count'] ?? 0),
                (int) ($field['canonical_non_empty_count'] ?? 0),
                (int) ($field['matching_non_empty_count'] ?? 0),
                (int) ($field['legacy_only_count'] ?? 0),
                (int) ($field['canonical_only_count'] ?? 0),
                (int) ($field['divergent_count'] ?? 0),
                (int) ($field['empty_both_count'] ?? 0),
                (int) ($field['non_scalar_review_count'] ?? 0),
                (string) ($field['readiness_status'] ?? 'unknown'),
                (string) ($field['risk'] ?? 'unknown'),
            ];
        }

        $this->table(
            [
                'field',
                'canonical_area',
                'canonical_field',
                'legacy',
                'canonical',
                'match',
                'legacy_only',
                'canonical_only',
                'divergent',
                'empty_both',
                'non_scalar',
                'readiness_status',
                'risk',
            ],
            $rows,
        );
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
     * @param list<array<string,mixed>> $fields
     */
    private function renderSampleDifferences(array $fields): void
    {
        $rows = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $samples = is_array($field['sample_differences'] ?? null) ? $field['sample_differences'] : [];
            foreach ($samples as $sample) {
                if (!is_array($sample)) {
                    continue;
                }

                $rows[] = [
                    (string) ($field['field'] ?? ''),
                    (string) ($sample['user_id'] ?? ''),
                    (string) ($sample['classification'] ?? ''),
                    $sample['legacy_value'] === null ? 'null' : (string) $sample['legacy_value'],
                    $sample['canonical_value'] === null ? 'null' : (string) $sample['canonical_value'],
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->info('Sample differences (max 5 por campo)');
        $this->table(['field', 'user_id', 'classification', 'legacy_value', 'canonical_value'], $rows);
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