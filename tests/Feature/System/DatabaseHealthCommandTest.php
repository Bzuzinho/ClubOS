<?php

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_health_command_outputs_json_without_credentials(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'ep-round-mud-ahmzb6j9-pooler.c-3.us-east-1.aws.neon.tech',
            'database.connections.pgsql.port' => '6543',
            'database.connections.pgsql.database' => 'clubos',
            'database.connections.pgsql.username' => 'clubos_user',
            'database.connections.pgsql.password' => 'super-secret-password',
            'database.connections.pgsql.connect_timeout' => 5,
        ]);

        config(['database.default' => 'sqlite']);

        $exitCode = Artisan::call('system:database-health', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('connection', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('recommendation', $payload);
        $this->assertArrayNotHasKey('password', $payload['connection']);
        $this->assertStringNotContainsString('super-secret-password', $output);
    }

    public function test_database_health_command_writes_report_path(): void
    {
        $reportPath = 'storage/app/audits/p1-database-health-test.json';

        $exitCode = Artisan::call('system:database-health', [
            '--json' => true,
            '--report-path' => $reportPath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists(base_path($reportPath));
    }
}
