<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\ExternalFiscalReceiptRecordingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ExternalFiscalReceiptRecordingCommand extends Command
{
    protected $signature = 'finance:record-external-fiscal-receipt
        {fiscal_request : ID do fiscal_document_request}
        {--receipt-number= : Numero do recibo/documento fiscal externo}
        {--issued-at= : Data/hora de emissao YYYY-MM-DD ou ISO datetime}
        {--external-document-id= : ID externo opcional}
        {--provider= : Provider, default do fiscal_request}
        {--receipt-pdf-path= : Caminho opcional para PDF ja carregado}
        {--notes= : Observacao opcional}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--dry-run : Nao altera dados}
        {--apply : Aplica alteracoes}
        {--confirm-manual-receipt : Confirma que o recibo foi emitido manualmente fora do ClubOS}';

    protected $description = 'Regista manualmente no ClubOS um recibo fiscal ja emitido externamente';

    public function __construct(
        private readonly ExternalFiscalReceiptRecordingService $recordingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->recordingService->record((string) $this->argument('fiscal_request'), [
            'receipt_number' => $this->option('receipt-number'),
            'issued_at' => $this->option('issued-at'),
            'external_document_id' => $this->option('external-document-id'),
            'provider' => $this->option('provider'),
            'receipt_pdf_path' => $this->option('receipt-pdf-path'),
            'notes' => $this->option('notes'),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'confirm_manual_receipt' => (bool) $this->option('confirm-manual-receipt'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        if ((bool) $this->option('apply') && ! (bool) ($payload['applied'] ?? false) && ! (bool) ($payload['skipped'] ?? false)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $this->info('External fiscal receipt recording');
        $this->table(
            ['Metric', 'Value'],
            [
                ['dry_run', (bool) $payload['dry_run'] ? 'yes' : 'no'],
                ['apply', (bool) $payload['apply'] ? 'yes' : 'no'],
                ['ready_to_record', (bool) $payload['ready_to_record'] ? 'yes' : 'no'],
                ['applied', (bool) $payload['applied'] ? 'yes' : 'no'],
                ['skipped', (bool) $payload['skipped'] ? 'yes' : 'no'],
                ['action', (string) $payload['action']],
            ],
        );

        $this->table(
            ['Fiscal Request', 'Invoice', 'Provider', 'Receipt Number', 'Issued At', 'Ready', 'Applied', 'Blocked Reasons'],
            [[
                (string) $payload['fiscal_request_id'],
                (string) ($payload['invoice_id'] ?? ''),
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['receipt_number'] ?? ''),
                (string) ($payload['issued_at'] ?? ''),
                (bool) $payload['ready_to_record'] ? 'yes' : 'no',
                (bool) $payload['applied'] ? 'yes' : 'no',
                implode(', ', (array) ($payload['blocked_reasons'] ?? [])),
            ]],
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
