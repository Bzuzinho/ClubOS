<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\EquipmentLoanStockInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class EquipmentLoanStockInspectionCommand extends Command
{
    protected $signature = 'inventory:inspect-equipment-loan-stock
        {--loan= : Filtrar por emprestimo}
        {--movement= : Filtrar por movimento de stock}
        {--material= : Filtrar por material/produto}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-actionable : Mostra apenas itens acionaveis}';

    protected $description = 'Inspecao read-only de casos cirurgicos de emprestimos de material e stock';

    public function __construct(
        private readonly EquipmentLoanStockInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect([
            'loan' => $this->option('loan'),
            'movement' => $this->option('movement'),
            'material' => $this->option('material'),
            'only_actionable' => (bool) $this->option('only-actionable'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Equipment loan stock inspection');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        foreach ($payload['items'] ?? [] as $item) {
            $this->line('');
            $this->line('Loan: '.(string) ($item['loan_id'] ?? ''));
            $this->table(['Field', 'Value'], [
                ['Loan found?', ((bool) ($item['loan_record_found'] ?? false)) ? 'yes' : 'no'],
                ['Primary movement', (string) data_get($item, 'primary_movement.id', '')],
                ['Material', (string) (data_get($item, 'material.nome') ?: data_get($item, 'material.id', ''))],
                ['Related movements', (string) count($item['related_movements'] ?? [])],
                ['Nearby movements', (string) count($item['nearby_context'] ?? [])],
                ['Net equipment loan', (string) data_get($item, 'impact_by_source.equipment_loan.physical_net', '')],
                ['Net period total', (string) data_get($item, 'impact_by_source.total_material_period.physical_net', '')],
                ['Global stock ok?', ((bool) data_get($item, 'global_stock_state.matches_ledger', false)) ? 'yes' : 'no'],
                ['Classification', (string) ($item['classification'] ?? '')],
                ['Recommendation', (string) ($item['recommendation'] ?? '')],
                ['Actionable', ((bool) ($item['actionable'] ?? false)) ? 'yes' : 'no'],
            ]);
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
