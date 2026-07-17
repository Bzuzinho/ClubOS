<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FiscalDocumentRequestProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FiscalDocumentRequestProcessingCommand extends Command
{
    protected $signature = 'finance:process-fiscal-document-requests
        {--fiscal-request=* : Um ou mais fiscal request IDs}
        {--invoice=* : Uma ou mais invoices}
        {--payment=* : Um ou mais payments}
        {--provider= : Provider especifico}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--dry-run : Explicito, mas comportamento default}
        {--apply : Executa processamento real}
        {--confirm-external-issue : Confirma intencao de emitir/chamar provider externo}
        {--export-payload-path= : Guarda payloads prontos para emissao manual}
        {--fail-on-blocked : Exit code 1 se houver bloqueados}';

    protected $description = 'Processamento controlado de pedidos fiscais ready, com dry-run por defeito';

    public function __construct(
        private readonly FiscalDocumentRequestProcessingService $processingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->processingService->process([
            'fiscal_request' => $this->option('fiscal-request'),
            'invoice' => $this->option('invoice'),
            'payment' => $this->option('payment'),
            'provider' => $this->option('provider'),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_external_issue' => (bool) $this->option('confirm-external-issue'),
            'export_payload_path' => $this->option('export-payload-path'),
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

        if ((bool) $this->option('apply') && (int) data_get($payload, 'summary.blocked_count', 0) > 0) {
            return self::FAILURE;
        }

        if ((bool) $this->option('apply') && (int) data_get($payload, 'summary.failed_count', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('Fiscal document request processing');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])
                ->map(static fn (mixed $value, string $key): array => [$key, (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Fiscal Request', 'Invoice', 'Provider', 'Amount', 'Ready', 'Action', 'Processed', 'External Ref', 'Error'],
            collect($payload['items'] ?? [])
                ->map(static fn (array $item): array => [
                    (string) data_get($item, 'fiscal_request_id', ''),
                    (string) data_get($item, 'invoice_id', ''),
                    (string) data_get($item, 'provider', ''),
                    number_format((float) data_get($item, 'amount', 0), 2, '.', ''),
                    (bool) data_get($item, 'ready', false) ? 'yes' : 'no',
                    (string) data_get($item, 'action', ''),
                    (bool) data_get($item, 'processed', false) ? 'yes' : 'no',
                    (string) data_get($item, 'external_document_number', ''),
                    (string) data_get($item, 'error', ''),
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
