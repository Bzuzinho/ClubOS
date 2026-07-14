<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\StaleFiscalRequestCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StaleFiscalRequestCleanupCommand extends Command
{
    protected $signature = 'finance:cleanup-stale-fiscal-requests
        {--dry-run : Mostra o que seria feito sem alterar dados}
        {--apply : Aplica apenas limpezas seguras}
        {--invoice= : Limpa apenas uma fatura}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--fail-on-unsafe : Falha com codigo 1 quando existem candidatos inseguros}';

    protected $description = 'Arquiva logicamente pedidos fiscais pendentes stale em modo controlado';

    public function __construct(
        private readonly StaleFiscalRequestCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->cleanupService->run([
            'apply' => (bool) $this->option('apply'),
            'invoice' => is_string($this->option('invoice')) ? (string) $this->option('invoice') : null,
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-unsafe') && (int) data_get($payload, 'summary.unsafe_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $this->info('Stale fiscal request cleanup');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Classification', 'Fiscal Request', 'Invoice', 'Status', 'Deleted', 'Applied', 'Recommendation'],
            collect($items)->map(static fn (array $item): array => [
                (string) ($item['classification'] ?? ''),
                (string) ($item['fiscal_request_id'] ?? ''),
                (string) ($item['invoice_id'] ?? ''),
                (string) ($item['status'] ?? ''),
                (string) ($item['deleted_at'] ?? ''),
                ((bool) ($item['applied'] ?? false)) ? 'yes' : 'no',
                (string) ($item['recommendation'] ?? ''),
            ])->all(),
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
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
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
