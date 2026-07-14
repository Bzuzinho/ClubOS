<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\InvoiceObligationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class InvoiceObligationAuditCommand extends Command
{
    protected $signature = 'finance:audit-invoices
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--from= : Data minima de emissao/vencimento a auditar}
        {--to= : Data maxima de emissao/vencimento a auditar}
        {--invoice= : Audita apenas uma fatura}
        {--user= : Audita apenas um utilizador}
        {--type= : Audita apenas um tipo de fatura}
        {--only-open : Audita apenas faturas com valor em aberto/estado pendente/vencido/parcial}
        {--include-cancelled : Inclui faturas canceladas}
        {--fail-on-critical : Falha com codigo 1 quando existem findings criticos}
        {--fail-on-warning : Falha com codigo 1 quando existem warnings}';

    protected $description = 'Audita faturas e obrigacoes financeiras existentes em modo estritamente read-only';

    public function __construct(
        private readonly InvoiceObligationAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'invoice' => $this->option('invoice'),
            'user' => $this->option('user'),
            'type' => $this->option('type'),
            'only_open' => (bool) $this->option('only-open'),
            'include_cancelled' => (bool) $this->option('include-cancelled'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        if ((bool) $this->option('fail-on-critical') && (int) ($summary['critical_count'] ?? 0) > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-warning') && (int) ($summary['warning_count'] ?? 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $findings = is_array($payload['findings'] ?? null) ? $payload['findings'] : [];

        $this->info('Audit invoices and financial obligations');
        $this->table(
            ['Metric', 'Value'],
            [
                ['total_invoices_scanned', (int) ($summary['total_invoices_scanned'] ?? 0)],
                ['total_active_invoices', (int) ($summary['total_active_invoices'] ?? 0)],
                ['total_cancelled_invoices', (int) ($summary['total_cancelled_invoices'] ?? 0)],
                ['total_findings', (int) ($summary['total_findings'] ?? 0)],
                ['critical_count', (int) ($summary['critical_count'] ?? 0)],
                ['warning_count', (int) ($summary['warning_count'] ?? 0)],
                ['info_count', (int) ($summary['info_count'] ?? 0)],
                ['invoices_with_findings', (int) ($summary['invoices_with_findings'] ?? 0)],
                ['protected_invoices_with_findings', (int) ($summary['protected_invoices_with_findings'] ?? 0)],
                ['unprotected_invoices_with_findings', (int) ($summary['unprotected_invoices_with_findings'] ?? 0)],
                ['total_amount_scanned', number_format((float) ($summary['total_amount_scanned'] ?? 0), 2, '.', '')],
                ['total_open_amount_scanned', number_format((float) ($summary['total_open_amount_scanned'] ?? 0), 2, '.', '')],
                ['total_item_sum_scanned', number_format((float) ($summary['total_item_sum_scanned'] ?? 0), 2, '.', '')],
            ],
        );

        $rows = collect($findings)
            ->map(static fn (array $finding): array => [
                (string) ($finding['severity'] ?? ''),
                (string) ($finding['code'] ?? ''),
                (string) ($finding['invoice_id'] ?? ''),
                (string) ($finding['user_id'] ?? ''),
                (string) ($finding['tipo'] ?? ''),
                (string) ($finding['mes'] ?? ''),
                (string) ($finding['estado_pagamento'] ?? ''),
                (string) ($finding['recommendation'] ?? ''),
            ])
            ->all();

        $this->table(['Severity', 'Code', 'Invoice', 'User', 'Tipo', 'Mes', 'Estado', 'Recommendation'], $rows);
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
