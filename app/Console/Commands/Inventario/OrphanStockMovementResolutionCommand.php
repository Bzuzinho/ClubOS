<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\OrphanStockMovementResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class OrphanStockMovementResolutionCommand extends Command
{
    protected $signature = 'inventory:resolve-orphan-stock-movements
        {--material=* : Um ou mais material/product IDs}
        {--movement=* : Um ou mais stock movement IDs}
        {--strategy= : accept_manual|release_orphan_reservation|physical_adjustment|full_safe_resolution}
        {--target-physical-stock= : Stock fisico alvo para physical_adjustment}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--dry-run : Forca modo dry-run}
        {--apply : Aplica apenas acoes seguras}
        {--confirm-orphan-resolution : Confirmacao explicita para aplicar resolucao de orfaos}
        {--fail-on-unsafe : Exit code 1 se houver acoes inseguras}';

    protected $description = 'Resolve de forma controlada movimentos orfaos de stock sem apagar historico';

    public function __construct(
        private readonly OrphanStockMovementResolutionService $resolutionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->resolutionService->run([
            'material' => $this->option('material'),
            'movement' => $this->option('movement'),
            'strategy' => $this->option('strategy'),
            'target_physical_stock' => $this->option('target-physical-stock'),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_orphan_resolution' => (bool) $this->option('confirm-orphan-resolution'),
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
        $this->info('Inventory orphan stock movement resolution');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Material', 'Strategy', 'Current Physical', 'Current Reserved', 'Current Available', 'Proposed', 'Safe', 'Applied', 'Expected After'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) (($item['material_name'] ?? null) ?: ($item['material_id'] ?? '')),
                    (string) ($item['strategy'] ?? ''),
                    (string) data_get($item, 'current_state.calculated_physical_stock', ''),
                    (string) data_get($item, 'current_state.calculated_reserved_stock', ''),
                    (string) data_get($item, 'current_state.calculated_available_stock', ''),
                    (string) count($item['proposed_actions'] ?? []),
                    (string) collect($item['proposed_actions'] ?? [])->where('safe_to_apply', true)->count(),
                    (bool) ($item['applied'] ?? false) ? 'yes' : 'no',
                    sprintf(
                        'P:%s R:%s A:%s',
                        (string) data_get($item, 'expected_after.calculated_physical_stock', ''),
                        (string) data_get($item, 'expected_after.calculated_reserved_stock', ''),
                        (string) data_get($item, 'expected_after.calculated_available_stock', ''),
                    ),
                ])
                ->all(),
        );

        foreach ($payload['items'] ?? [] as $item) {
            $this->line('');
            $this->line(sprintf('Actions for material: %s', (string) (($item['material_name'] ?? null) ?: ($item['material_id'] ?? ''))));
            $this->table(
                ['Action', 'Qty', 'Safe', 'Applied', 'Reason', 'Movement Created', 'Product Updated'],
                collect($item['proposed_actions'] ?? [])
                    ->map(static fn (array $action): array => [
                        (string) ($action['action_type'] ?? ''),
                        (string) ($action['quantity'] ?? ''),
                        (bool) ($action['safe_to_apply'] ?? false) ? 'yes' : 'no',
                        (bool) ($action['applied'] ?? false) ? 'yes' : 'no',
                        (string) ($action['reason'] ?? ''),
                        (string) ($action['stock_movement_id'] ?? ''),
                        (bool) ($action['product_updated'] ?? false) ? 'yes' : 'no',
                    ])
                    ->all(),
            );
        }

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
