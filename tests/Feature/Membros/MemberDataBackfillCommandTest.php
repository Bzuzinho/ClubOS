<?php

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MemberDataBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_continues_without_writes(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure')
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('sem escrita')
            ->assertExitCode(0);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_commit_without_unlock_write_is_blocked(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit')
            ->expectsOutputToContain('Escrita bloqueada')
            ->assertExitCode(2);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_commit_with_unlock_write_but_without_confirm_is_blocked(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write')
            ->expectsOutputToContain('Escrita bloqueada')
            ->assertExitCode(2);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_commit_with_wrong_confirm_is_blocked(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=ERRADO')
            ->expectsOutputToContain('Escrita bloqueada')
            ->assertExitCode(2);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_commit_with_three_flags_creates_dados_pessoais(): void
    {
        $user = $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $this->assertDatabaseHas('dados_pessoais', [
            'user_id' => $user->id,
            'nome_completo' => 'Backfill User',
        ]);
    }

    public function test_commit_with_three_flags_creates_dados_configuracao(): void
    {
        $user = $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $this->assertDatabaseHas('dados_configuracao', [
            'user_id' => $user->id,
            'consentimento_rgpd' => 1,
        ]);
    }

    public function test_commit_does_not_change_users_table_data(): void
    {
        $user = $this->seedBackfillCandidate();
        $before = User::query()->findOrFail($user->id)->only([
            'nome_completo',
            'nif',
            'rgpd',
            'consentimento',
            'estado',
        ]);

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $after = User::query()->findOrFail($user->id)->only([
            'nome_completo',
            'nif',
            'rgpd',
            'consentimento',
            'estado',
        ]);

        $this->assertSame($before, $after);
    }

    public function test_commit_does_not_duplicate_records_on_second_run(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);
        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $this->assertDatabaseCount('dados_pessoais', 1);
        $this->assertDatabaseCount('dados_configuracao', 1);
    }

    public function test_second_run_reports_skipped_existing(): void
    {
        $this->seedBackfillCandidate();

        $this->assertSame(0, Artisan::call('members:backfill-data-structure', [
            '--commit' => true,
            '--unlock-write' => true,
            '--confirm' => 'BACKFILL_MEMBER_DATA',
            '--json' => true,
        ]));

        $this->assertSame(0, Artisan::call('members:backfill-data-structure', [
            '--commit' => true,
            '--unlock-write' => true,
            '--confirm' => 'BACKFILL_MEMBER_DATA',
            '--json' => true,
        ]));

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['skipped_existing_dados_pessoais']);
        $this->assertSame(1, $decoded['summary']['skipped_existing_dados_configuracao']);
    }

    public function test_existing_records_are_not_overwritten(): void
    {
        $user = $this->seedBackfillCandidate();

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Nome Existente',
            'nif' => '999999999',
            'migration_source_hash' => 'existing-hash-p',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
            'migration_source_hash' => 'existing-hash-c',
        ]);

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $this->assertDatabaseHas('dados_pessoais', [
            'user_id' => $user->id,
            'nome_completo' => 'Nome Existente',
            'nif' => '999999999',
            'migration_source_hash' => 'existing-hash-p',
        ]);

        $this->assertDatabaseHas('dados_configuracao', [
            'user_id' => $user->id,
            'consentimento_rgpd' => 0,
            'migration_source_hash' => 'existing-hash-c',
        ]);
    }

    public function test_allow_updates_option_is_blocked_in_this_sprint(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --allow-updates')
            ->expectsOutputToContain('Atualização de registos existentes ainda não está permitida nesta sprint.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_user_id_option_limits_backfill_to_one_user(): void
    {
        $target = $this->seedBackfillCandidate('Target User', '223456781');
        $other = $this->seedBackfillCandidate('Other User', '223456782');

        $this->artisan(sprintf(
            'members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA --user-id=%s',
            $target->id
        ))->assertExitCode(0);

        $this->assertDatabaseHas('dados_pessoais', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('dados_pessoais', ['user_id' => $other->id]);
    }

    public function test_limit_option_restricts_quantity(): void
    {
        $this->seedBackfillCandidate('User 1', '223456791');
        $this->seedBackfillCandidate('User 2', '223456792');

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA --limit=1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('dados_pessoais', 1);
        $this->assertDatabaseCount('dados_configuracao', 1);
    }

    public function test_json_option_returns_valid_json_structure(): void
    {
        $this->seedBackfillCandidate();

        $this->assertSame(0, Artisan::call('members:backfill-data-structure', ['--json' => true]));

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('users', $decoded);
        $this->assertArrayHasKey('dry_run', $decoded);
    }

    public function test_report_path_writes_json_report_file(): void
    {
        $this->seedBackfillCandidate();

        $relativePath = 'storage/app/member-data-backfill-report-test.json';
        $absolutePath = base_path($relativePath);

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }

        $this->assertSame(0, Artisan::call('members:backfill-data-structure', [
            '--commit' => true,
            '--unlock-write' => true,
            '--confirm' => 'BACKFILL_MEMBER_DATA',
            '--json' => true,
            '--report-path' => $relativePath,
        ]));

        $this->assertFileExists($absolutePath);

        $decoded = json_decode((string) File::get($absolutePath), true);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('environment', $decoded);
        $this->assertArrayHasKey('options', $decoded);

        File::delete($absolutePath);
    }

    public function test_migration_source_hash_is_filled_on_created_records(): void
    {
        $user = $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $pessoal = DadosPessoais::query()->where('user_id', $user->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNotNull($pessoal->migration_source_hash);
        $this->assertNotNull($config->migration_source_hash);
        $this->assertNotSame('', $pessoal->migration_source_hash);
        $this->assertNotSame('', $config->migration_source_hash);
    }

    public function test_migrated_from_users_at_is_filled_on_created_records(): void
    {
        $user = $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $pessoal = DadosPessoais::query()->where('user_id', $user->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNotNull($pessoal->migrated_from_users_at);
        $this->assertNotNull($config->migrated_from_users_at);
    }

    public function test_audit_after_backfill_detects_existing_target_records(): void
    {
        $this->seedBackfillCandidate();

        $this->artisan('members:backfill-data-structure --commit --unlock-write --confirm=BACKFILL_MEMBER_DATA')
            ->assertExitCode(0);

        $this->assertSame(0, Artisan::call('members:audit-data-structure', [
            '--json' => true,
        ]));

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['users_with_dados_pessoais']);
        $this->assertSame(1, $decoded['summary']['users_with_dados_configuracao']);
    }

    private function seedBackfillCandidate(string $nome = 'Backfill User', string $nif = '123456789'): User
    {
        return User::factory()->create([
            'nome_completo' => $nome,
            'name' => $nome,
            'nif' => $nif,
            'data_nascimento' => '2000-05-10',
            'sexo' => 'masculino',
            'rgpd' => true,
            'consentimento' => true,
            'declaracao_de_transporte' => true,
            'afiliacao' => true,
            'estado' => 'ativo',
            'email_secundario' => 'secundario@example.test',
        ]);
    }
}
