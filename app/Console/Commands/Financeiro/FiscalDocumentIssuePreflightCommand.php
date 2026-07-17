<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FiscalDocumentIssuePreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FiscalDocumentIssuePreflightCommand extends Command
{
    protected $signature = 'finance:preflight-fiscal-document-issue
        {--fiscal-request= : Filtra pedido fiscal}
        {--invoice= : Filtra fatura}
        {--payment= : Filtra pagamento}
        {--user= : Filtra utilizador}
        {--provider= : Filtra provider fiscal}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-ready : Mostra apenas pedidos prontos}
        {--fail-on-blocked : Exit code 1 se houver pedidos bloqueados}';

    protected $description = 'Preflight read-only para emissao/processamento de pedidos fiscais externos pendentes';

    public function __construct(
        private readonly FiscalDocumentIssuePreflightService $preflightService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->preflightService->preflight([
            'fiscal_request' => $this->option('fiscal-request'),
            'invoice' => $this->option('invoice'),
            'payment' => $this->option('payment'),
            'user' => $this->option('user'),
            'provider' => $this->option('provider'),
            'only_ready' => (bool) $this->option('only-ready'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('fail-on-blocked') && (int) data_get($payload, 'summary.blocked_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Fiscal document issue preflight');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, is_array($value) ? implode(', ', $value) : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Fiscal Request', 'Invoice', 'User', 'Provider', 'Amount', 'Ready', 'Blocked Reasons', 'Warnings'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) data_get($item, 'fiscal_request.id', ''),
                    (string) data_get($item, 'invoice.id', ''),
                    (string) data_get($item, 'user.user_id', ''),
                    (string) data_get($item, 'fiscal_request.provider', ''),
                    number_format((float) data_get($item, 'fiscal_request.amount', 0), 2, '.', ''),
                    (bool) data_get($item, 'readiness.ready', false) ? 'yes' : 'no',
                    implode(', ', (array) data_get($item, 'readiness.blocked_reasons', [])),
                    implode(', ', (array) data_get($item, 'readiness.warnings', [])),
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
