<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class SportsLegacyPhysicalTableCleanupTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_08_17_141500_drop_retired_desportivo_legacy_tables.php';

    /** @var list<string> */
    private const TARGETS = ['presences', 'training_sessions', 'call_ups'];

    public function test_authorized_legacy_tables_are_physically_absent_after_migrations(): void
    {
        foreach (self::TARGETS as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must be physically removed.');
        }
    }

    public function test_cleanup_preserves_surviving_dependent_and_external_module_tables(): void
    {
        foreach ([
            'training_session_metrics',
            'event_attendances',
            'teams',
            'team_members',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is outside this physical cleanup scope.');
        }
    }

    public function test_sqlite_has_no_foreign_key_left_pointing_to_removed_training_sessions(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific schema integrity assertion.');
        }

        foreach (['training_session_attendance', 'training_session_metrics'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $foreignKeys = DB::select("PRAGMA foreign_key_list('{$table}')");

            foreach ($foreignKeys as $foreignKey) {
                $this->assertNotSame(
                    'training_sessions',
                    $foreignKey->table ?? null,
                    $table.' retains a stale FK to the physically removed training_sessions table.',
                );
            }
        }
    }

    public function test_cleanup_is_fail_closed_before_any_target_drop_when_data_exists(): void
    {
        foreach (self::TARGETS as $table) {
            $this->assertFalse(Schema::hasTable($table));
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('id')->primary();
            });
        }

        DB::table('presences')->insert(['id' => '00000000-0000-0000-0000-000000000001']);
        $migration = require base_path(self::MIGRATION);

        try {
            $migration->up();
            $this->fail('Cleanup must refuse to run when an authorized target table contains rows.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing Desportivo legacy cleanup', $exception->getMessage());
            $this->assertStringContainsString('[presences]', $exception->getMessage());

            foreach (self::TARGETS as $table) {
                $this->assertTrue(Schema::hasTable($table), $table.' was changed before preflight completed.');
            }
        } finally {
            foreach (self::TARGETS as $table) {
                Schema::dropIfExists($table);
            }
        }
    }

    public function test_migration_source_drops_only_the_authorized_legacy_tables(): void
    {
        $source = (string) file_get_contents(base_path(self::MIGRATION));

        $this->assertSame(1, substr_count($source, 'Schema::dropIfExists($table)'));
        foreach (self::TARGETS as $table) {
            $this->assertStringContainsString("'{$table}'", $source);
        }

        foreach ([
            'training_session_attendance',
            'training_session_metrics',
            'event_results',
            'event_attendances',
            'teams',
            'team_members',
        ] as $table) {
            $this->assertStringNotContainsString("Schema::dropIfExists('{$table}')", $source);
            $this->assertStringNotContainsString("Schema::drop('{$table}')", $source);
        }

        $this->assertStringNotContainsString('DROP TABLE', strtoupper($source));
        $this->assertStringNotContainsString('disableForeignKeyConstraints', $source);

        $preflightPosition = strpos($source, '$this->assertCleanupIsSafe();');
        $foreignKeyPosition = strpos($source, '$this->dropTrainingSessionForeignKey($table);');
        $dropPosition = strpos($source, 'Schema::dropIfExists($table);');

        $this->assertNotFalse($preflightPosition);
        $this->assertNotFalse($foreignKeyPosition);
        $this->assertNotFalse($dropPosition);
        $this->assertLessThan($foreignKeyPosition, $preflightPosition);
        $this->assertLessThan($dropPosition, $preflightPosition);
    }
}
