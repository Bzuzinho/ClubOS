<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FiscalDocumentAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class FiscalDocumentAuditCommand extends Command
{
    protected $signature = 'finance:audit-fiscal-documents
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--from= : Data inicial YYYY-MM-DD}
        {--to= : Data final YYYY-MM-DD}
        {--invoice= : Filtra fatura}
        {--payment= : Filtra pagamento}
        {--allocation= : Filtra alocacao}
        {--fiscal-request= : Filtra pedido fiscal}
        {--user= : Filtra utilizador}
        {--include-clean : Inclui cadeias fiscais limpas como info}
        {--include-deleted : Inclui soft-deleted}
        {--only-actionable : Mostra apenas findings acionaveis}
        {--fail-on-critical : Exit code 1 se houver critical}
        {--fail-on-warning : Exit code 1 se houver warning}';

    protected $description = 'Audita pedidos fiscais, recibos e documentos fiscais externos em modo read-only';

    public function __construct(
        private readonly FiscalDocumentAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->audit([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'invoice' => $this->option('invoice'),
            'payment' => $this->option('payment'),
            'allocation' => $this->option('allocation'),
            'fiscal_request' => $this->option('fiscal-request'),
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

        $this->info('Audit fiscal documents');
        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->map(static fn (mixed $value, string $key): array => [$key, is_float($value) ? number_format($value, 2, '.', '') : (string) $value])
                ->values()
                ->all(),
        );

        $this->table(
            ['Severity', 'Code', 'Fiscal Request', 'Invoice', 'Payment', 'Allocation', 'Amount', 'Fiscal Status', 'External Ref', 'Actionable', 'Recommendation'],
            collect($findings)
                ->map(static fn (array $finding): array => [
                    (string) ($finding['severity'] ?? ''),
                    (string) ($finding['code'] ?? ''),
                    (string) ($finding['fiscal_request_id'] ?? ''),
                    (string) ($finding['invoice_id'] ?? ''),
                    (string) ($finding['payment_id'] ?? ''),
                    (string) ($finding['allocation_id'] ?? ''),
                    number_format((float) ($finding['amount'] ?? 0), 2, '.', ''),
                    (string) ($finding['fiscal_status'] ?? ''),
                    (string) (($finding['external_document_number'] ?? null) ?: ($finding['external_document_id'] ?? '')),
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
