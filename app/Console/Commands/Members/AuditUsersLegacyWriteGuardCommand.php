<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyWriteGuardScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditUsersLegacyWriteGuardCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-write-guard
        {--json : Devolve o relatorio em JSON}
        {--fail-on-violation : Falha com codigo 1 quando existem violacoes}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita escritas diretas de campos legacy pessoais/configuracao em users';

    public function __construct(private readonly UsersLegacyWriteGuardScanner $scanner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $scan = $this->scanner->scan();
        $hasViolations = $scan['violations'] !== [];
        $shouldFail = (bool) $this->option('fail-on-violation') && $hasViolations;

        $payload = [
            'summary' => [
                'blocked_fields_count' => $scan['blocked_fields_count'],
                'scanned_files' => $scan['scanned_files'],
                'violations_count' => count($scan['violations']),
            ],
            'violations' => $scan['violations'],
            'passed' => !$shouldFail,
            'failure_reason' => $shouldFail
                ? 'Legacy users write guard failed. Existem violacoes de escrita direta em campos bloqueados.'
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
     * @param array<string, mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = $payload['summary'];

        $this->info('Audit users legacy write guard (read-only)');
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['blocked_fields_count', $summary['blocked_fields_count']],
                ['scanned_files', $summary['scanned_files']],
                ['violations_count', $summary['violations_count']],
                ['passed', $payload['passed'] ? 'true' : 'false'],
            ]
        );

        if ($payload['violations'] === []) {
            $this->info('Sem violacoes encontradas.');
        } else {
            $rows = [];
            foreach (array_slice($payload['violations'], 0, 30) as $violation) {
                $rows[] = [
                    $violation['file'] ?? '',
                    $violation['field'] ?? '',
                    $violation['pattern'] ?? '',
                    $violation['line'] ?? '',
                ];
            }

            $this->newLine();
            $this->warn('Violacoes encontradas (max 30):');
            $this->table(['file', 'field', 'pattern', 'line'], $rows);

            $first = $payload['violations'][0] ?? null;
            if (is_array($first)) {
                $this->newLine();
                $this->error(sprintf(
                    'Legacy users write guard failed. Campo %s encontrado em contexto de escrita no ficheiro %s.',
                    (string) ($first['field'] ?? '?'),
                    (string) ($first['file'] ?? '?')
                ));
            }
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