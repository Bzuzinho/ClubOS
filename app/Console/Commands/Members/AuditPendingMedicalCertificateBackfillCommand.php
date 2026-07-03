<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\PendingMedicalCertificateBackfillAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditPendingMedicalCertificateBackfillCommand extends Command
{
    protected $signature = 'members:audit-pending-medical-certificate-backfill
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Auditoria read-only dos 5 casos pendentes de data_atestado_medico para athlete_sports_data';

    public function __construct(private readonly PendingMedicalCertificateBackfillAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->auditor->audit();

        $this->writeReportIfRequested($payload);
        $this->renderOutput($payload);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $this->renderHumanOutput($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanOutput(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];

        $this->info('Audit pending medical certificate backfill (read-only)');
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? PendingMedicalCertificateBackfillAuditor::VERSION)));

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['pending_user_ids_count', (int) ($payload['scope']['pending_user_ids_count'] ?? 0)],
                ['users_found_count', (int) ($summary['users_found_count'] ?? 0)],
                ['users_missing_count', (int) ($summary['users_missing_count'] ?? 0)],
            ]
        );

        $rows = [];
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $user = is_array($case['user'] ?? null) ? $case['user'] : [];
            $target = is_array($case['target'] ?? null) ? $case['target'] : [];
            $validation = is_array($case['validation'] ?? null) ? $case['validation'] : [];

            $rows[] = [
                (string) ($case['user_id'] ?? ''),
                (string) ($case['classification'] ?? ''),
                ((bool) ($case['found'] ?? false)) ? 'true' : 'false',
                (string) ($user['name'] ?? ''),
                (string) ($user['perfil'] ?? ''),
                ((bool) ($validation['is_sports_member'] ?? false)) ? 'true' : 'false',
                ((bool) ($target['athlete_sports_data_exists'] ?? false)) ? 'true' : 'false',
                (string) ($user['users_data_atestado_medico'] ?? ''),
                (string) ($target['athlete_sports_data_data_atestado_medico'] ?? ''),
                ((bool) ($validation['legacy_date_valid'] ?? false)) ? 'true' : 'false',
            ];
        }

        $this->newLine();
        $this->table(
            [
                'user_id',
                'classification',
                'found',
                'name',
                'perfil',
                'is_sports_member',
                'has_sports_target',
                'legacy_date',
                'target_date',
                'legacy_date_valid',
            ],
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

        $path = $this->resolvePath($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
