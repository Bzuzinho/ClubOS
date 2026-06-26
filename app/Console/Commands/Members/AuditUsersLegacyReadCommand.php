<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditUsersLegacyReadCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-read
        {--json : Devolve o relatorio em JSON}
        {--fail-on-finding : Falha com codigo 1 quando existem findings}
        {--report-path= : Caminho para guardar relatorio JSON}
        {--group-by=group : Agrupamento do output humano: group|file|field|priority}
        {--summary-only : Mostra apenas contagens agregadas}
        {--path=* : Limita scan a path(s) especificos (pode repetir)}';

    protected $description = 'Audita leituras diretas de campos legacy pessoais/configuracao em users';

    public function __construct(private readonly UsersLegacyReadScanner $scanner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = $this->normalizePathsOption();

        $scan = $this->scanner->scan(
            $paths === [] ? null : $paths,
            $this->scanner->defaultAllowlist(),
        );

        $hasFindings = $scan['summary']['findings_count'] > 0;
        $shouldFail = (bool) $this->option('fail-on-finding') && $hasFindings;
        $groupedSummary = $this->scanner->groupedSummary($scan['findings']);

        $payload = [
            'summary' => $scan['summary'],
            'grouped_summary' => $groupedSummary,
            'findings' => $scan['findings'],
            'passed' => !$shouldFail,
            'failure_reason' => $shouldFail
                ? 'Legacy users read audit failed. Existem leituras diretas de campos legacy bloqueados.'
                : null,
        ];

        $this->writeReportFileIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return $shouldFail ? self::FAILURE : self::SUCCESS;
        }

        $this->renderHumanReport($payload);

        return $shouldFail ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function normalizePathsOption(): array
    {
        $raw = $this->option('path');
        if (!is_array($raw)) {
            return [];
        }

        $paths = [];
        foreach ($raw as $path) {
            if (!is_string($path)) {
                continue;
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }

            $paths[] = $trimmed;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = $payload['summary'];
        $groupBy = $this->normalizeGroupByOption();
        $summaryOnly = (bool) $this->option('summary-only');

        $this->info('Audit users legacy read (read-only)');
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['blocked_fields_count', $summary['blocked_fields_count']],
                ['scanned_files', $summary['scanned_files']],
                ['findings_count', $summary['findings_count']],
                ['passed', $payload['passed'] ? 'true' : 'false'],
            ]
        );

        $this->newLine();
        $this->renderGroupedSummary($payload['grouped_summary']);

        if ($summaryOnly) {
            return;
        }

        if ($summary['findings_count'] === 0) {
            $this->info('Auditoria de leituras legacy users aprovada');

            return;
        }

        $this->warn('Auditoria encontrou leituras diretas legacy em users');

        $rows = [];
        foreach (array_slice($this->groupFindingsForHumanReport($payload['findings'], $groupBy), 0, 30) as $finding) {
            $rows[] = [
                $finding['group_key'] ?? '',
                $finding['file'] ?? '',
                $finding['field'] ?? '',
                $finding['pattern'] ?? '',
                $finding['line'] ?? '',
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table([$groupBy, 'file', 'field', 'pattern', 'line'], $rows);
        }

        if ($payload['failure_reason'] !== null) {
            $this->newLine();
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
        File::put($path, $this->toJson($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function normalizeGroupByOption(): string
    {
        $groupBy = is_string($this->option('group-by')) ? strtolower(trim((string) $this->option('group-by'))) : 'group';

        return in_array($groupBy, ['group', 'file', 'field', 'priority'], true) ? $groupBy : 'group';
    }

    /**
     * @param array{by_remediation_group:array<string,int>,by_severity:array<string,int>,by_migration_priority:array<string,int>,by_file:array<string,int>} $groupedSummary
     */
    private function renderGroupedSummary(array $groupedSummary): void
    {
        $this->info('Resumo por remediation_group');
        $this->renderKeyCountTable($groupedSummary['by_remediation_group'] ?? []);

        $this->newLine();
        $this->info('Resumo por severity');
        $this->renderKeyCountTable($groupedSummary['by_severity'] ?? []);

        $this->newLine();
        $this->info('Resumo por migration_priority');
        $this->renderKeyCountTable($groupedSummary['by_migration_priority'] ?? []);
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
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function groupFindingsForHumanReport(array $findings, string $groupBy): array
    {
        $normalized = [];

        foreach ($findings as $finding) {
            $groupKey = match ($groupBy) {
                'file' => (string) ($finding['file'] ?? ''),
                'field' => (string) ($finding['field'] ?? ''),
                'priority' => (string) ($finding['migration_priority'] ?? ''),
                default => (string) ($finding['remediation_group'] ?? ''),
            };

            $finding['group_key'] = $groupKey;
            $normalized[] = $finding;
        }

        usort($normalized, static function (array $left, array $right): int {
            $groupCmp = strcmp((string) ($left['group_key'] ?? ''), (string) ($right['group_key'] ?? ''));
            if ($groupCmp !== 0) {
                return $groupCmp;
            }

            $fileCmp = strcmp((string) ($left['file'] ?? ''), (string) ($right['file'] ?? ''));
            if ($fileCmp !== 0) {
                return $fileCmp;
            }

            return ((int) ($left['line'] ?? 0)) <=> ((int) ($right['line'] ?? 0));
        });

        return $normalized;
    }
}
