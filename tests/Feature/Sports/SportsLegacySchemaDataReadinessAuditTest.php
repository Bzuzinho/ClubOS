<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Services\SportsFoundation\SportsLegacySchemaDataReadinessAuditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SportsLegacySchemaDataReadinessAuditTest extends TestCase
{
    public function test_audit_is_read_only_and_classifies_forbidden_tables(): void
    {
        $trackedTables = collect([
            'presences',
            'training_sessions',
            'event_results',
            'event_attendances',
            'teams',
            'team_members',
            'call_ups',
            'training_athletes',
            'trainings',
            'competitions',
            'competition_registrations',
        ])->filter(fn (string $table): bool => Schema::hasTable($table))->values();

        $snapshot = fn (): array => $trackedTables
            ->mapWithKeys(fn (string $table): array => [$table => (int) DB::table($table)->count()])
            ->all();

        $before = $snapshot();
        $report = app(SportsLegacySchemaDataReadinessAuditor::class)->audit();
        $after = $snapshot();

        $this->assertTrue($report['read_only']);
        $this->assertSame('desportivo_legacy', $report['tables']['presences']['owner']);
        $this->assertTrue($report['tables']['presences']['removal_candidate']);
        $this->assertFalse($report['tables']['event_attendances']['removal_candidate']);
        $this->assertSame('external_module_owned', $report['tables']['teams']['classification']);
        $this->assertSame($before, $after);
    }

    public function test_presence_reconciliation_never_guesses_missing_links(): void
    {
        $report = app(SportsLegacySchemaDataReadinessAuditor::class)->audit();
        $presence = $report['presence_reconciliation'];

        $this->assertArrayHasKey('legacy_count', $presence);
        $this->assertArrayHasKey('reconciled_count', $presence);
        $this->assertArrayHasKey('unreconciled_count', $presence);
        $this->assertGreaterThanOrEqual(0, $presence['unreconciled_count']);
    }

    public function test_audit_command_exposes_json_contract_without_writes(): void
    {
        $path = storage_path('framework/testing/sports-legacy-schema-data-readiness.json');
        @unlink($path);

        $this->artisan('desportivo:audit-legacy-schema-data', ['--json' => $path])
            ->assertSuccessful();

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('sports-legacy-schema-data-readiness-v1', $payload['version']);

        @unlink($path);
    }
}
