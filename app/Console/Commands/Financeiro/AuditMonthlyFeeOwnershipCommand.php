<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\MonthlyFeeOwnershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditMonthlyFeeOwnershipCommand extends Command
{
    protected $signature = 'finance:audit-monthly-fee-ownership {--json} {--report-path=storage/app/audits/fee1-monthly-fee-ownership.json}';
    protected $description = 'Audita a titularidade das mensalidades e ligações financeiras sem alterar dados';

    public function __construct(private readonly MonthlyFeeOwnershipService $service) { parent::__construct(); }

    public function handle(): int
    {
        $payload = $this->service->audit();
        $this->writeReport($payload);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($this->option('json')) {
            $this->line($json);
        } else {
            $this->info('Auditoria de titularidade de mensalidades');
            $this->table(['Métrica', 'Valor'], collect($payload['summary'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        }
        return self::SUCCESS;
    }

    private function writeReport(array $payload): void
    {
        $path = trim((string) $this->option('report-path'));
        if ($path === '') return;
        $path = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
