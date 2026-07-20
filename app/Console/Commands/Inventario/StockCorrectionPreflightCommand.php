<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\StockCorrectionPreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StockCorrectionPreflightCommand extends Command
{
    protected $signature = 'inventory:preflight-stock-corrections
        {--material=* : Um ou mais material/product IDs}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-safe : Mostra apenas acoes seguras propostas}
        {--fail-on-unsafe : Exit code 1 se houver acoes inseguras ou com revisao manual}';

    protected $description = 'Preflight read-only de correcoes controladas de stock fisico';

    public function __construct(
        private readonly StockCorrectionPreflightService $preflightService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->preflightService->preflight([
            'material' => $this->option('material'),
            'only_safe' => (bool) $this->option('only-safe'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
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
        $this->info('Inventory stock correction preflight');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Material', 'Stored', 'Physical', 'Reserved', 'Available', 'Diff', 'Safe Actions', 'Manual Review', 'Recommendation'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) (($item['material_name'] ?? null) ?: ($item['material_id'] ?? '')),
                    (string) ($item['stored_stock'] ?? ''),
                    (string) ($item['calculated_physical_stock'] ?? ''),
                    (string) ($item['calculated_reserved_stock'] ?? ''),
                    (string) ($item['calculated_available_stock'] ?? ''),
                    (string) ($item['physical_difference'] ?? ''),
                    (string) collect($item['proposed_actions'] ?? [])->filter(fn (array $action): bool => (bool) $action['safe_to_apply'])->count(),
                    (string) collect($item['proposed_actions'] ?? [])->filter(fn (array $action): bool => (bool) $action['requires_manual_review'])->count(),
                    (string) ($item['final_recommendation'] ?? ''),
                ])
                ->all(),
        );

        $this->table(
            ['Material', 'Action', 'Safe', 'Reason', 'Expected After'],
            collect($payload['items'] ?? [])
                ->flatMap(static fn (array $item): array => collect($item['proposed_actions'] ?? [])
                    ->map(static fn (array $action): array => [
                        (string) (($item['material_name'] ?? null) ?: ($item['material_id'] ?? '')),
                        (string) ($action['action_type'] ?? ''),
                        (bool) ($action['safe_to_apply'] ?? false) ? 'yes' : 'no',
                        (string) ($action['reason'] ?? ''),
                        (string) data_get($action, 'expected_after.expected_physical_after', ''),
                    ])
                    ->all())
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
