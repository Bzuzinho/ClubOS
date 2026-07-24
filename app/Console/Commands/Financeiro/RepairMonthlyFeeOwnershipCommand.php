<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\MonthlyFeeOwnershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class RepairMonthlyFeeOwnershipCommand extends Command
{
    protected $signature = 'finance:repair-monthly-fee-ownership {--dry-run} {--json} {--report-path=storage/app/audits/fee1-repair-monthly-fee-ownership.json} {--confirm=}';
    protected $description = 'Repara apenas titularidades de mensalidades determináveis de forma inequívoca';

    public function __construct(private readonly MonthlyFeeOwnershipService $service) { parent::__construct(); }

    public function handle(): int
    {
        $apply = ! $this->option('dry-run') && hash_equals('REPAIR_MONTHLY_FEE_OWNERSHIP', (string) $this->option('confirm'));
        $payload = $this->service->repair($apply);
        if (! $apply) {
            $payload['confirmation_required'] = 'REPAIR_MONTHLY_FEE_OWNERSHIP';
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = trim((string) $this->option('report-path'));
        if ($path !== '') {
            $path = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) ? $path : base_path($path);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
        }
        if ($this->option('json')) $this->line($json);
        else $this->table(['Métrica', 'Valor'], collect($payload['summary'])->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value])->values()->all());
        return self::SUCCESS;
    }
}
