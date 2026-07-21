<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventario;

use App\Services\Inventario\EquipmentLoanStockResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class EquipmentLoanStockResolutionCommand extends Command
{
    protected $signature = 'inventory:resolve-equipment-loan-stock
        {--loan= : Filtrar por emprestimo}
        {--movement= : Filtrar por movimento de stock}
        {--strategy= : create_missing_return|accept_legacy_exit|full_safe_resolution}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--dry-run : Forca modo dry-run}
        {--apply : Aplica apenas acoes seguras}
        {--confirm-equipment-loan-resolution : Confirmacao explicita para aplicar resolucao de emprestimo}
        {--fail-on-unsafe : Exit code 1 se houver acoes inseguras}';

    protected $description = 'Resolve de forma controlada saidas de emprestimos de material sem registo ativo';

    public function __construct(
        private readonly EquipmentLoanStockResolutionService $resolutionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->resolutionService->run([
            'loan' => $this->option('loan'),
            'movement' => $this->option('movement'),
            'strategy' => $this->option('strategy'),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_equipment_loan_resolution' => (bool) $this->option('confirm-equipment-loan-resolution'),
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
        $this->info('Equipment loan stock resolution');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Loan', 'Movement', 'Material', 'Strategy', 'Proposed', 'Safe', 'Applied', 'Expected After', 'Recommendation'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) ($item['loan_id'] ?? ''),
                    (string) ($item['movement_id'] ?? ''),
                    (string) (data_get($item, 'material.nome') ?: data_get($item, 'material.id', '')),
                    (string) ($item['strategy'] ?? ''),
                    (string) count($item['proposed_actions'] ?? []),
                    (string) collect($item['proposed_actions'] ?? [])->where('safe_to_apply', true)->count(),
                    (bool) ($item['applied'] ?? false) ? 'yes' : 'no',
                    sprintf(
                        'P:%s R:%s A:%s',
                        (string) data_get($item, 'expected_after.ledger_physical_stock', ''),
                        (string) data_get($item, 'expected_after.ledger_reserved_stock', ''),
                        (string) data_get($item, 'expected_after.ledger_available_stock', ''),
                    ),
                    (string) data_get($item, 'proposed_actions.0.reason', ''),
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
