<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MemberContactBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_does_not_write(): void
    {
        $user = $this->createUserWithDadosPessoais(contactoUsers: '910000001', contactoCanonical: null);

        $report = $this->runJsonCommand();

        $this->assertNull($this->contactoFromDadosPessoais($user));
        $this->assertSame(1, $report['summary']['candidates']);
        $this->assertSame(0, $report['summary']['updated']);
        $this->assertTrue($report['summary']['dry_run']);
        $this->assertFalse($report['summary']['committed']);
    }

    public function test_command_blocks_commit_without_confirmation(): void
    {
        $user = $this->createUserWithDadosPessoais(contactoUsers: '910000002', contactoCanonical: null);

        $this->artisan('members:backfill-contact --commit')
            ->expectsOutputToContain('Escrita bloqueada')
            ->assertExitCode(2);

        $this->assertNull($this->contactoFromDadosPessoais($user));
    }

    public function test_command_blocks_commit_with_wrong_confirmation(): void
    {
        $user = $this->createUserWithDadosPessoais(contactoUsers: '910000003', contactoCanonical: null);

        $this->artisan('members:backfill-contact --commit --confirm=WRONG')
            ->expectsOutputToContain('Escrita bloqueada')
            ->assertExitCode(2);

        $this->assertNull($this->contactoFromDadosPessoais($user));
    }

    public function test_command_writes_contact_with_explicit_confirmation(): void
    {
        $user = $this->createUserWithDadosPessoais(contactoUsers: '910000004', contactoCanonical: null);

        $this->artisan('members:backfill-contact --commit --confirm=BACKFILL_CONTACT')
            ->assertExitCode(0);

        $this->assertSame('910000004', $this->contactoFromDadosPessoais($user));
    }

    public function test_command_does_not_overwrite_existing_canonical_contact(): void
    {
        $user = $this->createUserWithDadosPessoais(contactoUsers: '910000005', contactoCanonical: '930123123');

        $this->artisan('members:backfill-contact --commit --confirm=BACKFILL_CONTACT')
            ->assertExitCode(0);

        $this->assertSame('930123123', $this->contactoFromDadosPessoais($user));
    }

    public function test_command_uses_fallback_order_contacto_then_telemovel_then_contacto_telefonico(): void
    {
        $userA = $this->createUserWithDadosPessoais(contactoUsers: '910000011', telemovelUsers: '920000011', contactoTelefonicoUsers: '930000011', contactoCanonical: null, name: 'Fallback A');
        $userB = $this->createUserWithDadosPessoais(contactoUsers: '   ', telemovelUsers: '920000012', contactoTelefonicoUsers: '930000012', contactoCanonical: null, name: 'Fallback B');
        $userC = $this->createUserWithDadosPessoais(contactoUsers: '', telemovelUsers: ' ', contactoTelefonicoUsers: '930000013', contactoCanonical: null, name: 'Fallback C');

        $this->artisan('members:backfill-contact --commit --confirm=BACKFILL_CONTACT')
            ->assertExitCode(0);

        $this->assertSame('910000011', $this->contactoFromDadosPessoais($userA));
        $this->assertSame('920000012', $this->contactoFromDadosPessoais($userB));
        $this->assertSame('930000013', $this->contactoFromDadosPessoais($userC));
    }

    public function test_command_does_not_create_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'name' => 'Sem Dados Pessoais',
            'nome_completo' => 'Sem Dados Pessoais',
            'contacto' => '910000021',
        ]);

        $report = $this->runJsonCommand([
            '--commit' => true,
            '--confirm' => 'BACKFILL_CONTACT',
        ]);

        $this->assertDatabaseMissing('dados_pessoais', ['user_id' => $user->id]);
        $this->assertSame(1, $report['summary']['skipped_missing_dados_pessoais']);
    }

    public function test_command_supports_user_id_and_limit(): void
    {
        $target = $this->createUserWithDadosPessoais(contactoUsers: '910000031', contactoCanonical: null, name: 'A User Target');
        $other = $this->createUserWithDadosPessoais(contactoUsers: '910000032', contactoCanonical: null, name: 'B User Other');

        $this->artisan(sprintf(
            'members:backfill-contact --commit --confirm=BACKFILL_CONTACT --user-id=%s',
            $target->id
        ))->assertExitCode(0);

        $this->assertSame('910000031', $this->contactoFromDadosPessoais($target));
        $this->assertNull($this->contactoFromDadosPessoais($other));

        $limited = $this->runJsonCommand([
            '--limit' => '1',
        ]);

        $this->assertSame(1, $limited['summary']['total_users_analyzed']);
    }

    public function test_command_writes_report_path(): void
    {
        $this->createUserWithDadosPessoais(contactoUsers: '910000041', contactoCanonical: null);

        $relativePath = 'storage/app/member-contact-backfill-test/report.json';
        $absolutePath = base_path($relativePath);

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }

        $this->assertSame(0, Artisan::call('members:backfill-contact', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]));

        $this->assertFileExists($absolutePath);

        $decoded = json_decode((string) File::get($absolutePath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('items', $decoded);

        File::delete($absolutePath);
        File::deleteDirectory(dirname($absolutePath));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runJsonCommand(array $options = []): array
    {
        $exitCode = Artisan::call('members:backfill-contact', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function contactoFromDadosPessoais(User $user): ?string
    {
        return DadosPessoais::query()
            ->where('user_id', $user->id)
            ->value('contacto');
    }

    private function createUserWithDadosPessoais(
        string $contactoUsers,
        ?string $contactoCanonical,
        ?string $telemovelUsers = null,
        ?string $contactoTelefonicoUsers = null,
        string $name = 'Backfill Contact User'
    ): User {
        $user = User::factory()->create([
            'name' => $name,
            'nome_completo' => $name,
            'contacto' => $contactoUsers,
            'telemovel' => $telemovelUsers,
            'contacto_telefonico' => $contactoTelefonicoUsers,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'contacto' => $contactoCanonical,
        ]);

        return $user;
    }
}
