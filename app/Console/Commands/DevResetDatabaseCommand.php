<?php

namespace App\Console\Commands;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DevResetDatabaseCommand extends Command
{
    protected $signature = 'dev:reset-database
        {--database= : Ligacao de base de dados}
        {--seed : Executa db:seed apos migrate}
        {--seeder= : Classe de seeder raiz}
        {--confirm= : Confirmacao obrigatoria para reset local}';

    protected $description = 'Reset seguro da base local de desenvolvimento sem usar comandos destrutivos bloqueados';

    public function handle(): int
    {
        if (DatabaseSafetyGuard::isProduction()) {
            $this->error('Comando bloqueado: APP_ENV=production nunca permite reset de base de dados.');

            return 2;
        }

        if (DatabaseSafetyGuard::databaseUrlContainsProtectedSignature()) {
            $this->error('Comando bloqueado: DATABASE_URL protegido (ex.: neon.tech).');

            return 2;
        }

        if (! app()->environment(['local', 'testing'])) {
            $this->error('Comando bloqueado: dev:reset-database so pode ser executado em APP_ENV local/testing.');

            return 2;
        }

        if (! hash_equals(DatabaseSafetyGuard::DEV_RESET_CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Confirmacao invalida. Use --confirm=RESET_LOCAL_DEV.');

            return 2;
        }

        $database = (string) ($this->option('database') ?: config('database.default'));
        $connection = DB::connection($database);
        $schema = $connection->getSchemaBuilder();

        $this->components->task('Dropping all views', function () use ($schema) {
            try {
                $schema->dropAllViews();
            } catch (\Throwable) {
                // Nem todos os drivers suportam dropAllViews de forma uniforme.
            }

            return true;
        });

        $this->components->task('Dropping all tables', function () use ($schema) {
            $schema->dropAllTables();

            return true;
        });

        $this->components->task('Dropping all types', function () use ($schema) {
            if (method_exists($schema, 'dropAllTypes')) {
                try {
                    $schema->dropAllTypes();
                } catch (\Throwable) {
                    // Alguns drivers nao possuem suporte para tipos custom.
                }
            }

            return true;
        });

        $this->call('migrate', [
            '--database' => $database,
            '--force' => true,
        ]);

        if ((bool) $this->option('seed') || is_string($this->option('seeder'))) {
            $this->call('db:seed', array_filter([
                '--database' => $database,
                '--class' => $this->option('seeder') ?: null,
                '--force' => true,
            ]));
        }

        $this->components->info('Reset seguro concluido para ambiente local/testing.');

        return 0;
    }
}
