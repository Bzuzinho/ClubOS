<?php

namespace App\Console\Commands\System;

use App\Services\System\PerformanceAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PerformanceAuditCommand extends Command
{
    protected $signature = 'system:audit-performance
        {--json : Output JSON}
        {--report-path= : Write the audit payload to this path}
        {--route= : Optional route/path context for the audit report}
        {--only-critical : Return only warning/critical findings}';

    protected $description = 'Audit ClubOS static performance risk points without changing data.';

    public function handle(PerformanceAuditService $service): int
    {
        $payload = $service->audit([
            'route' => $this->option('route'),
            'only_critical' => (bool) $this->option('only-critical'),
        ]);

        $reportPath = $this->option('report-path');
        if (is_string($reportPath) && trim($reportPath) !== '') {
            $path = base_path($reportPath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Performance audit: %d findings (%d critical, %d warnings, %d info).',
            $payload['summary']['total_findings'],
            $payload['summary']['critical_count'],
            $payload['summary']['warning_count'],
            $payload['summary']['info_count'],
        ));

        foreach ($payload['findings'] as $finding) {
            $this->line(sprintf('[%s] %s - %s', $finding['severity'], $finding['code'], $finding['message']));
        }

        return self::SUCCESS;
    }
}
