<?php

namespace Tests\Feature\Desportivo;

use App\Services\Desportivo\MigrateLegacyPresencesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrateLegacyPresencesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_returns_complete_retired_report_without_legacy_table(): void
    {
        $this->assertFalse(Schema::hasTable('presences'));

        $report = app(MigrateLegacyPresencesAction::class)->execute(true);

        $this->assertTrue($report['retired']);
        $this->assertTrue($report['dry_run']);
        $this->assertArrayHasKey('duration_seconds', $report);
        $this->assertSame(0, $report['total_presences']);
        $this->assertSame(0, $report['migrated']);
        $this->assertSame(0, $report['conflicts']);
        $this->assertSame(0, $report['errors']);
        $this->assertSame(0, $report['skipped']);
    }

    public function test_retired_action_cannot_query_or_recreate_presences(): void
    {
        $source = (string) file_get_contents(app_path('Services/Desportivo/MigrateLegacyPresencesAction.php'));

        $this->assertStringNotContainsString('App\\Models\\Presence', $source);
        $this->assertStringNotContainsString('Presence::', $source);
        $this->assertStringNotContainsString("Schema::create('presences'", $source);
        $this->assertStringNotContainsString("DB::table('presences'", $source);

        app(MigrateLegacyPresencesAction::class)->execute(false);

        $this->assertFalse(Schema::hasTable('presences'));
    }
}
