<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\StockMismatchInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StockMismatchInspectionCommand extends Command
{
    protected $signature = 'inventory:inspect-stock-mismatch
        {--material=* : Um ou mais material/product IDs}
        {--sku= : SKU/codigo do produto ou variante}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-mismatch : Mostra apenas produtos com divergencia}
        {--fail-on-mismatch : Exit code 1 se houver divergencias}';

    protected $description = 'Inspecao read-only de divergencias de stock por produto';

    public function __construct(
        private readonly StockMismatchInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect([
            'material' => $this->option('material'),
            'sku' => $this->option('sku'),
            'only_mismatch' => (bool) $this->option('only-mismatch'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-mismatch') && (int) data_get($payload, 'summary.mismatch_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Inventory stock mismatch inspection');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Material', 'Stored', 'Calculated', 'Difference', 'Movements', 'Suspicious', 'Recommendation'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) data_get($item, 'material.name', data_get($item, 'material.id', '')),
                    (string) ($item['stored_stock'] ?? ''),
                    (string) ($item['calculated_stock'] ?? ''),
                    (string) ($item['difference'] ?? ''),
                    (string) count($item['movements'] ?? []),
                    (string) count(data_get($item, 'analysis.suspicion_flags', [])),
                    (string) ($item['recommended_next_action'] ?? ''),
                ])
                ->all(),
        );

        foreach ($payload['items'] ?? [] as $item) {
            $this->line('');
            $this->line(sprintf('Material: %s', (string) data_get($item, 'material.name', data_get($item, 'material.id', ''))));
            $this->table(
                ['Date', 'Type', 'Qty', 'Signed', 'Running', 'Source', 'Flags'],
                collect($item['movements'] ?? [])
                    ->map(static fn (array $movement): array => [
                        (string) ($movement['date'] ?? ''),
                        (string) ($movement['type'] ?? ''),
                        (string) ($movement['raw_quantity'] ?? ''),
                        (string) ($movement['signed_quantity'] ?? ''),
                        (string) ($movement['running_stock'] ?? ''),
                        trim((string) (($movement['source_type'] ?? '') . ':' . ($movement['source_id'] ?? '')), ':'),
                        implode(',', $movement['suspicion_flags'] ?? []),
                    ])
                    ->all(),
            );
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
