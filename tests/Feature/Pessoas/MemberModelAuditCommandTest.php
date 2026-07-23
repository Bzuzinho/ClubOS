<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\AthleteSportsData;
use App\Models\DadosConfiguracao;
use App\Models\DadosFinanceiros;
use App\Models\DadosPessoais;
use App\Models\Familia;
use App\Models\User;
use App\Models\UserType;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberModelAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_user_member_is_clean(): void
    {
        $user = $this->validMember(role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('member_model_clean', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
    }

    public function test_duplicate_case_insensitive_email_generates_duplicate_user_email(): void
    {
        $this->validMember(['email' => 'duplicado@example.test']);
        $this->validMember(['email' => 'DUPLICADO@example.test']);

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('duplicate_user_email', collect($payload['findings'])->pluck('code')->all());
        $this->assertGreaterThanOrEqual(1, $payload['summary']['duplicate_identity_count']);
    }

    public function test_user_without_name_generates_missing_identity(): void
    {
        $user = $this->validMember(['name' => ' ', 'nome_completo' => ' '], ['nome_completo' => ' ']);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('user_missing_required_identity', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_member_profile_linked_to_missing_user_generates_invalid_link(): void
    {
        $missingUserId = (string) Str::uuid();
        Schema::dropIfExists('dados_pessoais');
        Schema::create('dados_pessoais', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('nome_completo')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('nif')->nullable();
            $table->string('documento_identificacao')->nullable();
            $table->string('contacto')->nullable();
            $table->timestamps();
        });

        DB::table('dados_pessoais')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $missingUserId,
            'nome_completo' => 'Ficha Orfa',
            'data_nascimento' => '2000-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('member_user_link_invalid', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_minor_without_guardian_generates_minor_without_guardian(): void
    {
        $minor = $this->validMember([
            'menor' => true,
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ], [
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $minor->id]);

        $this->assertContains('minor_without_guardian', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_guardian_without_dependents_is_reported_as_non_actionable_info(): void
    {
        $guardianType = $this->userType('encarregado', 'Encarregado de Educacao');
        $guardian = $this->validMember([
            'perfil' => 'encarregado',
            'tipo_membro' => ['Encarregado de Educacao'],
        ], role: $guardianType);

        $payload = $this->audit(['--json' => true, '--user' => $guardian->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'guardian_without_dependents');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
    }

    public function test_active_athlete_with_access_without_sports_profile_is_reported(): void
    {
        $athleteType = $this->userType('atleta', 'Atleta');
        $athlete = $this->validMember([
            'email_utilizador' => 'atleta.login@example.test',
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
        ], role: $athleteType);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);

        $this->assertContains('active_athlete_without_sports_profile', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_active_athlete_with_sports_profile_does_not_generate_sports_warning(): void
    {
        $athleteType = $this->userType('atleta', 'Atleta');
        $athlete = $this->validMember([
            'email_utilizador' => 'sports-ok@example.test',
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
        ], role: $athleteType);
        AthleteSportsData::query()->create([
            'user_id' => $athlete->id,
            'ativo' => true,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);

        $this->assertNotContains('active_athlete_without_sports_profile', collect($payload['findings'])->pluck('code')->all());
        $this->assertTrue(collect($payload['findings'])->every(fn (array $finding): bool => ($finding['context']['has_athlete_sports_data'] ?? false) === true));
    }

    public function test_active_legacy_athlete_without_sports_profile_is_pending_info_not_warning(): void
    {
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'email_utilizador' => null,
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'athlete_sports_profile_pending_setup');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertNotContains('active_athlete_without_sports_profile', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_active_athlete_without_clear_financial_obligation_is_info_not_warning(): void
    {
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'email_utilizador' => null,
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'athlete_financial_setup_not_required_or_unknown');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertNotContains('active_athlete_without_financial_setup', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_active_athlete_with_clear_financial_obligation_without_setup_is_warning(): void
    {
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
        ], role: false);
        $this->insertInvoice([
            'user_id' => $athlete->id,
            'tipo' => 'mensalidade',
            'estado_pagamento' => 'pendente',
            'valor_em_aberto' => 25,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'active_athlete_without_financial_setup');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
        $this->assertTrue($finding['actionable']);
    }

    public function test_member_athlete_without_login_and_without_user_type_is_reclassified_as_expected_info(): void
    {
        $user = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'email_utilizador' => null,
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'athlete_without_platform_access_expected');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_minor_athlete_without_role_is_not_permission_warning(): void
    {
        $minor = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'menor' => true,
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ], [
            'data_nascimento' => now()->subYears(12)->toDateString(),
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $minor->id]);

        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertContains('athlete_without_platform_access_expected', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_guardian_without_role_is_not_permission_warning_without_explicit_access(): void
    {
        $guardian = $this->validMember([
            'perfil' => 'encarregado',
            'tipo_membro' => ['Encarregado de Educacao'],
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $guardian->id]);

        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertContains('guardian_without_platform_access_expected', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_member_with_unknown_status_is_reported(): void
    {
        $user = $this->validMember(['estado' => 'hibernado']);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('member_unknown_status', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_login_user_without_role_is_reported(): void
    {
        $user = $this->validMember(['email_utilizador' => 'login@example.test', 'password' => bcrypt('secret')], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertContains('member_without_platform_access_expected', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_portal_eligible_without_granted_access_is_not_permission_warning(): void
    {
        $user = $this->validMember(role: false);
        $this->enablePortalEligibility($user);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'portal_eligible_without_platform_access');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertTrue($finding['context']['portal_eligible']);
        $this->assertFalse($finding['context']['platform_access_granted']);
        $this->assertFalse($finding['context']['access_expected']);
        $this->assertSame('portal_eligible_without_granted_access', $finding['context']['access_expected_reason']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
        $this->assertSame(1, $payload['summary']['portal_eligible_count']);
        $this->assertSame(1, $payload['summary']['portal_eligible_without_access_count']);
        $this->assertSame(0, $payload['summary']['platform_access_granted_count']);
    }

    public function test_portal_eligibility_for_common_member_types_does_not_require_access_role(): void
    {
        $users = [
            $this->validMember(['perfil' => 'atleta', 'tipo_membro' => ['Atleta'], 'ativo_desportivo' => true], role: false),
            $this->validMember(['perfil' => 'atleta', 'tipo_membro' => ['Atleta'], 'ativo_desportivo' => true, 'menor' => true, 'data_nascimento' => now()->subYears(12)->toDateString()], [
                'data_nascimento' => now()->subYears(12)->toDateString(),
            ], role: false),
            $this->validMember(['perfil' => 'encarregado', 'tipo_membro' => ['Encarregado de Educacao']], role: false),
            $this->validMember(role: false),
        ];
        foreach ($users as $user) {
            $this->enablePortalEligibility($user);
        }

        $payload = $this->audit(['--json' => true]);

        $this->assertSame(4, $payload['summary']['portal_eligible_count']);
        $this->assertSame(4, $payload['summary']['portal_eligible_without_access_count']);
        $this->assertSame(0, $payload['summary']['platform_access_granted_count']);
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(4, collect($payload['findings'])->where('code', 'portal_eligible_without_platform_access')->count());
    }

    public function test_portal_invite_without_enabled_flag_is_not_granted_access(): void
    {
        $user = $this->validMember(role: false);
        $this->enablePortalEligibility($user, lastAccessEmailSent: true);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'portal_eligible_without_platform_access');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertTrue($finding['context']['portal_eligible']);
        $this->assertFalse($finding['context']['platform_access_granted']);
        $this->assertSame('no_explicit_platform_access_enabled', $finding['context']['platform_access_granted_reason']);
        $this->assertSame(0, $payload['summary']['platform_access_granted_count']);
        $this->assertSame(0, $payload['summary']['platform_access_granted_missing_role_count']);
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_contact_email_without_credentials_or_access_role_is_not_permission_warning(): void
    {
        $user = $this->validMember(['email' => 'contact-only@example.test', 'email_utilizador' => null, 'password' => ''], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'member_without_platform_access_expected');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
        $this->assertSame(1, $payload['summary']['login_identifier_only_count']);
        $this->assertSame(1, $payload['summary']['total_no_platform_access_expected']);
    }

    public function test_functional_member_types_are_not_treated_as_access_roles(): void
    {
        $user = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'password' => '',
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'athlete_without_platform_access_expected');

        $this->assertNotNull($finding);
        $this->assertContains('Atleta', $finding['context']['member_functional_types']);
        $this->assertSame([], $finding['context']['access_roles']);
        $this->assertFalse($finding['context']['has_access_role']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_access_role_is_reported_separately_from_functional_member_type(): void
    {
        $role = $this->userType('gestor', 'Gestor');
        $user = $this->validMember([
            'perfil' => 'socio',
            'tipo_membro' => ['Socio'],
            'password' => '',
        ], role: $role);
        app(PlatformAccessService::class)->grantPlatformAccess($user);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'member_model_clean');

        $this->assertNotNull($finding);
        $this->assertContains('Socio', $finding['context']['member_functional_types']);
        $this->assertContains('gestor', $finding['context']['access_roles']);
        $this->assertTrue($finding['context']['has_access_role']);
        $this->assertTrue($finding['context']['has_login']);
        $this->assertNotContains('user_missing_role', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_year_before_1900_birthdate_is_warning(): void
    {
        $user = $this->validMember([
            'data_nascimento' => '0098-06-11',
        ], [
            'data_nascimento' => '0098-06-11',
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'birthdate_year_out_of_range');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
        $this->assertTrue($finding['actionable']);
        $this->assertSame(1, $payload['summary']['invalid_birthdate_count']);
    }

    public function test_future_birthdate_is_specific_warning(): void
    {
        $futureDate = now()->addYear()->toDateString();
        $user = $this->validMember([
            'data_nascimento' => $futureDate,
        ], [
            'data_nascimento' => $futureDate,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('birthdate_future', collect($payload['findings'])->pluck('code')->all());
        $this->assertNotContains('user_missing_required_identity', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_unrealistic_age_birthdate_is_specific_warning(): void
    {
        $user = $this->validMember([
            'data_nascimento' => '1910-01-01',
        ], [
            'data_nascimento' => '1910-01-01',
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'birthdate_age_unrealistic');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_administrative_placeholder_birthdate_is_info(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'estado' => 'ativo',
            'data_nascimento' => '1900-01-01',
            'password' => '',
        ]);
        DB::table('users')->where('id', $admin->id)->update(['password' => '']);

        $payload = $this->audit(['--json' => true, '--user' => $admin->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'administrative_placeholder_birthdate');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertSame(1, $payload['summary']['placeholder_birthdate_count']);
    }

    public function test_summary_distinguishes_login_credentials_from_identifier_only_members(): void
    {
        $this->validMember(['email' => 'identifier-only@example.test', 'password' => ''], role: false);
        $this->validMember(['email_utilizador' => 'credentialed@example.test', 'password' => bcrypt('secret')], role: false);

        $payload = $this->audit(['--json' => true]);

        $this->assertSame(1, $payload['summary']['login_credentials_detected_count']);
        $this->assertSame(1, $payload['summary']['login_identifier_only_count']);
        $this->assertSame(0, $payload['summary']['total_login_users_detected']);
        $this->assertSame(2, $payload['summary']['total_member_only_users_detected']);
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
        $this->assertSame(2, $payload['summary']['total_no_platform_access_expected']);
    }

    public function test_orphan_permission_rows_are_reported(): void
    {
        Schema::dropIfExists('user_user_type');
        Schema::create('user_user_type', function ($table): void {
            $table->uuid('user_id');
            $table->uuid('user_type_id');
            $table->timestamps();
        });
        Schema::dropIfExists('user_type_permissions');
        Schema::create('user_type_permissions', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_type_id');
            $table->string('modulo');
            $table->boolean('pode_ver')->default(true);
            $table->boolean('pode_editar')->default(false);
            $table->boolean('pode_eliminar')->default(false);
            $table->timestamps();
        });

        DB::statement('PRAGMA foreign_keys=OFF');
        DB::table('user_user_type')->insert([
            'user_id' => (string) Str::uuid(),
            'user_type_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_type_permissions')->insert([
            'id' => (string) Str::uuid(),
            'user_type_id' => (string) Str::uuid(),
            'modulo' => 'membros',
            'pode_ver' => true,
            'pode_editar' => false,
            'pode_eliminar' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('PRAGMA foreign_keys=ON');

        $payload = $this->audit(['--json' => true]);

        $this->assertContains('permission_orphan_user', collect($payload['findings'])->pluck('code')->all());
        $this->assertGreaterThanOrEqual(1, $payload['summary']['permission_issue_count']);
    }

    public function test_functional_admin_without_explicit_grant_is_info_not_warning(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'ativo_desportivo' => false,
            'estado' => 'ativo',
            'password' => '',
        ]);
        DB::table('users')->where('id', $admin->id)->update(['password' => '']);

        $payload = $this->audit(['--json' => true, '--user' => $admin->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'functional_admin_or_operational_without_platform_access');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertContains('admin_user', $finding['context']['functional_classification']);
        $this->assertFalse($finding['context']['access_expected']);
        $this->assertSame('no_explicit_platform_access_configured', $finding['context']['access_expected_reason']);
        $this->assertSame(0, $payload['summary']['admin_access_role_issue_count']);
        $this->assertSame(0, $payload['summary']['admin_or_operational_access_missing_role_count']);
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_active_operational_without_explicit_grant_is_info_not_warning(): void
    {
        $operator = $this->validMember([
            'perfil' => 'financeiro',
            'tipo_membro' => ['Socio'],
            'estado' => 'ativo',
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $operator->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'functional_admin_or_operational_without_platform_access');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertFalse($finding['context']['access_expected']);
        $this->assertSame('no_explicit_platform_access_configured', $finding['context']['access_expected_reason']);
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_inactive_admin_without_user_type_is_not_warning(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
            'estado' => 'inativo',
            'password' => '',
        ]);
        DB::table('users')->where('id', $admin->id)->update(['password' => '']);

        $payload = $this->audit(['--json' => true, '--user' => $admin->id]);

        $this->assertNotContains('admin_or_operational_access_missing_role', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame(0, $payload['summary']['permission_issue_count']);
    }

    public function test_only_actionable_removes_reclassified_info_findings(): void
    {
        $member = $this->validMember(['email_utilizador' => null], role: false);

        $payload = $this->audit(['--json' => true, '--only-actionable' => true, '--user' => $member->id]);

        $this->assertSame([], $payload['findings']);
        $this->assertSame(0, $payload['summary']['info_count']);
    }

    public function test_fail_on_warning_returns_exit_one_when_warning_exists(): void
    {
        $user = $this->validMember(role: false);
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'acesso_portal_ativo' => true,
            'platform_access_enabled' => true,
        ]);

        $exitCode = Artisan::call('people:audit-member-model', [
            '--user' => $user->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_current_known_access_users_are_context_until_explicit_grant(): void
    {
        $admin = $this->validMember([
            'name' => 'Administrador',
            'nome_completo' => 'Administrador',
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
        ], role: false);
        $ricardo = $this->validMember([
            'name' => 'Ricardo Jorge Guerra Vitorino Ferreira',
            'nome_completo' => 'Ricardo Jorge Guerra Vitorino Ferreira',
            'perfil' => 'socio',
            'tipo_membro' => ['Socio'],
        ], role: false);

        $payload = $this->audit(['--json' => true]);
        $findings = collect($payload['findings']);
        $adminFinding = $findings->first(fn (array $finding): bool => $finding['user_id'] === $admin->id && $finding['code'] === 'functional_admin_or_operational_without_platform_access');
        $ricardoFinding = $findings->first(fn (array $finding): bool => $finding['user_id'] === $ricardo->id && $finding['code'] === 'member_without_platform_access_expected');

        $this->assertNotNull($adminFinding);
        $this->assertNotNull($ricardoFinding);
        $this->assertSame('no_explicit_platform_access_enabled', $adminFinding['context']['platform_access_granted_reason']);
        $this->assertSame('no_explicit_platform_access_enabled', $ricardoFinding['context']['platform_access_granted_reason']);
        $this->assertTrue($adminFinding['context']['known_current_access_user']);
        $this->assertTrue($ricardoFinding['context']['known_current_access_user']);
        $this->assertFalse($adminFinding['context']['platform_access_granted']);
        $this->assertFalse($ricardoFinding['context']['platform_access_granted']);
        $this->assertSame(0, $payload['summary']['total_platform_access_configured']);
        $this->assertSame(0, $payload['summary']['platform_access_granted_count']);
        $this->assertSame(2, $payload['summary']['known_current_access_user_count']);
        $this->assertCount(2, $payload['schema_detected']['platform_access_schema']['known_current_access_users_detected']);
    }

    public function test_platform_login_gate_with_grant_filter_is_reported_as_restricted_info(): void
    {
        $this->validMember(role: false);

        $payload = $this->audit(['--json' => true]);
        $finding = collect($payload['findings'])->firstWhere('code', 'platform_login_gate_restricted_to_explicit_access');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
        $this->assertSame('no_action_needed_login_gate_restricted', $finding['recommendation']);
        $this->assertSame(0, $payload['summary']['platform_login_gate_issue_count']);
        $this->assertSame(0, $payload['summary']['security_issue_count']);
        $this->assertSame(1, $payload['summary']['platform_login_gate_restricted_count']);
    }

    public function test_invoices_without_estado_column_do_not_crash_and_open_monthly_obligation_is_detected(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->insertInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'estado_pagamento' => 'pendente',
            'valor_em_aberto' => 25,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertFalse($payload['schema_detected']['invoice_columns']['estado']);
        $this->assertContains('inactive_member_with_active_financial_obligation', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_invoice_payment_status_and_open_amount_columns_are_used_when_present(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->insertInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'estado_pagamento' => 'pago',
            'valor_em_aberto' => 12,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('inactive_member_with_active_financial_obligation', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_invoice_estado_column_filters_cancelled_invoices_when_present(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->recreateInvoicesTable(['user_id', 'tipo', 'estado', 'estado_pagamento', 'valor_em_aberto']);
        $this->insertInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'estado' => 'cancelada',
            'estado_pagamento' => 'pendente',
            'valor_em_aberto' => 50,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertTrue($payload['schema_detected']['invoice_columns']['estado']);
        $this->assertNotContains('inactive_member_with_active_financial_obligation', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_invoices_without_type_or_origin_do_not_crash_and_generate_limited_info(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->recreateInvoicesTable(['user_id', 'estado_pagamento', 'valor_em_aberto']);
        $this->insertInvoice([
            'user_id' => $user->id,
            'estado_pagamento' => 'pendente',
            'valor_em_aberto' => 50,
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'financial_profile_check_limited');

        $this->assertNotNull($finding);
        $this->assertSame('info', $finding['severity']);
        $this->assertFalse($finding['actionable']);
    }

    public function test_invoices_without_payment_state_or_open_amount_do_not_crash_and_generate_limited_info(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->recreateInvoicesTable(['user_id', 'tipo']);
        $this->insertInvoice([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
        ]);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertContains('financial_profile_check_limited', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_missing_invoices_table_does_not_crash(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('invoices');
        Schema::enableForeignKeyConstraints();

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);

        $this->assertFalse($payload['schema_detected']['financial_tables']['invoices']);
        $this->assertNotContains('financial_profile_check_limited', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_schema_detected_includes_real_invoice_columns(): void
    {
        $this->validMember();

        $payload = $this->audit(['--json' => true]);

        $this->assertArrayHasKey('financial_tables', $payload['schema_detected']);
        $this->assertArrayHasKey('invoice_columns', $payload['schema_detected']);
        foreach (['user_id', 'tipo', 'origem_tipo', 'estado', 'status', 'estado_pagamento', 'valor_em_aberto'] as $column) {
            $this->assertArrayHasKey($column, $payload['schema_detected']['invoice_columns']);
        }
    }

    public function test_only_actionable_hides_schema_limited_financial_info(): void
    {
        $user = $this->validMember(['estado' => 'inativo']);
        $this->recreateInvoicesTable(['user_id', 'tipo']);

        $payload = $this->audit(['--json' => true, '--only-actionable' => true, '--user' => $user->id]);

        $this->assertNotContains('financial_profile_check_limited', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_json_output_is_valid_and_contains_schema_contract(): void
    {
        $this->validMember();

        $payload = $this->audit(['--json' => true]);

        $this->assertSame('c1-member-model-audit-v1', $payload['version']);
        $this->assertArrayHasKey('schema_detected', $payload);
        $this->assertSame('dados_pessoais', $payload['schema_detected']['member_profile_table']);
        $this->assertArrayHasKey('total_login_users_detected', $payload['summary']);
        $this->assertArrayHasKey('total_platform_access_expected', $payload['summary']);
        $this->assertArrayHasKey('total_platform_access_configured', $payload['summary']);
        $this->assertArrayHasKey('total_no_platform_access_expected', $payload['summary']);
        $this->assertArrayHasKey('portal_eligible_count', $payload['summary']);
        $this->assertArrayHasKey('portal_eligible_without_access_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_granted_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_granted_missing_role_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_expected_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_expected_without_role_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_issue_count', $payload['summary']);
        $this->assertArrayHasKey('known_current_access_user_count', $payload['summary']);
        $this->assertArrayHasKey('functional_admin_without_access_count', $payload['summary']);
        $this->assertArrayHasKey('functional_operational_without_access_count', $payload['summary']);
        $this->assertArrayHasKey('security_issue_count', $payload['summary']);
        $this->assertArrayHasKey('platform_login_gate_issue_count', $payload['summary']);
        $this->assertArrayHasKey('login_credentials_detected_count', $payload['summary']);
        $this->assertArrayHasKey('login_identifier_only_count', $payload['summary']);
        $this->assertArrayHasKey('access_role_missing_count', $payload['summary']);
        $this->assertArrayHasKey('invalid_birthdate_count', $payload['summary']);
        $this->assertArrayHasKey('member_without_login_role_expected_count', $payload['summary']);
        $this->assertArrayHasKey('member_without_platform_access_expected_count', $payload['summary']);
        $this->assertArrayHasKey('athlete_without_platform_access_expected_count', $payload['summary']);
        $this->assertArrayHasKey('guardian_without_platform_access_expected_count', $payload['summary']);
        $this->assertArrayHasKey('member_without_access_role_expected_count', $payload['summary']);
        $this->assertArrayHasKey('suspected_false_positive_reclassified_count', $payload['summary']);
        $this->assertArrayHasKey('platform_access_schema', $payload['schema_detected']);
        $this->assertArrayHasKey('portal_access_active_true_count', $payload['schema_detected']['platform_access_schema']);
        $this->assertArrayHasKey('access_grant_columns_detected', $payload['schema_detected']['platform_access_schema']);
        $this->assertArrayHasKey('invite_columns_detected', $payload['schema_detected']['platform_access_schema']);
        $this->assertArrayHasKey('login_activity_columns_detected', $payload['schema_detected']['platform_access_schema']);
        $this->assertArrayHasKey('role_tables_detected', $payload['schema_detected']['platform_access_schema']);
        $this->assertArrayHasKey('known_current_access_users_detected', $payload['schema_detected']['platform_access_schema']);
    }

    public function test_json_findings_include_functional_classification_context(): void
    {
        $user = $this->validMember(['email_utilizador' => null], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $user->id]);
        $finding = collect($payload['findings'])->firstWhere('code', 'member_without_platform_access_expected');

        $this->assertNotNull($finding);
        $this->assertArrayHasKey('functional_classification', $finding['context']);
        $this->assertContains('person_record', $finding['context']['functional_classification']);
        $this->assertContains('member_profile', $finding['context']['functional_classification']);
        $this->assertFalse($finding['context']['has_login']);
        $this->assertFalse($finding['context']['has_user_type']);
        $this->assertFalse($finding['context']['has_access_role']);
        $this->assertFalse($finding['context']['has_login_credentials']);
        $this->assertTrue($finding['context']['has_login_identifier']);
        $this->assertFalse($finding['context']['access_expected']);
        $this->assertFalse($finding['context']['platform_access_granted']);
        $this->assertFalse($finding['context']['access_role_missing_is_problem']);
        $this->assertSame('no_access_expected', $finding['context']['platform_access_status']);
        $this->assertContains('Socio', $finding['context']['member_functional_types']);
        $this->assertSame([], $finding['context']['access_roles']);
    }

    public function test_password_present_does_not_make_sports_profile_required_by_access(): void
    {
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'password' => bcrypt('secret'),
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);

        $this->assertNotContains('active_athlete_without_sports_profile', collect($payload['findings'])->pluck('code')->all());
        $this->assertSame('legacy_imported_member_without_confirmed_operational_access', collect($payload['findings'])->firstWhere('code', 'athlete_sports_profile_pending_setup')['context']['sports_profile_reason']);
    }

    public function test_password_present_does_not_make_financial_setup_required_by_access(): void
    {
        $athlete = $this->validMember([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
            'password' => bcrypt('secret'),
        ], role: false);

        $payload = $this->audit(['--json' => true, '--user' => $athlete->id]);

        $this->assertNotContains('active_athlete_without_financial_setup', collect($payload['findings'])->pluck('code')->all());
        $this->assertContains('athlete_financial_setup_not_required_or_unknown', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->validMember();
        $path = storage_path('app/testing/member-model-audit.json');
        File::delete($path);

        Artisan::call('people:audit-member-model', [
            '--json' => true,
            '--report-path' => $path,
        ]);

        $this->assertFileExists($path);
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('c1-member-model-audit-v1', $payload['version']);
        File::delete($path);
    }

    public function test_audit_is_read_only(): void
    {
        $this->validMember();
        $before = $this->snapshot();

        Artisan::call('people:audit-member-model', ['--json' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $userOverrides
     * @param array<string,mixed> $profileOverrides
     */
    private function validMember(array $userOverrides = [], array $profileOverrides = [], UserType|false|null $role = null): User
    {
        $user = User::factory()->create(array_merge([
            'perfil' => 'socio',
            'tipo_membro' => ['Socio'],
            'estado' => 'ativo',
            'menor' => false,
            'ativo_desportivo' => false,
            'data_nascimento' => now()->subYears(30)->toDateString(),
            'email_utilizador' => null,
            'password' => '',
        ], $userOverrides));
        if (($userOverrides['password'] ?? '') === '') {
            DB::table('users')->where('id', $user->id)->update(['password' => '']);
        }

        DadosPessoais::query()->create(array_merge([
            'user_id' => $user->id,
            'nome_completo' => $user->nome_completo ?: $user->name,
            'data_nascimento' => $user->data_nascimento,
            'nif' => fake()->unique()->numerify('#########'),
            'documento_identificacao' => fake()->unique()->numerify('########'),
            'contacto' => '912345678',
        ], $profileOverrides));

        if ($role !== false) {
            $role ??= $this->userType('socio', 'Socio');
            DB::table('user_user_type')->insert([
                'user_id' => $user->id,
                'user_type_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function userType(string $codigo, string $nome): UserType
    {
        return UserType::query()->firstOrCreate(
            ['codigo' => $codigo],
            ['nome' => $nome, 'descricao' => $nome, 'ativo' => true],
        );
    }

    /**
     * @param array<int,string> $columns
     */
    private function recreateInvoicesTable(array $columns): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('invoices');
        Schema::create('invoices', function ($table) use ($columns): void {
            $table->uuid('id')->primary();
            if (in_array('user_id', $columns, true)) {
                $table->uuid('user_id')->nullable();
            }
            if (in_array('tipo', $columns, true)) {
                $table->string('tipo')->nullable();
            }
            if (in_array('origem_tipo', $columns, true)) {
                $table->string('origem_tipo')->nullable();
            }
            if (in_array('estado', $columns, true)) {
                $table->string('estado')->nullable();
            }
            if (in_array('status', $columns, true)) {
                $table->string('status')->nullable();
            }
            if (in_array('estado_pagamento', $columns, true)) {
                $table->string('estado_pagamento')->nullable();
            }
            if (in_array('valor_em_aberto', $columns, true)) {
                $table->decimal('valor_em_aberto', 10, 2)->nullable();
            }
            $table->timestamps();
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertInvoice(array $overrides): void
    {
        $row = [
            'id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (['user_id', 'tipo', 'origem_tipo', 'estado', 'status', 'estado_pagamento', 'valor_em_aberto'] as $column) {
            if (Schema::hasColumn('invoices', $column) && array_key_exists($column, $overrides)) {
                $row[$column] = $overrides[$column];
            }
        }

        foreach ([
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 50,
        ] as $column => $value) {
            if (Schema::hasColumn('invoices', $column)) {
                $row[$column] = $value;
            }
        }

        DB::table('invoices')->insert($row);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function audit(array $arguments): array
    {
        Artisan::call('people:audit-member-model', $arguments);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,int>
     */
    private function snapshot(): array
    {
        return [
            'users' => User::query()->count(),
            'dados_pessoais' => DadosPessoais::query()->count(),
            'dados_financeiros' => DadosFinanceiros::query()->count(),
            'athlete_sports_data' => AthleteSportsData::query()->count(),
            'user_guardian' => DB::table('user_guardian')->count(),
            'familias' => Familia::query()->count(),
            'familia_user' => DB::table('familia_user')->count(),
            'user_user_type' => DB::table('user_user_type')->count(),
            'user_type_permissions' => DB::table('user_type_permissions')->count(),
            'dados_configuracao' => DadosConfiguracao::query()->count(),
        ];
    }

    private function enablePortalEligibility(User $user, bool $lastAccessEmailSent = false): void
    {
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'acesso_portal_ativo' => true,
            'ultimo_envio_acessos_at' => $lastAccessEmailSent ? now() : null,
        ]);
    }
}
