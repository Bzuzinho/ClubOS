<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\UnallocatedPaymentAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class UnallocatedPaymentAuditCommand extends Command
{
    protected $signature = 'finance:audit-unallocated-payments
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--from= : Data inicial YYYY-MM-DD}
        {--to= : Data final YYYY-MM-DD}
        {--payment= : Filtra por payment id}
        {--user= : Filtra por user id}
        {--only-actionable : Mostra apenas pagamentos que podem exigir acao}
        {--include-cancelled : Inclui pagamentos cancelados no detalhe}
        {--fail-on-actionable : Falha com codigo 1 se houver actionable_count}
        {--fail-on-warning : Falha com codigo 1 se houver warning}';

    protected $description = 'Audita pagamentos com saldo nao alocado em modo read-only';

    public function __construct(
        private readonly UnallocatedPaymentAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'payment' => $this->option('payment'),
            'user' => $this->option('user'),
            'only_actionable' => (bool) $this->option('only-actionable'),
            'include_cancelled' => (bool) $this->option('include-cancelled'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        if ((bool) $this->option('fail-on-actionable') && (int) ($summary['actionable_count'] ?? 0) > 0) {
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
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $this->info('Audit unallocated payments');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, is_float($value) ? number_format($value, 2, '.', '') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Classification', 'Payment', 'User', 'Family', 'Amount', 'Unallocated', 'Open Invoices', 'Actionable', 'Recommendation'],
            collect($items)
                ->map(static fn (array $item): array => [
                    (string) ($item['severity'] ?? ''),
                    (string) ($item['classification'] ?? ''),
                    (string) ($item['payment_id'] ?? ''),
                    (string) ($item['user_id'] ?? ''),
                    (string) ($item['family_id'] ?? ''),
                    number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($item['unallocated_amount'] ?? 0), 2, '.', ''),
                    number_format((float) ($item['open_invoice_amount_for_owner'] ?? 0), 2, '.', ''),
                    (bool) ($item['actionable'] ?? false) ? 'yes' : 'no',
                    (string) ($item['recommendation'] ?? ''),
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
