<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    /** @var list<string> */
    private const TARGET_TABLES = [
        'presences',
        'training_sessions',
        'call_ups',
    ];

    /** @var list<string> */
    private const TRAINING_SESSION_DEPENDENTS = [
        'training_session_attendance',
        'training_session_metrics',
    ];

    public function up(): void
    {
        $this->assertCleanupIsSafe();

        foreach (self::TRAINING_SESSION_DEPENDENTS as $table) {
            $this->dropTrainingSessionForeignKey($table);
        }

        foreach (self::TARGET_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        $this->restorePresencesTable();
        $this->restoreTrainingSessionsTable();
        $this->restoreCallUpsTable();

        foreach (self::TRAINING_SESSION_DEPENDENTS as $table) {
            $this->restoreTrainingSessionForeignKey($table);
        }
    }

    private function assertCleanupIsSafe(): void
    {
        foreach (self::TARGET_TABLES as $table) {
            $this->assertTableIsEmpty($table, 'target legacy table');
        }

        foreach (self::TRAINING_SESSION_DEPENDENTS as $table) {
            $this->assertTableIsEmpty($table, 'preserved training_sessions dependent table');
        }
    }

    private function assertTableIsEmpty(string $table, string $classification): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = (int) DB::table($table)->count();
        if ($rows > 0) {
            throw new RuntimeException(sprintf(
                'Refusing Desportivo legacy cleanup: %s [%s] contains %d row(s). No schema changes were applied.',
                $classification,
                $table,
                $rows,
            ));
        }
    }

    private function dropTrainingSessionForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'training_session_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $constraint = $table.'_training_session_id_foreign';
            DB::statement(sprintf(
                'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"',
                $table,
                $constraint,
            ));

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTrainingSessionDependentWithoutLegacyForeignKey($table);

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['training_session_id']);
        });
    }

    private function rebuildSqliteTrainingSessionDependentWithoutLegacyForeignKey(string $table): void
    {
        $temporaryTable = $table.'_legacy_fk_cleanup';

        if (Schema::hasTable($temporaryTable)) {
            throw new RuntimeException('Refusing Desportivo legacy cleanup: temporary SQLite table already exists ['.$temporaryTable.'].');
        }

        match ($table) {
            'training_session_attendance' => Schema::create($temporaryTable, function (Blueprint $blueprint): void {
                $blueprint->uuid('id')->primary();
                $blueprint->uuid('training_session_id');
                $blueprint->uuid('user_id');
                $blueprint->boolean('presente')->default(true);
                $blueprint->string('estado')->default('presente');
                $blueprint->integer('volume_real_m')->nullable();
                $blueprint->integer('rpe')->nullable();
                $blueprint->text('observacoes_tecnicas')->nullable();
                $blueprint->timestamps();

                $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $blueprint->unique(['training_session_id', 'user_id']);
                $blueprint->index(['presente']);
                $blueprint->index(['estado']);
            }),
            'training_session_metrics' => Schema::create($temporaryTable, function (Blueprint $blueprint): void {
                $blueprint->uuid('id')->primary();
                $blueprint->uuid('training_session_id');
                $blueprint->uuid('user_id');
                $blueprint->integer('volume_m')->nullable();
                $blueprint->integer('rpe')->nullable();
                $blueprint->string('zona_treino')->nullable();
                $blueprint->string('tipo_metrica')->nullable();
                $blueprint->decimal('valor', 10, 2)->nullable();
                $blueprint->timestamps();

                $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $blueprint->index(['training_session_id', 'user_id']);
            }),
            default => throw new RuntimeException('Unsupported SQLite training_sessions dependent table ['.$table.'].'),
        };

        $rows = DB::table($table)->get()->map(
            static fn (object $row): array => (array) $row,
        )->all();

        if ($rows !== []) {
            DB::table($temporaryTable)->insert($rows);
        }

        Schema::drop($table);
        Schema::rename($temporaryTable, $table);
    }

    private function restoreTrainingSessionForeignKey(string $table): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasTable('training_sessions')
            || ! Schema::hasColumn($table, 'training_session_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('training_session_id')
                ->references('id')
                ->on('training_sessions')
                ->cascadeOnDelete();
        });
    }

    private function restorePresencesTable(): void
    {
        if (Schema::hasTable('presences')) {
            return;
        }

        Schema::create('presences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->date('data');
            $table->uuid('treino_id')->nullable();
            $table->uuid('escalao_id')->nullable();
            $table->string('tipo', 30);
            $table->text('justificacao')->nullable();
            $table->boolean('presente')->default(false);
            $table->boolean('is_legacy')->default(true);
            $table->uuid('migrated_to_training_athlete_id')->nullable();
            $table->string('status', 50)->default('ausente');
            $table->integer('distancia_realizada_m')->nullable();
            $table->string('classificacao', 50)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('treino_id')->references('id')->on('trainings')->nullOnDelete();
            $table->foreign('escalao_id')->references('id')->on('age_groups')->nullOnDelete();
            $table->foreign('migrated_to_training_athlete_id')->references('id')->on('training_athletes')->nullOnDelete();

            $table->index('user_id');
            $table->index('data');
            $table->index('treino_id');
            $table->index('tipo');
            $table->index('presente');
            $table->index('is_legacy');
            $table->index('migrated_to_training_athlete_id');
        });
    }

    private function restoreTrainingSessionsTable(): void
    {
        if (Schema::hasTable('training_sessions')) {
            return;
        }

        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('equipa_id')->nullable();
            $table->dateTime('data_hora');
            $table->integer('duracao_minutos')->default(60);
            $table->string('local')->nullable();
            $table->text('objetivos')->nullable();
            $table->string('estado', 30)->default('scheduled');
            $table->timestamps();

            $table->foreign('equipa_id')->references('id')->on('teams')->nullOnDelete();

            $table->index('data_hora');
            $table->index('equipa_id');
            $table->index('estado');
        });
    }

    private function restoreCallUpsTable(): void
    {
        if (Schema::hasTable('call_ups')) {
            return;
        }

        Schema::create('call_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('team_id');
            $table->json('called_up_athletes');
            $table->json('attendances')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();

            $table->index('event_id');
            $table->index('team_id');
        });
    }
};
