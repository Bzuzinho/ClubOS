<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Command;

class MigrateRefreshGuardedCommand extends Command
{
    protected $signature = 'migrate:refresh
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step= : The number of migrations to be reverted & re-run}
        {--confirm= : Token de confirmacao explicita para comandos destrutivos}';

    protected $description = 'Reset and re-run all migrations (guarded)';

    public function handle(): int
    {
        $decision = DatabaseSafetyGuard::evaluateDestructiveCommand('migrate:refresh', (string) $this->option('confirm'));

        if (! $decision['allowed']) {
            $this->error((string) $decision['reason']);

            return 2;
        }

        $step = (int) ($this->option('step') ?: 0);

        if ($step > 0) {
            $rollbackResult = $this->call('migrate:rollback', array_filter([
                '--database' => $this->option('database'),
                '--path' => $this->option('path'),
                '--realpath' => (bool) $this->option('realpath'),
                '--step' => $step,
                '--force' => true,
            ], static fn ($value) => $value !== null && $value !== []));
        } else {
            $rollbackResult = $this->call('migrate:reset', array_filter([
                '--database' => $this->option('database'),
                '--path' => $this->option('path'),
                '--realpath' => (bool) $this->option('realpath'),
                '--force' => true,
                '--confirm' => (string) $this->option('confirm'),
            ], static fn ($value) => $value !== null && $value !== []));
        }

        if ($rollbackResult !== 0) {
            return $rollbackResult;
        }

        $migrateResult = $this->call('migrate', array_filter([
            '--database' => $this->option('database'),
            '--path' => $this->option('path'),
            '--realpath' => (bool) $this->option('realpath'),
            '--force' => true,
        ], static fn ($value) => $value !== null && $value !== []));

        if ($migrateResult !== 0) {
            return $migrateResult;
        }

        if ((bool) $this->option('seed') || is_string($this->option('seeder'))) {
            return $this->call('db:seed', array_filter([
                '--database' => $this->option('database'),
                '--class' => $this->option('seeder') ?: 'Database\\Seeders\\DatabaseSeeder',
                '--force' => true,
            ]));
        }

        return 0;
    }
}
