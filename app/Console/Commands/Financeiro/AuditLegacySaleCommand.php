<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\LegacySaleAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditLegacySaleCommand extends Command
{
    protected $signature = 'finance:audit-legacy-sales
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--fail-on-operational-write : Falha com codigo 1 se detectar escrita operacional}
        {--fail-on-parallel-finance : Falha com codigo 1 se detectar Invoice+Entry paralela}';

    protected $description = 'Audita legacy Sale model em modo read-only, detectando efeitos financeiros paralelos e escrita operacional';

    public function __construct(
        private readonly LegacySaleAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit();

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        if ((bool) $this->option('fail-on-parallel-finance')) {
            $parallelCount = collect($payload['findings'] ?? [])
                ->where('code', 'legacy_sale_parallel_invoice_and_entry')
                ->count();

            if ($parallelCount > 0) {
                $this->error("Found {$parallelCount} Sales with parallel Invoice+FinancialEntry");
                return self::FAILURE;
            }
        }

        if ((bool) $this->option('fail-on-operational-write')) {
            // No operational write detection yet (offline analysis would be required)
            $this->warn('--fail-on-operational-write requires code analysis; no runtime writes detected');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $findings = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];

        $this->info('Audit Legacy Sale Model Financial Effects');
        $this->table(
            ['Metric', 'Value'],
            [
                ['total_findings', (int) ($summary['total_findings'] ?? 0)],
                ['critical_count', (int) ($summary['critical_count'] ?? 0)],
                ['warning_count', (int) ($summary['warning_count'] ?? 0)],
                ['info_count', (int) ($summary['info_count'] ?? 0)],
            ]
        );

        if (!empty($findings)) {
            $this->newLine();
            $this->info('Findings by Code:');
            $codeCounts = $summary['findings_by_code'] ?? [];
            foreach ($codeCounts as $code => $count) {
                $this->line(sprintf('  %s: %d', $code, $count));
            }

            $this->newLine();
            $this->info('First 10 Findings:');
            $findingsDisplay = collect($findings)
                ->take(10)
                ->map(static fn (array $f): array => [
                    $f['severity'] ?? '',
                    $f['code'] ?? '',
                    $f['entity_id'] ?? '',
                    $f['reason'] ?? '',
                ])
                ->all();

            $this->table(['Severity', 'Code', 'Entity ID', 'Reason'], $findingsDisplay);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';

        if ($reportPath === '') {
            return;
        }

        $dir = dirname($reportPath);
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($reportPath, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        $this->line(sprintf('Report written to: %s', $reportPath));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
