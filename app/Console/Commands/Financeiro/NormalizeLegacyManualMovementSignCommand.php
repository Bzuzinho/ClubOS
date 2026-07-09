<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\NormalizeLegacyManualMovementSignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class NormalizeLegacyManualMovementSignCommand extends Command
{
    protected $signature = 'finance:normalize-legacy-manual-movement-sign
        {movement_id : UUID do movement alvo}
        {--dry-run : Simula sem escrita}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}';

    protected $description = 'Normaliza sinal monetario de um movement manual legacy especifico, com guardas estritas';

    public function __construct(
        private readonly NormalizeLegacyManualMovementSignService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $movementId = (string) $this->argument('movement_id');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $payload = $this->service->normalize($movementId, $dryRun);
        } catch (Throwable $exception) {
            $payload = [
                'movement_id' => $movementId,
                'dry_run' => $dryRun,
                'guards_passed' => false,
                'error' => $exception->getMessage(),
            ];

            $this->writeReportIfRequested($payload);

            if ((bool) $this->option('json')) {
                $this->line($this->toJson($payload));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHuman($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->info('Normalize legacy manual movement sign');
        $this->table(
            ['Metric', 'Value'],
            [
                ['movement_id', (string) ($payload['movement_id'] ?? '')],
                ['dry_run', (bool) ($payload['dry_run'] ?? false) ? 'true' : 'false'],
                ['guards_passed', (bool) ($payload['guards_passed'] ?? false) ? 'true' : 'false'],
                ['changed_fields_count', $this->changedFieldCount($payload)],
                ['reporting_revenue_delta', (float) data_get($payload, 'financial_impact.reporting_revenue_delta', 0)],
                ['reporting_expense_delta', (float) data_get($payload, 'financial_impact.reporting_expense_delta', 0)],
                ['reporting_balance_delta', (float) data_get($payload, 'financial_impact.reporting_balance_delta', 0)],
                ['current_account_delta', (float) data_get($payload, 'financial_impact.current_account_delta', 0)],
            ]
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

        $reportPath = str_starts_with($reportPathOption, '/')
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
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function changedFieldCount(array $payload): int
    {
        $movementCount = count((array) data_get($payload, 'changed_fields.movement', []));
        $entryCount = count((array) data_get($payload, 'changed_fields.entry', []));
        $itemsCount = collect((array) data_get($payload, 'changed_fields.items', []))
            ->map(static fn (array $fields): int => count($fields))
            ->sum();

        return $movementCount + $entryCount + $itemsCount;
    }
}
