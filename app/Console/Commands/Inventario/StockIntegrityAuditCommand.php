<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\StockIntegrityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StockIntegrityAuditCommand extends Command
{
    protected $signature = 'inventory:audit-stock-integrity
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--material= : Filtrar por material/produto}
        {--category= : Filtrar por categoria}
        {--location= : Filtrar por local/area de armazenamento}
        {--include-zero : Inclui materiais com stock zero}
        {--include-inactive : Inclui materiais inativos}
        {--include-deleted : Inclui registos soft-deleted quando suportado}
        {--include-clean : Inclui materiais sem findings como info}
        {--only-actionable : Mostra apenas findings acionaveis}
        {--fail-on-critical : Exit code 1 se houver critical}
        {--fail-on-warning : Exit code 1 se houver warning ou critical}';

    protected $description = 'Auditoria read-only de integridade de inventario, materiais e stock';

    public function __construct(
        private readonly StockIntegrityAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'material' => $this->option('material'),
            'category' => $this->option('category'),
            'location' => $this->option('location'),
            'include_zero' => (bool) $this->option('include-zero'),
            'include_inactive' => (bool) $this->option('include-inactive'),
            'include_deleted' => (bool) $this->option('include-deleted'),
            'include_clean' => (bool) $this->option('include-clean'),
            'only_actionable' => (bool) $this->option('only-actionable'),
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
        $this->info('Inventory stock integrity audit');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Material', 'Stock', 'Calculated', 'Difference', 'Related', 'Actionable', 'Recommendation'],
            collect($payload['findings'] ?? [])
                ->map(static fn (array $finding): array => [
                    (string) ($finding['severity'] ?? ''),
                    (string) ($finding['code'] ?? ''),
                    (string) (($finding['material_name'] ?? null) ?: ($finding['material_id'] ?? '')),
                    (string) ($finding['stock_current'] ?? ''),
                    (string) ($finding['stock_calculated'] ?? ''),
                    (string) ($finding['stock_difference'] ?? ''),
                    (string) (($finding['movement_id'] ?? null) ?: ($finding['loan_id'] ?? null) ?: ($finding['request_id'] ?? null) ?: ($finding['sale_id'] ?? null) ?: ($finding['invoice_item_id'] ?? '')),
                    (bool) ($finding['actionable'] ?? false) ? 'yes' : 'no',
                    (string) ($finding['recommendation'] ?? ''),
                ])
                ->all(),
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
