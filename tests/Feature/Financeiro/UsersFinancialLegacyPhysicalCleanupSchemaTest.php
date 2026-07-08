<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UsersFinancialLegacyPhysicalCleanupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fc2_removed_columns_are_absent_in_users_and_canonical_tables_remain_available(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'centro_custo'));
        $this->assertFalse(Schema::hasColumn('users', 'tipo_mensalidade'));
        $this->assertFalse(Schema::hasColumn('users', 'conta_corrente'));

        $this->assertTrue(Schema::hasTable('centro_custo_user'));
        $this->assertTrue(Schema::hasColumn('dados_financeiros', 'mensalidade_id'));
        $this->assertTrue(Schema::hasColumn('dados_financeiros', 'conta_corrente_manual'));
    }

    public function test_fc1_readiness_audit_stays_green_after_fc2_physical_cleanup(): void
    {
        $centroCustoExitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'centro_custo',
            '--fail-on-not-ready' => true,
        ]);

        $tipoMensalidadeExitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'tipo_mensalidade',
            '--fail-on-not-ready' => true,
        ]);

        $contaCorrenteExitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'conta_corrente',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $centroCustoExitCode);
        $this->assertSame(0, $tipoMensalidadeExitCode);
        $this->assertSame(0, $contaCorrenteExitCode);
    }
}
