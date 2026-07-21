<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\StockSourceOfTruthAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StockSourceOfTruthAuditCommand extends Command
{
    protected $signature = 'inventory:audit-stock-source-of-truth
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-actionable : Mostra apenas findings acionaveis}
        {--include-info : Inclui findings informativos nao acionaveis no output principal}
        {--fail-on-warning : Exit code 1 se houver warning ou critical}
        {--fail-on-critical : Exit code 1 se houver critical}';

    protected $description = 'Auditoria read-only da fonte de verdade de stock e snapshots derivados';

    public function __construct(
        private readonly StockSourceOfTruthAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'only_actionable' => (bool) $this->option('only-actionable'),
            'include_info' => (bool) $this->option('include-info'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-critical') && (int) data_get($payload, 'summary.critical_count', 0) > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-warning') && ((int) data_get($payload, 'summary.warning_count', 0) > 0 || (int) data_get($payload, 'summary.critical_count', 0) > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Inventory stock source of truth audit');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])->map(static fn (mixed $value, string $key): array => [$key, (string) $value])->values()->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Material', 'Movement', 'Source', 'Actionable', 'Recommendation'],
            collect($payload['findings'] ?? [])->map(static fn (array $finding): array => [
                (string) ($finding['severity'] ?? ''),
                (string) ($finding['code'] ?? ''),
                (string) (($finding['material_name'] ?? null) ?: ($finding['material_id'] ?? '')),
                (string) ($finding['movement_id'] ?? ''),
                trim((string) ($finding['source_type'] ?? '').':'.(string) ($finding['source_id'] ?? ''), ':'),
                (bool) ($finding['actionable'] ?? false) ? 'yes' : 'no',
                (string) ($finding['recommendation'] ?? ''),
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

        $reportPath = str_starts_with($reportPathOption, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $reportPathOption) === 1
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
