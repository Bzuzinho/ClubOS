<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\PendingFiscalRequestInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class PendingFiscalRequestInspectionCommand extends Command
{
    protected $signature = 'finance:inspect-pending-fiscal-requests
        {--fiscal-request= : Filtra pedido fiscal}
        {--invoice= : Filtra fatura}
        {--payment= : Filtra pagamento}
        {--user= : Filtra utilizador}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-actionable : Mostra apenas decisoes acionaveis}
        {--fail-on-actionable : Exit code 1 se houver decisoes acionaveis}';

    protected $description = 'Inspeciona pedidos fiscais pending sem documento externo em modo read-only';

    public function __construct(
        private readonly PendingFiscalRequestInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect([
            'fiscal_request' => $this->option('fiscal-request'),
            'invoice' => $this->option('invoice'),
            'payment' => $this->option('payment'),
            'user' => $this->option('user'),
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
        $this->info('Pending fiscal request inspection');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Fiscal Request', 'Invoice', 'User', 'Amount', 'Age Days', 'Risk', 'Decision', 'Recommendation'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) data_get($item, 'fiscal_request.id', ''),
                    (string) data_get($item, 'invoice.id', ''),
                    (string) data_get($item, 'fiscal_request.user_id', ''),
                    number_format((float) data_get($item, 'fiscal_request.amount', 0), 2, '.', ''),
                    (string) data_get($item, 'timeline.age_days', ''),
                    (string) data_get($item, 'decision.risk_level', ''),
                    (string) data_get($item, 'decision.recommended_next_action', ''),
                    (string) data_get($item, 'decision.recommended_next_action', ''),
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
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
