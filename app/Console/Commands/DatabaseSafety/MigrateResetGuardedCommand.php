<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateResetGuardedCommand extends Command
{
    protected $signature = 'migrate:reset
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--pretend : Dump the SQL queries that would be run}
        {--confirm= : Token de confirmacao explicita para comandos destrutivos}';

    protected $description = 'Rollback all database migrations (guarded)';

    public function handle(): int
    {
        $decision = DatabaseSafetyGuard::evaluateDestructiveCommand('migrate:reset', (string) $this->option('confirm'));

        if (! $decision['allowed']) {
            $this->error((string) $decision['reason']);

            return 2;
        }

        $database = $this->option('database') ?: config('database.default');
        $migrationsTable = config('database.migrations.table', config('database.migrations', 'migrations'));

        try {
            $steps = (int) DB::connection($database)->table($migrationsTable)->count();
        } catch (\Throwable) {
            $this->components->warn('Migration table not found.');

            return 0;
        }

        if ($steps <= 0) {
            $this->components->warn('Nothing to rollback.');

            return 0;
        }

        return $this->call('migrate:rollback', array_filter([
            '--database' => $database,
            '--path' => $this->option('path'),
            '--realpath' => (bool) $this->option('realpath'),
            '--pretend' => (bool) $this->option('pretend'),
            '--step' => $steps,
            '--force' => true,
        ], static fn ($value) => $value !== null && $value !== []));
    }
}
