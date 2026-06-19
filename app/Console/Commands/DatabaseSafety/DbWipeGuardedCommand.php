<?php

namespace App\Console\Commands\DatabaseSafety;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DbWipeGuardedCommand extends Command
{
    protected $signature = 'db:wipe
        {--database= : The database connection to use}
        {--drop-views : Drop all tables and views}
        {--drop-types : Drop all tables and types (Postgres only)}
        {--force : Force the operation to run when in production}
        {--confirm= : Token de confirmacao explicita para comandos destrutivos}';

    protected $description = 'Drop all tables, views, and types (guarded)';

    public function handle(): int
    {
        $decision = DatabaseSafetyGuard::evaluateDestructiveCommand('db:wipe', (string) $this->option('confirm'));

        if (! $decision['allowed']) {
            $this->error((string) $decision['reason']);

            return 2;
        }

        $database = $this->option('database') ?: config('database.default');
        $schema = DB::connection($database)->getSchemaBuilder();

        if ((bool) $this->option('drop-views')) {
            $schema->dropAllViews();
            $this->components->info('Dropped all views successfully.');
        }

        $schema->dropAllTables();
        $this->components->info('Dropped all tables successfully.');

        if ((bool) $this->option('drop-types') && method_exists($schema, 'dropAllTypes')) {
            $schema->dropAllTypes();
            $this->components->info('Dropped all types successfully.');
        }

        return 0;
    }
}
