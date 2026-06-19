<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Command;

class MigrateFreshGuardedCommand extends Command
{
    protected $signature = 'migrate:fresh
        {--database= : The database connection to use}
        {--drop-views : Drop all tables and views}
        {--drop-types : Drop all tables and types (Postgres only)}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--schema-path= : The path to a schema dump file}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step : Force the migrations to be run so they can be rolled back individually}
        {--confirm= : Token de confirmacao explicita para comandos destrutivos}';

    protected $description = 'Drop all tables and re-run all migrations (guarded)';

    public function handle(): int
    {
        $decision = DatabaseSafetyGuard::evaluateDestructiveCommand('migrate:fresh', (string) $this->option('confirm'));

        if (! $decision['allowed']) {
            $this->error((string) $decision['reason']);

            return 2;
        }

        $wipeResult = $this->call('db:wipe', array_filter([
            '--database' => $this->option('database'),
            '--drop-views' => (bool) $this->option('drop-views'),
            '--drop-types' => (bool) $this->option('drop-types'),
            '--force' => true,
            '--confirm' => (string) $this->option('confirm'),
        ]));

        if ($wipeResult !== 0) {
            return $wipeResult;
        }

        $migrateResult = $this->call('migrate', array_filter([
            '--database' => $this->option('database'),
            '--path' => $this->option('path'),
            '--realpath' => (bool) $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--force' => true,
            '--step' => (bool) $this->option('step'),
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
