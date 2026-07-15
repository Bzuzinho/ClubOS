<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\PaymentAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class PaymentAuditCommand extends Command
{
    protected $signature = 'finance:audit-payments
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--from= : Data inicial YYYY-MM-DD}
        {--to= : Data final YYYY-MM-DD}
        {--payment= : Filtra por payment id}
        {--invoice= : Filtra por invoice id}
        {--user= : Filtra por user id}
        {--include-deleted : Inclui soft-deleted em detalhe}
        {--only-open : Mostra apenas pagamentos com saldo nao alocado}
        {--fail-on-critical : Falha com codigo 1 se houver critical}
        {--fail-on-warning : Falha com codigo 1 se houver warning}';

    protected $description = 'Audita pagamentos, alocacoes, creditos e rastos bancarios em modo read-only';

    public function __construct(
        private readonly PaymentAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'payment' => $this->option('payment'),
            'invoice' => $this->option('invoice'),
            'user' => $this->option('user'),
            'include_deleted' => (bool) $this->option('include-deleted'),
            'only_open' => (bool) $this->option('only-open'),
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

        $this->info('Audit payments and allocations');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, is_float($value) ? number_format($value, 2, '.', '') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Payment', 'Allocation', 'Invoice', 'User', 'Amount', 'Status', 'Recommendation'],
            collect($findings)
                ->map(static fn (array $finding): array => [
                    (string) ($finding['severity'] ?? ''),
                    (string) ($finding['code'] ?? ''),
                    (string) ($finding['payment_id'] ?? ''),
                    (string) ($finding['allocation_id'] ?? ''),
                    (string) ($finding['invoice_id'] ?? ''),
                    (string) ($finding['user_id'] ?? ''),
                    number_format((float) ($finding['amount'] ?? 0), 2, '.', ''),
                    (string) ($finding['status'] ?? ''),
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
