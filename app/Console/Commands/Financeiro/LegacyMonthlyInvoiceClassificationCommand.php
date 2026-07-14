<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\LegacyMonthlyInvoiceClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class LegacyMonthlyInvoiceClassificationCommand extends Command
{
    protected $signature = 'finance:classify-legacy-monthly-invoices
        {--dry-run : Mostra o que seria feito sem alterar dados}
        {--apply : Aplica apenas alteracoes seguras}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--invoice= : Processa apenas uma fatura}
        {--user= : Processa apenas um utilizador}
        {--from-month= : Mes inicial YYYY-MM}
        {--to-month= : Mes final YYYY-MM}
        {--include-protected : Inclui protegidas no relatorio, mas nunca altera}
        {--fail-on-unsafe : Falha com codigo 1 quando existirem casos inseguros}';

    protected $description = 'Classifica mensalidades legacy seguras em modo controlado';

    public function __construct(
        private readonly LegacyMonthlyInvoiceClassificationService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->service->classify([
            'apply' => (bool) $this->option('apply'),
            'invoice' => $this->option('invoice'),
            'user' => $this->option('user'),
            'from_month' => $this->option('from-month'),
            'to_month' => $this->option('to-month'),
            'include_protected' => (bool) $this->option('include-protected'),
        ]);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        $unsafeCount = (int) ($payload['summary']['unsafe_needs_manual_review'] ?? 0);
        if ((bool) $this->option('fail-on-unsafe') && $unsafeCount > 0) {
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

        $this->info('Legacy monthly invoice classification');
        $this->table(
            ['Metric', 'Value'],
            [
                ['mode', (string) ($payload['mode'] ?? '')],
                ['total_candidates', (int) ($summary['total_candidates'] ?? 0)],
                ['safe_to_classify', (int) ($summary['safe_to_classify'] ?? 0)],
                ['protected_legacy_monthly', (int) ($summary['protected_legacy_monthly'] ?? 0)],
                ['unsafe_needs_manual_review', (int) ($summary['unsafe_needs_manual_review'] ?? 0)],
                ['already_classified', (int) ($summary['already_classified'] ?? 0)],
                ['applied_count', (int) ($summary['applied_count'] ?? 0)],
                ['skipped_count', (int) ($summary['skipped_count'] ?? 0)],
                ['warnings_count', (int) ($summary['warnings_count'] ?? 0)],
            ],
        );

        $rows = collect($items)
            ->map(static fn (array $item): array => [
                (string) ($item['classification'] ?? ''),
                (string) ($item['invoice_id'] ?? ''),
                (string) ($item['user_id'] ?? ''),
                (string) ($item['mes'] ?? ''),
                (string) ($item['estado_pagamento'] ?? ''),
                number_format((float) ($item['valor_total'] ?? 0), 2, '.', ''),
                ((bool) ($item['applied'] ?? false)) ? 'yes' : 'no',
                (string) ($item['recommendation'] ?? ''),
            ])
            ->all();

        $this->table(['Classification', 'Invoice', 'User', 'Mes', 'Estado', 'Valor', 'Applied', 'Recommendation'], $rows);
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
