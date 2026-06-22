<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

class MemberDataAuditProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_is_read_only_and_never_creates_target_rows(): void
    {
        $this->seedAuditCandidate();

        $this->assertSame(0, Artisan::call('members:audit-data-structure', [
            '--json' => true,
        ]));

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['total_users'] ?? 0);
        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_audit_command_rejects_backfill_write_flags(): void
    {
        $this->seedAuditCandidate();

        try {
            Artisan::call('members:audit-data-structure', [
                '--commit' => true,
                '--unlock-write' => true,
                '--confirm' => 'BACKFILL_MEMBER_DATA',
            ]);

            $this->fail('A auditoria nao deve aceitar flags de escrita do backfill.');
        } catch (InvalidOptionException $exception) {
            $this->assertStringContainsString('--commit', $exception->getMessage());
        }

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    private function seedAuditCandidate(): User
    {
        return User::factory()->create([
            'nome_completo' => 'Audit Protection User',
            'name' => 'Audit Protection User',
            'nif' => '223456780',
            'data_nascimento' => '2002-01-20',
            'sexo' => 'masculino',
            'rgpd' => true,
            'consentimento' => true,
            'declaracao_de_transporte' => true,
            'afiliacao' => false,
            'estado' => 'ativo',
            'email_secundario' => 'audit.protection@example.test',
        ]);
    }
}
