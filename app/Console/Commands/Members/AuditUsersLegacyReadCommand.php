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

        $payload = [
            'summary' => $scan['summary'],
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

        if ($summary['findings_count'] === 0) {
            $this->info('Auditoria de leituras legacy users aprovada');

            return;
        }

        $this->warn('Auditoria encontrou leituras diretas legacy em users');

        $rows = [];
        foreach (array_slice($payload['findings'], 0, 30) as $finding) {
            $rows[] = [
                $finding['file'] ?? '',
                $finding['field'] ?? '',
                $finding['pattern'] ?? '',
                $finding['line'] ?? '',
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['file', 'field', 'pattern', 'line'], $rows);
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
}
