<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FinancialTimelineAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FinancialTimelineAuditCommand extends Command
{
    protected $signature = 'finance:audit-financial-timeline
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--from= : Data inicial YYYY-MM-DD}
        {--to= : Data final YYYY-MM-DD}
        {--payment= : Filtra pagamento}
        {--allocation= : Filtra payment allocation}
        {--invoice= : Filtra fatura}
        {--bank-transaction= : Filtra movimento bancario}
        {--user= : Filtra utilizador}
        {--include-clean : Inclui cadeias temporais limpas como info}
        {--include-deleted : Inclui soft-deleted/cancelled em detalhe}
        {--only-actionable : Mostra apenas findings acionaveis}
        {--fail-on-critical : Exit code 1 se houver critical}
        {--fail-on-warning : Exit code 1 se houver warning}';

    protected $description = 'Audita a consistencia temporal banco -> pagamento -> alocacao -> fatura -> fiscal em modo read-only';

    public function __construct(
        private readonly FinancialTimelineAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'payment' => $this->option('payment'),
            'allocation' => $this->option('allocation'),
            'invoice' => $this->option('invoice'),
            'bank_transaction' => $this->option('bank-transaction'),
            'user' => $this->option('user'),
            'include_clean' => (bool) $this->option('include-clean'),
            'include_deleted' => (bool) $this->option('include-deleted'),
            'only_actionable' => (bool) $this->option('only-actionable'),
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

        $this->info('Audit financial timeline consistency');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, is_float($value) ? number_format($value, 2, '.', '') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Bank Tx', 'Payment', 'Allocation', 'Invoice', 'Fiscal', 'Date Diff', 'Actionable', 'Recommendation'],
            collect($findings)
                ->map(static fn (array $finding): array => [
                    (string) ($finding['severity'] ?? ''),
                    (string) ($finding['code'] ?? ''),
                    (string) ($finding['bank_transaction_id'] ?? ''),
                    (string) ($finding['payment_id'] ?? ''),
                    (string) ($finding['allocation_id'] ?? ''),
                    (string) ($finding['invoice_id'] ?? ''),
                    (string) ($finding['fiscal_request_id'] ?? ''),
                    (string) data_get($finding, 'context.date_diff_days', ''),
                    ! empty($finding['actionable']) ? 'yes' : 'no',
                    (string) ($finding['recommendation'] ?? ''),
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
