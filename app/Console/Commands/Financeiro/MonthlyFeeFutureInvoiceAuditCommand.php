<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\MonthlyFeeFutureInvoiceAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MonthlyFeeFutureInvoiceAuditCommand extends Command
{
    protected $signature = 'finance:audit-future-monthly-fees
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--from= : Data efetiva para calcular o primeiro mes futuro}
        {--user= : Audita apenas um membro}
        {--only-reconcilable : Omite findings de faturas protegidas}
        {--fail-on-critical : Falha com codigo 1 quando existem findings criticos}
        {--fail-on-warning : Falha com codigo 1 quando existem warnings}';

    protected $description = 'Audita mensalidades futuras existentes em modo estritamente read-only';

    public function __construct(
        private readonly MonthlyFeeFutureInvoiceAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'user' => $this->option('user'),
            'only_reconcilable' => (bool) $this->option('only-reconcilable'),
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

        $this->info('Audit future monthly fee invoices');
        $this->table(
            ['Metric', 'Value'],
            [
                ['effective_date', (string) ($payload['effective_date'] ?? '')],
                ['cutoff_month', (string) ($payload['cutoff_month'] ?? '')],
                ['window', sprintf('%s..%s', (string) ($payload['window']['start'] ?? ''), (string) ($payload['window']['end'] ?? ''))],
                ['total_future_monthly_invoices', (int) ($summary['total_future_monthly_invoices'] ?? 0)],
                ['total_reconcilable', (int) ($summary['total_reconcilable'] ?? 0)],
                ['total_protected', (int) ($summary['total_protected'] ?? 0)],
                ['total_findings', (int) ($summary['total_findings'] ?? 0)],
                ['critical_count', (int) ($summary['critical_count'] ?? 0)],
                ['warning_count', (int) ($summary['warning_count'] ?? 0)],
                ['info_count', (int) ($summary['info_count'] ?? 0)],
            ]
        );

        $rows = collect($findings)
            ->map(static fn (array $finding): array => [
                (string) ($finding['severity'] ?? ''),
                (string) ($finding['code'] ?? ''),
                (string) ($finding['invoice_id'] ?? ''),
                (string) ($finding['user_id'] ?? ''),
                (string) ($finding['mes'] ?? ''),
                ((bool) ($finding['reconcilable'] ?? false)) ? 'yes' : 'no',
                (string) ($finding['recommendation'] ?? ''),
            ])
            ->all();

        $this->table(['Severity', 'Code', 'Invoice', 'User', 'Mes', 'Reconcilable', 'Recommendation'], $rows);
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
