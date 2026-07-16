<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FiscalRequestAnomalyInspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FiscalRequestAnomalyInspectionCommand extends Command
{
    protected $signature = 'finance:inspect-fiscal-request-anomaly
        {invoice : ID da fatura a inspecionar}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--fail-on-actionable : Falha com codigo 1 se o caso exigir acao manual/controlada}';

    protected $description = 'Inspeciona anomalias fiscais de faturas em modo estritamente read-only';

    public function __construct(
        private readonly FiscalRequestAnomalyInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->inspectionService->inspect((string) $this->argument('invoice'));

        if ($payload === null) {
            $this->error(sprintf('Invoice not found: %s', (string) $this->argument('invoice')));

            return self::FAILURE;
        }

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-actionable') && in_array((string) $payload['risk_level'], ['medium', 'high'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $invoice = is_array($payload['invoice_snapshot'] ?? null) ? $payload['invoice_snapshot'] : [];
        $fiscalRequests = is_array($payload['fiscal_request_snapshot'] ?? null) ? $payload['fiscal_request_snapshot'] : [];
        $payments = is_array($payload['payment_snapshot'] ?? null) ? $payload['payment_snapshot'] : [];
        $allocations = is_array($payload['payment_allocation_snapshot'] ?? null) ? $payload['payment_allocation_snapshot'] : [];
        $anomalies = is_array($payload['detected_anomalies'] ?? null) ? $payload['detected_anomalies'] : [];
        $reversalContext = is_array($payload['reversal_context'] ?? null) ? $payload['reversal_context'] : [];

        $this->info('Fiscal request anomaly inspection');
        $this->table(
            ['Field', 'Value'],
            [
                ['invoice_id', (string) ($payload['invoice_id'] ?? '')],
                ['user_id', (string) ($invoice['user_id'] ?? '')],
                ['tipo', (string) ($invoice['tipo'] ?? '')],
                ['mes', (string) ($invoice['mes'] ?? '')],
                ['estado_pagamento', (string) ($invoice['estado_pagamento'] ?? '')],
                ['valor_total', number_format((float) ($invoice['valor_total'] ?? 0), 2, '.', '')],
                ['valor_pago', number_format((float) ($invoice['valor_pago'] ?? 0), 2, '.', '')],
                ['valor_em_aberto', number_format((float) ($invoice['valor_em_aberto'] ?? 0), 2, '.', '')],
                ['risk_level', (string) ($payload['risk_level'] ?? '')],
                ['can_auto_fix', ((bool) ($payload['can_auto_fix'] ?? false)) ? 'yes' : 'no'],
                ['can_archive_stale_request', ((bool) ($payload['can_archive_stale_request'] ?? false)) ? 'yes' : 'no'],
                ['future_action_candidate', (string) ($payload['future_action_candidate'] ?? '')],
            ],
        );

        $this->table(
            ['Reversal Field', 'Value'],
            collect($reversalContext)
                ->map(static fn (mixed $value, string $key): array => [
                    $key,
                    is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
                ])
                ->values()
                ->all(),
        );

        $this->table(
            ['ID', 'Status', 'Deleted', 'External Number', 'External ID', 'Amount'],
            collect($fiscalRequests)->map(static fn (array $request): array => [
                (string) ($request['id'] ?? ''),
                (string) ($request['status'] ?? ''),
                (string) ($request['deleted_at'] ?? ''),
                (string) ($request['external_document_number'] ?? ''),
                (string) ($request['external_document_id'] ?? ''),
                number_format((float) ($request['amount'] ?? 0), 2, '.', ''),
            ])->all(),
        );

        $this->table(
            ['Type', 'ID', 'Status', 'Amount', 'Deleted'],
            collect($payments)->map(static fn (array $payment): array => [
                'payment',
                (string) ($payment['id'] ?? ''),
                (string) ($payment['status'] ?? ''),
                number_format((float) ($payment['amount'] ?? 0), 2, '.', ''),
                (string) ($payment['deleted_at'] ?? ''),
            ])->merge(collect($allocations)->map(static fn (array $allocation): array => [
                'allocation',
                (string) ($allocation['id'] ?? ''),
                (string) ($allocation['status'] ?? ''),
                number_format((float) ($allocation['amount'] ?? 0), 2, '.', ''),
                (string) ($allocation['deleted_at'] ?? ''),
            ]))->all(),
        );

        $this->table(
            ['Anomaly'],
            collect($anomalies)->map(static fn (string $anomaly): array => [$anomaly])->all(),
        );

        $this->line(sprintf('recommended_next_action: %s', (string) ($payload['recommended_next_action'] ?? '')));
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
