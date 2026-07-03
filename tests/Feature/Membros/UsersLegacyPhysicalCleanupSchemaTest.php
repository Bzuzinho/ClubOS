<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UsersLegacyPhysicalCleanupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_m6_removed_columns_are_absent_in_users_and_present_in_dados_pessoais(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'estado_civil'));
        $this->assertFalse(Schema::hasColumn('users', 'numero_irmaos'));

        $this->assertTrue(Schema::hasColumn('dados_pessoais', 'estado_civil'));
        $this->assertTrue(Schema::hasColumn('dados_pessoais', 'numero_irmaos'));
    }

    public function test_cleanup_readiness_audit_stays_green_after_physical_cleanup_for_removed_fields(): void
    {
        $estadoCivilExitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'estado_civil',
            '--fail-on-not-ready' => true,
        ]);

        $numeroIrmaosExitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'numero_irmaos',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $estadoCivilExitCode);
        $this->assertSame(0, $numeroIrmaosExitCode);
    }
}
