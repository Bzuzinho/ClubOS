<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Services\Operations\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class ResetOperationalDataCommand extends Command
{
    private const CONFIRMATION = 'RESET_OPERATIONAL_2026_09_29';
    private const MARKER = 'app/private/financial-reset-2026-09-29.done.json';

    protected $signature = 'ops:reset-operational-data
        {--execute : Execute the destructive reset; without this option only a preview is produced}
        {--confirm= : Exact confirmation token required for production execution}
        {--report-path= : Optional JSON report path}';

    protected $description = 'One-time reset of fictitious finance, bank, store/logistics and competition operational data while preserving members';

    public function __construct(private readonly OperationalDataResetService $reset)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $marker = storage_path(self::MARKER);

        if ((bool) $this->option('execute') && File::exists($marker)) {
            $this->info('Operational reset already completed; marker exists.');
            $this->line(File::get($marker));

            return self::SUCCESS;
        }

        if ((bool) $this->option('execute')) {
            if (! app()->environment('production')) {
                $this->error('Execution is restricted to APP_ENV=production.');

                return 2;
            }

            if (! hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
                $this->error('Invalid confirmation token.');

                return 2;
            }
        }

        $report = (bool) $this->option('execute')
            ? $this->reset->execute()
            : $this->reset->preview();

        $report['environment'] = app()->environment();
        $report['generated_at'] = now()->toIso8601String();

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reportPath = trim((string) $this->option('report-path'));

        if ($reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, $json.PHP_EOL);
        }

        if ((bool) $this->option('execute')) {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, $json.PHP_EOL);
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
