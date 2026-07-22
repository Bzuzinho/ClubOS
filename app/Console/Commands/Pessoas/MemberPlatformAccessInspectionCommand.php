<?php

declare(strict_types=1);

namespace App\Console\Commands\Pessoas;

use App\Services\Pessoas\MemberModelAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MemberPlatformAccessInspectionCommand extends Command
{
    protected $signature = 'people:inspect-member-platform-access
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}
        {--only-actionable : Mostra apenas casos acionaveis}
        {--user= : Filtrar por user id}';

    protected $description = 'Inspecao read-only de acesso esperado a plataforma por membro';

    public function __construct(
        private readonly MemberModelAuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditService->inspectPlatformAccess([
            'user' => $this->option('user'),
            'only_actionable' => (bool) $this->option('only-actionable'),
        ]);

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
        $this->info('Member platform access inspection');
        $this->table(
            ['Metric', 'Value'],
            collect($payload['summary'] ?? [])->map(static fn (mixed $value, string $key): array => [$key, (string) $value])->values()->all(),
        );
        $this->table(
            ['User', 'Name', 'Perfil', 'Estado', 'Portal Eligible', 'Access Granted', 'Access Granted Reason', 'Access Expected', 'Has Access Role', 'Issue', 'Recommendation'],
            collect($payload['rows'] ?? [])->map(static fn (array $row): array => [
                (string) ($row['user_id'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['perfil'] ?? ''),
                (string) ($row['estado'] ?? ''),
                (bool) ($row['portal_eligible'] ?? false) ? 'yes' : 'no',
                (bool) ($row['platform_access_granted'] ?? false) ? 'yes' : 'no',
                (string) ($row['platform_access_granted_reason'] ?? ''),
                (bool) ($row['access_expected'] ?? false) ? 'yes' : 'no',
                (bool) ($row['has_access_role'] ?? false) ? 'yes' : 'no',
                (string) ($row['issue'] ?? ''),
                (string) ($row['recommendation'] ?? ''),
            ])->all(),
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

        $reportPath = str_starts_with($reportPathOption, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $reportPathOption) === 1
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
