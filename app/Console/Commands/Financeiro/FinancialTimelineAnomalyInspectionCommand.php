<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FinancialTimelineAnomalyInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FinancialTimelineAnomalyInspectionCommand extends Command
{
    protected $signature = 'finance:inspect-financial-timeline-anomaly
        {--payment= : ID do pagamento}
        {--allocation= : ID da payment allocation}
        {--financial-entry= : ID do lancamento financeiro}
        {--bank-transaction= : ID do movimento bancario}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--fail-on-actionable : Falha com codigo 1 se o caso exigir revisao ou correcao controlada}';

    protected $description = 'Inspeciona uma anomalia temporal financeira especifica em modo estritamente read-only';

    public function __construct(
        private readonly FinancialTimelineAnomalyInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect([
            'payment' => $this->option('payment'),
            'allocation' => $this->option('allocation'),
            'financial_entry' => $this->option('financial-entry'),
            'bank_transaction' => $this->option('bank-transaction'),
        ]);

        if ($payload === null) {
            $this->error('No financial timeline entities found for the provided filters.');

            return self::FAILURE;
        }

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-actionable') && in_array((string) $payload['recommended_next_action'], [
            'keep_warning_pending_manual_review',
            'create_targeted_fix_only_if_operationally_confirmed',
        ], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Financial timeline anomaly inspection');
        $this->table(
            ['Field', 'Value'],
            [
                ['risk_level', (string) ($payload['risk_level'] ?? '')],
                ['can_auto_classify_as_info', ! empty($payload['can_auto_classify_as_info']) ? 'yes' : 'no'],
                ['recommended_next_action', (string) ($payload['recommended_next_action'] ?? '')],
                ['read_only', ! empty($payload['read_only']) ? 'yes' : 'no'],
            ],
        );

        $this->table(
            ['Entity', 'ID', 'Date', 'Amount', 'Status'],
            [
                [
                    'bank_transaction',
                    (string) data_get($payload, 'bank_transaction_snapshot.id', ''),
                    (string) data_get($payload, 'bank_transaction_snapshot.data_movimento', ''),
                    number_format((float) data_get($payload, 'bank_transaction_snapshot.amount', 0), 2, '.', ''),
                    (string) data_get($payload, 'bank_transaction_snapshot.status', ''),
                ],
                [
                    'payment',
                    (string) data_get($payload, 'payment_snapshot.id', ''),
                    (string) data_get($payload, 'payment_snapshot.payment_date', ''),
                    number_format((float) data_get($payload, 'payment_snapshot.amount', 0), 2, '.', ''),
                    (string) data_get($payload, 'payment_snapshot.status', ''),
                ],
                [
                    'allocation',
                    (string) data_get($payload, 'payment_allocation_snapshot.id', ''),
                    (string) data_get($payload, 'payment_allocation_snapshot.allocated_at', ''),
                    number_format((float) data_get($payload, 'payment_allocation_snapshot.amount', 0), 2, '.', ''),
                    (string) data_get($payload, 'payment_allocation_snapshot.status', ''),
                ],
                [
                    'financial_entry',
                    (string) data_get($payload, 'financial_entry_snapshot.id', ''),
                    (string) data_get($payload, 'financial_entry_snapshot.data', ''),
                    number_format((float) data_get($payload, 'financial_entry_snapshot.amount', 0), 2, '.', ''),
                    (string) data_get($payload, 'financial_entry_snapshot.type', ''),
                ],
            ],
        );

        $this->table(
            ['Anomaly'],
            collect($payload['anomalies'] ?? [])->map(static fn (string $anomaly): array => [$anomaly])->all(),
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
