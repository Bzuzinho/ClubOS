<?php

namespace Tests\Feature\Infrastructure;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DatabaseSafetyTest extends TestCase
{
    public function test_migrate_fresh_is_blocked_when_protection_flag_is_enabled(): void
    {
        Config::set('database_safety.protect_destructive_commands', true);
        Config::set('database_safety.allow_destructive_commands', true);

        $this->artisan('migrate:fresh --confirm=DESTROY_LOCAL_DATABASE')
            ->expectsOutputToContain('DB_PROTECT_DESTRUCTIVE_COMMANDS=true')
            ->assertExitCode(2);
    }

    public function test_migrate_reset_is_blocked_on_neon_database_url(): void
    {
        Config::set('database_safety.protect_destructive_commands', false);
        Config::set('database_safety.allow_destructive_commands', true);
        Config::set('database.connections.sqlite.url', 'postgres://x.neon.tech/db');

        $this->artisan('migrate:reset --pretend --confirm=DESTROY_LOCAL_DATABASE')
            ->expectsOutputToContain('neon.tech')
            ->assertExitCode(2);
    }

    public function test_migrate_reset_is_allowed_only_with_all_required_flags_in_local_testing(): void
    {
        Config::set('database_safety.protect_destructive_commands', false);
        Config::set('database_safety.allow_destructive_commands', true);
        Config::set('database_safety.destructive_confirmation', 'DESTROY_LOCAL_DATABASE');
        Config::set('database.connections.sqlite.url', null);

        $this->artisan('migrate:reset --pretend --confirm=DESTROY_LOCAL_DATABASE')
            ->assertExitCode(0);
    }

    public function test_migrate_reset_is_blocked_without_allow_flag_even_with_confirm(): void
    {
        Config::set('database_safety.protect_destructive_commands', false);
        Config::set('database_safety.allow_destructive_commands', false);

        $this->artisan('migrate:reset --pretend --confirm=DESTROY_LOCAL_DATABASE')
            ->expectsOutputToContain('DB_ALLOW_DESTRUCTIVE_COMMANDS=true')
            ->assertExitCode(2);
    }

    public function test_dev_reset_database_requires_local_confirmation_token(): void
    {
        $this->artisan('dev:reset-database --confirm=WRONG')
            ->expectsOutputToContain('RESET_LOCAL_DEV')
            ->assertExitCode(2);
    }

    public function test_dev_reset_database_refuses_neon_url_even_with_valid_confirm(): void
    {
        Config::set('database.connections.sqlite.url', 'postgres://x.neon.tech/db');

        $this->artisan('dev:reset-database --confirm=' . DatabaseSafetyGuard::DEV_RESET_CONFIRMATION)
            ->expectsOutputToContain('neon.tech')
            ->assertExitCode(2);
    }
}
