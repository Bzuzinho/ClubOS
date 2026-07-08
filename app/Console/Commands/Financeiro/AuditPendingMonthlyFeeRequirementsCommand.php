<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\PendingMonthlyFeeRequirementAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditPendingMonthlyFeeRequirementsCommand extends Command
{
    protected $signature = 'finance:audit-pending-monthly-fee-requirements
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}
        {--user= : Audita apenas um user_id}';

    protected $description = 'Audita casos missing_required da mensalidade com diagnostico funcional read-only';

    public function __construct(private readonly PendingMonthlyFeeRequirementAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = is_string($this->option('user')) ? trim((string) $this->option('user')) : '';
        $payload = $this->auditor->audit($userId !== '' ? $userId : null);

        $this->writeReportIfRequested($payload);

        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));
        } else {
            $this->renderHumanReport($payload);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];

        $this->info('Audit pending monthly fee requirements (read-only)');
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? PendingMonthlyFeeRequirementAuditor::VERSION)));
        $this->newLine();

        $this->table(
            ['Metrica', 'Valor'],
            [
                ['total_cases', (int) ($summary['total_cases'] ?? 0)],
                ['missing_required_count', (int) ($summary['missing_required_count'] ?? 0)],
                ['not_required_count', (int) ($summary['not_required_count'] ?? 0)],
                ['resolved_monthly_fee_present_count', (int) ($summary['resolved_monthly_fee_present_count'] ?? 0)],
            ]
        );

        if ($cases === []) {
            return;
        }

        $rows = [];
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $operational = is_array($case['operational_state'] ?? null) ? $case['operational_state'] : [];
            $eligibility = is_array($case['eligibility'] ?? null) ? $case['eligibility'] : [];

            $rows[] = [
                (string) ($case['user_id'] ?? ''),
                (string) ($case['classification'] ?? ''),
                implode(',', is_array($case['reason_codes'] ?? null) ? $case['reason_codes'] : []),
                (string) ($operational['estado'] ?? ''),
                ((bool) ($eligibility['should_have_monthly_fee'] ?? false)) ? 'true' : 'false',
            ];
        }

        $this->newLine();
        $this->table(
            ['user_id', 'classification', 'reason_codes', 'estado', 'should_have_monthly_fee'],
            $rows,
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $path = str_starts_with($reportPath, '/') ? $reportPath : base_path($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));

        $this->line(sprintf('Report written to: %s', $path));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
