<?php

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberDataMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MemberDataBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_runs_without_writing_data(): void
    {
        User::factory()->create([
            'nome_completo' => 'Audit User',
            'nif' => '123456789',
            'data_nascimento' => '2000-05-10',
        ]);

        $this->artisan('members:audit-data-structure')
            ->expectsOutputToContain('Auditoria da estrutura de dados do membro')
            ->assertExitCode(0);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_audit_command_returns_expected_summary_lines(): void
    {
        User::factory()->count(2)->create();

        $this->artisan('members:audit-data-structure')
            ->expectsOutputToContain('total_users')
            ->expectsOutputToContain('users_with_personal_payload')
            ->expectsOutputToContain('users_with_configuration_payload')
            ->expectsOutputToContain('users_with_dados_pessoais')
            ->expectsOutputToContain('users_with_dados_configuracao')
            ->assertExitCode(0);
    }

    public function test_audit_command_json_output_is_valid(): void
    {
        User::factory()->create();

        $this->assertSame(0, Artisan::call('members:audit-data-structure', ['--json' => true]));

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('total_users', $decoded['summary']);
    }

    public function test_audit_command_respects_user_id_filter(): void
    {
        $target = User::factory()->create(['nome_completo' => 'Target User']);
        User::factory()->create(['nome_completo' => 'Other User']);

        $this->assertSame(0, Artisan::call('members:audit-data-structure', [
            '--json' => true,
            '--user-id' => $target->id,
        ]));

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['total_users']);
    }

    public function test_audit_command_respects_limit_filter(): void
    {
        User::factory()->count(3)->create();

        $this->assertSame(0, Artisan::call('members:audit-data-structure', [
            '--json' => true,
            '--limit' => 2,
        ]));

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(2, $decoded['summary']['total_users']);
    }

    public function test_backfill_command_dry_run_does_not_write_data(): void
    {
        User::factory()->create([
            'nome_completo' => 'Backfill Dry Run',
            'nif' => '223456789',
        ]);

        $this->artisan('members:backfill-data-structure')
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('sem escrita')
            ->assertExitCode(0);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_backfill_command_reports_would_create_counts(): void
    {
        User::factory()->create([
            'nome_completo' => 'Create Candidate',
            'nif' => '323456789',
            'rgpd' => true,
        ]);

        $this->artisan('members:backfill-data-structure')
            ->expectsOutputToContain('would_create_dados_pessoais')
            ->expectsOutputToContain('would_create_dados_configuracao')
            ->assertExitCode(0);
    }

    public function test_backfill_command_json_output_is_valid(): void
    {
        User::factory()->create();

        $this->assertSame(0, Artisan::call('members:backfill-data-structure', ['--json' => true]));

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertSame('dry-run', $decoded['mode']);
        $this->assertArrayHasKey('summary', $decoded);
    }

    public function test_backfill_commit_option_is_blocked_and_writes_nothing(): void
    {
        User::factory()->create([
            'nome_completo' => 'Commit Blocked User',
            'nif' => '423456789',
        ]);

        $this->artisan('members:backfill-data-structure --commit')
            ->expectsOutputToContain('Backfill com escrita ainda esta bloqueado nesta sprint')
            ->assertExitCode(2);

        $this->assertDatabaseCount('dados_pessoais', 0);
        $this->assertDatabaseCount('dados_configuracao', 0);
    }

    public function test_service_identifies_missing_dados_pessoais_and_dados_configuracao(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Missing Targets',
            'nif' => '523456789',
            'rgpd' => true,
        ]);

        $service = app(MemberDataMigrationService::class);
        $report = $service->buildAuditReport(['user_id' => $user->id]);

        $summary = $report['summary'];

        $this->assertSame(1, $summary['missing_dados_pessoais']);
        $this->assertSame(1, $summary['missing_dados_configuracao']);
    }

    public function test_service_identifies_conflicts_when_target_values_differ(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Conflict User Source',
            'nif' => '623456789',
            'rgpd' => true,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Different Target Name',
            'nif' => '999999999',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
        ]);

        $service = app(MemberDataMigrationService::class);
        $report = $service->buildAuditReport(['user_id' => $user->id]);

        $summary = $report['summary'];

        $this->assertSame(1, $summary['conflicts_dados_pessoais']);
        $this->assertSame(1, $summary['conflicts_dados_configuracao']);
    }

    public function test_fields_absent_in_users_do_not_break_audit(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Absent Field User',
        ]);

        $service = app(MemberDataMigrationService::class);
        $report = $service->buildAuditReport(['user_id' => $user->id]);

        $this->assertIsArray($report['summary']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['absent_source_fields']);
    }

    public function test_payload_signature_is_stable_for_same_payload(): void
    {
        $service = app(MemberDataMigrationService::class);

        $payload = [
            'nome_completo' => 'Stable Hash User',
            'nif' => '723456789',
            'codigo_postal' => '1000-200',
            'config' => [
                'b' => '2',
                'a' => '1',
            ],
        ];

        $signatureA = $service->calculatePayloadSignature($payload);
        $signatureB = $service->calculatePayloadSignature([
            'config' => [
                'a' => '1',
                'b' => '2',
            ],
            'codigo_postal' => '1000-200',
            'nif' => '723456789',
            'nome_completo' => 'Stable Hash User',
        ]);

        $this->assertSame($signatureA, $signatureB);
    }
}
