<?php

declare(strict_types=1);

namespace App\Console\Commands\Communication;

use App\Services\Communication\CommunicationAsyncPipelineAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CommunicationAsyncPipelineAuditCommand extends Command
{
    protected $signature = 'communication:audit-async-pipeline
        {--json : Devolve relatório em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--fail-on-warning : Exit code 1 se houver warning ou critical}
        {--fail-on-critical : Exit code 1 se houver critical}';

    protected $description = 'Auditoria read-only do pipeline assíncrono persistente de Comunicação';

    public function __construct(private readonly CommunicationAsyncPipelineAuditService $auditService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $reportPath = trim((string) ($this->option('report-path') ?? ''));

        if ($reportPath !== '') {
            $path = str_starts_with($reportPath, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $reportPath) === 1
                ? $reportPath
                : base_path($reportPath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->info('Communication async pipeline audit');
            $this->table(
                ['Metric', 'Value'],
                collect($payload['summary'])->map(static fn (mixed $value, string $key): array => [$key, (string) $value])->values()->all(),
            );
        }

        $critical = (int) data_get($payload, 'summary.critical_count', 0);
        $warning = (int) data_get($payload, 'summary.warning_count', 0);

        if ((bool) $this->option('fail-on-critical') && $critical > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-warning') && ($critical > 0 || $warning > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
