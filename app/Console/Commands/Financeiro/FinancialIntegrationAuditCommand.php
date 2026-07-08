<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\FinancialIntegrationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

final class FinancialIntegrationAuditCommand extends Command
{
    protected $signature = 'finance:audit-integrations
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar o payload JSON}
        {--module= : Audita apenas um modulo especifico}
        {--fail-on-critical : Falha com codigo 1 quando existem findings criticos}
        {--fail-on-warning : Falha com codigo 1 quando existem warnings}';

    protected $description = 'Audita integracoes financeiras transversais em modo estritamente read-only';

    public function __construct(
        private readonly FinancialIntegrationAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = $this->normalizeModuleOption();
        $payload = $this->auditService->audit(['module' => $module]);

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

    private function normalizeModuleOption(): ?string
    {
        $module = is_string($this->option('module')) ? trim((string) $this->option('module')) : '';

        if ($module === '' || $module === 'all') {
            return 'all';
        }

        if (!in_array($module, $this->auditService->supportedModules(), true)) {
            throw ValidationException::withMessages([
                'module' => sprintf('Modulo nao suportado [%s]. Suportados: %s', $module, implode(', ', $this->auditService->supportedModules())),
            ]);
        }

        return $module;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $modules = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];

        $this->info('Audit cross-module financial integrations');
        $this->table(
            ['Metric', 'Value'],
            [
                ['total_findings', (int) ($summary['total_findings'] ?? 0)],
                ['critical_count', (int) ($summary['critical_count'] ?? 0)],
                ['warning_count', (int) ($summary['warning_count'] ?? 0)],
                ['info_count', (int) ($summary['info_count'] ?? 0)],
            ]
        );

        $rows = collect($modules)
            ->map(static fn (array $module): array => [
                $module['module'] ?? '',
                (int) ($module['total_findings'] ?? 0),
                (int) ($module['critical_count'] ?? 0),
                (int) ($module['warning_count'] ?? 0),
                (int) ($module['info_count'] ?? 0),
            ])
            ->all();

        $this->table(['Module', 'Total', 'Critical', 'Warning', 'Info'], $rows);
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