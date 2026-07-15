<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\AccountCreditAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AccountCreditAuditCommand extends Command
{
    protected $signature = 'finance:audit-account-credits
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--from= : Data inicial YYYY-MM-DD}
        {--to= : Data final YYYY-MM-DD}
        {--credit= : Filtra por AccountCredit id}
        {--user= : Filtra por user id}
        {--invoice= : Filtra por invoice id se existir ligacao}
        {--payment= : Filtra por payment id se existir ligacao}
        {--include-deleted : Inclui soft-deleted se o modelo suportar}
        {--only-open : Apenas creditos com saldo disponivel}
        {--fail-on-critical : Exit code 1 se houver critical}
        {--fail-on-warning : Exit code 1 se houver warning}';

    protected $description = 'Audita creditos em conta AccountCredit em modo read-only';

    public function __construct(
        private readonly AccountCreditAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'credit' => $this->option('credit'),
            'user' => $this->option('user'),
            'invoice' => $this->option('invoice'),
            'payment' => $this->option('payment'),
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

        $this->info('Audit account credits');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, is_float($value) ? number_format($value, 2, '.', '') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Credit', 'User', 'Invoice', 'Payment', 'Amount', 'Balance', 'Status', 'Recommendation'],
            collect($findings)
                ->map(static fn (array $finding): array => [
                    (string) ($finding['severity'] ?? ''),
                    (string) ($finding['code'] ?? ''),
                    (string) ($finding['account_credit_id'] ?? ''),
                    (string) ($finding['user_id'] ?? ''),
                    (string) ($finding['invoice_id'] ?? ''),
                    (string) ($finding['payment_id'] ?? ''),
                    number_format((float) ($finding['amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($finding['available_balance'] ?? 0), 2, '.', ''),
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
