<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\StockCorrectionApplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StockCorrectionApplicationCommand extends Command
{
    protected $signature = 'inventory:apply-stock-corrections
        {--material=* : Um ou mais material/product IDs}
        {--action= : Tipo de acao a considerar, ex. create_missing_sale_stock_decrease}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--dry-run : Forca modo dry-run}
        {--apply : Aplica apenas acoes seguras}
        {--confirm-stock-correction : Confirmacao explicita para aplicar correcoes de stock}
        {--only-safe : Mostra/processa apenas acoes seguras propostas}
        {--fail-on-unsafe : Exit code 1 se houver acoes inseguras ou com revisao manual}';

    protected $description = 'Aplica de forma controlada correcoes seguras de stock propostas pelo preflight';

    public function __construct(
        private readonly StockCorrectionApplicationService $applicationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->applicationService->run([
            'material' => $this->option('material'),
            'action' => $this->option('action'),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_stock_correction' => (bool) $this->option('confirm-stock-correction'),
            'only_safe' => (bool) $this->option('only-safe'),
            'fail_on_unsafe' => (bool) $this->option('fail-on-unsafe'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) ($payload['blocked'] ?? false) || (int) data_get($payload, 'summary.failed_count', 0) > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-unsafe') && (int) data_get($payload, 'summary.unsafe_action_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Inventory stock correction application');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Material', 'Action', 'Safe', 'Applied', 'Skipped', 'Movement', 'Stock Before', 'Stock After', 'Error'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) (($item['material_name'] ?? null) ?: ($item['material_id'] ?? '')),
                    (string) ($item['action_type'] ?? ''),
                    (bool) ($item['safe_to_apply'] ?? false) ? 'yes' : 'no',
                    (bool) ($item['applied'] ?? false) ? 'yes' : 'no',
                    (bool) ($item['skipped'] ?? false) ? 'yes' : 'no',
                    (string) ($item['stock_movement_id'] ?? ''),
                    (string) ($item['product_stock_before'] ?? ''),
                    (string) ($item['product_stock_after'] ?? ''),
                    (string) ($item['error'] ?? ''),
                ])
                ->all(),
        );

        if (($payload['block_reason'] ?? null) !== null) {
            $this->warn('Blocked: '.(string) $payload['block_reason']);
        }
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
