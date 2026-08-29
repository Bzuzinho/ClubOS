<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FamilyFinalSchemaAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_schema_keeps_only_canonical_family_structures(): void
    {
        $this->assertTrue(Schema::hasTable('user_guardian'));
        $this->assertTrue(Schema::hasTable('familias'));
        $this->assertTrue(Schema::hasTable('familia_user'));
        $this->assertFalse(Schema::hasTable('user_relationships'));
        $this->assertFalse(Schema::hasColumn('users', 'encarregado_educacao'));
        $this->assertFalse(Schema::hasColumn('users', 'educandos'));

        $this->assertTrue(Route::has('membros.familia.encarregados.store'));
        $this->assertTrue(Route::has('membros.familia.encarregados.destroy'));
        $this->assertTrue(Route::has('membros.familia.membros.store'));
        $this->assertFalse(Route::has('membros.relacoes.index'));
        $this->assertFalse(Route::has('membros.relacoes.store'));
        $this->assertFalse(Route::has('membros.relacoes.destroy'));
    }

    public function test_final_schema_audit_is_read_only_and_passes(): void
    {
        $exitCode = Artisan::call('members:audit-family-final-schema', [
            '--json' => true,
            '--fail-on-finding' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('family-final-schema-v1', $payload['version'] ?? null);
        $this->assertTrue((bool) ($payload['read_only'] ?? false));
        $this->assertTrue((bool) ($payload['summary']['ready'] ?? false));
        $this->assertSame(3, (int) ($payload['summary']['canonical_tables_present_count'] ?? 0));
        $this->assertSame(0, (int) ($payload['summary']['legacy_structures_present_count'] ?? -1));
    }

    public function test_retired_runtime_classes_are_physically_absent(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/UserRelationship.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/RelacoesMembroController.php'));
        $this->assertFileDoesNotExist(app_path('Services/Family/FamilyJsonMirrorAuditor.php'));
        $this->assertFileDoesNotExist(app_path('Services/Family/FamilyLegacyRelationshipAuditor.php'));
    }
}
