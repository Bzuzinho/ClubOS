<?php

namespace App\Console\Commands\System;

use App\Services\System\DatabaseHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DatabaseHealthCommand extends Command
{
    protected $signature = 'system:database-health
        {--json : Output JSON}
        {--report-path= : Write the health payload to this path}';

    protected $description = 'Diagnose database connectivity and critical lightweight queries without exposing credentials.';

    public function handle(DatabaseHealthService $service): int
    {
        $payload = $service->check();

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

        $connection = $payload['connection'];
        $this->info(sprintf(
            'Database health: %s connection %s on %s:%s.',
            $payload['ok'] ? 'healthy' : 'unhealthy',
            $connection['name'],
            $connection['host'] ?? 'local',
            $connection['port'] ?? '-',
        ));

        foreach ($payload['checks'] as $name => $check) {
            $this->line(sprintf(
                '[%s] %s (%sms)',
                $check['ok'] ? 'ok' : 'failed',
                $name,
                $check['duration_ms'] ?? '-',
            ));
        }

        $this->line('Recommendation: '.$payload['recommendation']);

        return self::SUCCESS;
    }
}
