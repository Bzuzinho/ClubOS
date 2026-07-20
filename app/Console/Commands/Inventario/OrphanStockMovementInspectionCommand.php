<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\OrphanStockMovementInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class OrphanStockMovementInspectionCommand extends Command
{
    protected $signature = 'inventory:inspect-orphan-stock-movements
        {--material=* : Um ou mais material/product IDs}
        {--movement=* : Um ou mais stock movement IDs}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-actionable : Mostra apenas movimentos com recomendacao acionavel}
        {--fail-on-actionable : Exit code 1 se houver movimentos acionaveis}';

    protected $description = 'Inspecao assistida read-only de movimentos orfaos de stock';

    public function __construct(
        private readonly OrphanStockMovementInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect([
            'material' => $this->option('material'),
            'movement' => $this->option('movement'),
            'only_actionable' => (bool) $this->option('only-actionable'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-actionable') && (int) data_get($payload, 'summary.actionable_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Inventory orphan stock movement inspection');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Movement', 'Material', 'Type', 'Qty', 'Created At', 'Impact', 'Classification', 'Recommendation'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) data_get($item, 'movement.id', ''),
                    (string) (data_get($item, 'material.name') ?: data_get($item, 'material.id', '')),
                    (string) data_get($item, 'movement.type', ''),
                    (string) data_get($item, 'movement.quantity', ''),
                    (string) data_get($item, 'movement.created_at', ''),
                    sprintf(
                        'P:%s R:%s A:%s',
                        (string) data_get($item, 'impact.physical_delta', 0),
                        (string) data_get($item, 'impact.reserved_delta', 0),
                        (string) data_get($item, 'impact.available_delta', 0),
                    ),
                    (string) ($item['classification'] ?? ''),
                    (string) ($item['recommended_next_action'] ?? ''),
                ])
                ->all(),
        );

        foreach ($payload['items'] ?? [] as $item) {
            $links = collect($item['candidate_links'] ?? []);
            $nearby = collect(data_get($item, 'nearby_context.same_material_movements', []));
            if ($links->isEmpty() && $nearby->isEmpty()) {
                continue;
            }

            $this->line('');
            $this->line(sprintf('Movement detail: %s', (string) data_get($item, 'movement.id', '')));

            if ($nearby->isNotEmpty()) {
                $this->table(
                    ['Nearby Movement', 'Type', 'Qty', 'Created At', 'Source'],
                    $nearby->map(static fn (array $movement): array => [
                        (string) ($movement['id'] ?? ''),
                        (string) ($movement['type'] ?? ''),
                        (string) ($movement['quantity'] ?? ''),
                        (string) ($movement['created_at'] ?? ''),
                        trim((string) (($movement['source_type'] ?? '') . ':' . ($movement['source_id'] ?? '')), ':'),
                    ])->all(),
                );
            }

            if ($links->isNotEmpty()) {
                $this->table(
                    ['Candidate Source', 'Source ID', 'Item ID', 'Qty', 'Matches', 'Created At', 'Reason'],
                    $links->map(static fn (array $link): array => [
                        (string) ($link['source_type'] ?? ''),
                        (string) ($link['source_id'] ?? ''),
                        (string) ($link['source_item_id'] ?? ''),
                        (string) ($link['quantity'] ?? ''),
                        (bool) ($link['quantity_matches'] ?? false) ? 'yes' : 'no',
                        (string) ($link['created_at'] ?? ''),
                        (string) ($link['reason'] ?? ''),
                    ])->all(),
                );
            }
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
