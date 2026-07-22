<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MemberPlatformAccessInspectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_output_lists_member_without_platform_access_as_not_actionable(): void
    {
        $user = $this->validMember(role: false);

        $payload = $this->inspect(['--json' => true, '--user' => $user->id]);
        $row = $payload['rows'][0];

        $this->assertFalse($row['access_expected']);
        $this->assertFalse($row['has_access_role']);
        $this->assertFalse($row['actionable']);
        $this->assertSame('no_access_expected', $row['platform_access_status']);
        $this->assertSame('no_action_needed_member_without_platform_access', $row['recommendation']);
        $this->assertFalse($row['known_current_access_user']);
        $this->assertFalse($row['has_technical_role']);
        $this->assertSame(1, $payload['summary']['total_no_platform_access_expected']);
        $this->assertSame(0, $payload['summary']['platform_access_issue_count']);
    }

    public function test_access_role_is_configured_clean(): void
    {
        $role = $this->userType('socio', 'Socio');
        $user = $this->validMember(role: $role);

        $payload = $this->inspect(['--json' => true, '--user' => $user->id]);
        $row = $payload['rows'][0];

        $this->assertTrue($row['access_expected']);
        $this->assertTrue($row['has_access_role']);
        $this->assertFalse($row['actionable']);
        $this->assertSame('access_configured', $row['platform_access_status']);
        $this->assertSame('no_action_needed_access_clean', $row['recommendation']);
        $this->assertSame(1, $payload['summary']['total_platform_access_configured']);
    }

    public function test_portal_eligible_without_granted_access_is_not_actionable(): void
    {
        $user = $this->validMember(role: false);
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'acesso_portal_ativo' => true,
            'ultimo_envio_acessos_at' => null,
        ]);

        $payload = $this->inspect(['--json' => true, '--user' => $user->id]);
        $row = $payload['rows'][0];

        $this->assertTrue($row['portal_eligible']);
        $this->assertFalse($row['platform_access_granted']);
        $this->assertFalse($row['access_expected']);
        $this->assertFalse($row['has_access_role']);
        $this->assertFalse($row['actionable']);
        $this->assertSame('portal_eligible_without_access', $row['platform_access_status']);
        $this->assertSame('no_action_needed_portal_eligible_without_access', $row['recommendation']);
        $this->assertSame(1, $payload['summary']['portal_eligible_count']);
        $this->assertSame(1, $payload['summary']['portal_eligible_without_access_count']);
        $this->assertSame(0, $payload['summary']['platform_access_issue_count']);
    }

    public function test_access_invite_without_role_is_actionable(): void
    {
        $user = $this->validMember(role: false);
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'acesso_portal_ativo' => true,
            'ultimo_envio_acessos_at' => now(),
        ]);

        $payload = $this->inspect(['--json' => true, '--user' => $user->id]);
        $row = $payload['rows'][0];

        $this->assertTrue($row['portal_eligible']);
        $this->assertTrue($row['platform_access_granted']);
        $this->assertSame('access_invitation_or_acceptance_recorded', $row['platform_access_granted_reason']);
        $this->assertTrue($row['access_expected']);
        $this->assertFalse($row['has_access_role']);
        $this->assertTrue($row['actionable']);
        $this->assertSame('access_granted_missing_role', $row['platform_access_status']);
        $this->assertSame('assign_access_role_after_review', $row['recommendation']);
        $this->assertSame(1, $payload['summary']['platform_access_granted_count']);
        $this->assertSame(1, $payload['summary']['platform_access_issue_count']);
    }

    public function test_only_actionable_keeps_only_missing_access_roles(): void
    {
        $this->validMember(role: false);
        $granted = $this->validMember(role: false);
        DadosConfiguracao::query()->create([
            'user_id' => $granted->id,
            'acesso_portal_ativo' => true,
            'ultimo_envio_acessos_at' => now(),
        ]);

        $payload = $this->inspect(['--json' => true, '--only-actionable' => true]);

        $this->assertCount(1, $payload['rows']);
        $this->assertSame($granted->id, $payload['rows'][0]['user_id']);
        $this->assertSame(1, $payload['summary']['actionable_count']);
    }

    public function test_functional_admin_without_grant_is_not_actionable(): void
    {
        $admin = $this->validMember([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
        ], role: false);

        $payload = $this->inspect(['--json' => true, '--user' => $admin->id]);
        $row = $payload['rows'][0];

        $this->assertFalse($row['platform_access_granted']);
        $this->assertFalse($row['access_expected']);
        $this->assertFalse($row['actionable']);
        $this->assertSame('no_explicit_platform_access_configured', $row['access_expected_reason']);
        $this->assertSame('no_action_needed_functional_profile_without_platform_access', $row['recommendation']);
        $this->assertSame(1, $payload['summary']['functional_admin_without_access_count']);
        $this->assertSame(0, $payload['summary']['platform_access_issue_count']);
    }

    public function test_known_current_access_user_is_actionable_without_technical_role(): void
    {
        $current = $this->validMember([
            'name' => 'Ricardo Jorge Guerra Vitorino Ferreira',
            'nome_completo' => 'Ricardo Jorge Guerra Vitorino Ferreira',
        ], role: false);

        $payload = $this->inspect(['--json' => true, '--user' => $current->id]);
        $row = $payload['rows'][0];

        $this->assertTrue($row['known_current_access_user']);
        $this->assertTrue($row['platform_access_granted']);
        $this->assertSame('current_known_platform_access_users_audit_only', $row['platform_access_granted_reason']);
        $this->assertTrue($row['access_expected']);
        $this->assertTrue($row['actionable']);
        $this->assertSame(1, $payload['summary']['known_current_access_user_count']);
        $this->assertSame(1, $payload['summary']['platform_access_issue_count']);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->validMember(role: false);
        $path = storage_path('app/testing/member-platform-access-inspection.json');
        File::delete($path);

        Artisan::call('people:inspect-member-platform-access', [
            '--json' => true,
            '--report-path' => $path,
        ]);

        $this->assertFileExists($path);
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('c1-member-model-audit-v1', $payload['version']);
        $this->assertArrayHasKey('rows', $payload);
        File::delete($path);
    }

    public function test_inspection_is_read_only(): void
    {
        $this->validMember(role: false);
        $before = $this->snapshot();

        Artisan::call('people:inspect-member-platform-access', ['--json' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    private function validMember(array $userOverrides = [], UserType|false|null $role = null): User
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

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => $user->nome_completo ?: $user->name,
            'data_nascimento' => $user->data_nascimento,
            'nif' => fake()->unique()->numerify('#########'),
            'documento_identificacao' => fake()->unique()->numerify('########'),
            'contacto' => '912345678',
        ]);

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
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function inspect(array $arguments): array
    {
        Artisan::call('people:inspect-member-platform-access', $arguments);

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
            'dados_configuracao' => DadosConfiguracao::query()->count(),
            'user_user_type' => DB::table('user_user_type')->count(),
        ];
    }
}
