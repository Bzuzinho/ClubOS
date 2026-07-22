<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PlatformAccessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_valid_credentials_without_platform_access_cannot_login(): void
    {
        $user = $this->userWithPassword();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_with_valid_credentials_and_platform_access_can_login(): void
    {
        $user = $this->userWithPassword();
        app(PlatformAccessService::class)->grantPlatformAccess($user, null, 'test grant');

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_athlete_with_technical_password_without_platform_access_cannot_login(): void
    {
        $athlete = $this->userWithPassword([
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'ativo_desportivo' => true,
        ]);

        $this->post('/login', ['email' => $athlete->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guardian_with_technical_password_without_platform_access_cannot_login(): void
    {
        $guardian = $this->userWithPassword([
            'perfil' => 'encarregado',
            'tipo_membro' => ['Encarregado de Educacao'],
        ]);

        $this->post('/login', ['email' => $guardian->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_functional_admin_without_platform_access_cannot_login(): void
    {
        $admin = $this->userWithPassword([
            'perfil' => 'admin',
            'tipo_membro' => ['Admin'],
        ]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_legacy_portal_active_flag_does_not_grant_login(): void
    {
        $user = $this->userWithPassword();
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'acesso_portal_ativo' => true,
            'platform_access_enabled' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_public_registration_does_not_create_platform_session_without_explicit_access(): void
    {
        $response = $this->post('/register', [
            'name' => 'Pending Portal User',
            'email' => 'pending.portal@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(route('login', absolute: false));
        $this->assertDatabaseHas('users', ['email' => 'pending.portal@example.test']);
        $this->assertGuest();
    }

    public function test_grant_command_dry_run_does_not_change_data(): void
    {
        $user = $this->userWithPassword();

        Artisan::call('people:grant-platform-access', [
            'user' => $user->id,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse(app(PlatformAccessService::class)->hasPlatformAccess($user));
    }

    public function test_grant_command_apply_enables_access(): void
    {
        $user = $this->userWithPassword();

        Artisan::call('people:grant-platform-access', [
            'user' => $user->email,
            '--notes' => 'manual test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['applied']);
        $this->assertFalse($payload['previous_platform_access_enabled']);
        $this->assertTrue($payload['new_platform_access_enabled']);
        $this->assertTrue(app(PlatformAccessService::class)->hasPlatformAccess($user));
    }

    public function test_revoke_command_apply_removes_access(): void
    {
        $user = $this->userWithPassword();
        app(PlatformAccessService::class)->grantPlatformAccess($user);

        Artisan::call('people:revoke-platform-access', [
            'user' => $user->id,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['applied']);
        $this->assertTrue($payload['previous_platform_access_enabled']);
        $this->assertFalse($payload['new_platform_access_enabled']);
        $this->assertFalse(app(PlatformAccessService::class)->hasPlatformAccess($user->refresh()));
    }

    public function test_platform_access_commands_support_report_path_and_json(): void
    {
        $user = $this->userWithPassword();
        $path = storage_path('app/testing/platform-access-command.json');
        File::delete($path);

        Artisan::call('people:grant-platform-access', [
            'user' => $user->id,
            '--dry-run' => true,
            '--json' => true,
            '--report-path' => $path,
        ]);

        $this->assertFileExists($path);
        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($user->id, $payload['user_id']);
        $this->assertTrue($payload['dry_run']);
        File::delete($path);
    }

    public function test_audit_counts_access_from_new_flag_and_login_gate_is_restricted(): void
    {
        $granted = $this->userWithPassword();
        $legacyPortal = $this->userWithPassword();
        app(PlatformAccessService::class)->grantPlatformAccess($granted);
        DadosConfiguracao::query()->create([
            'user_id' => $legacyPortal->id,
            'acesso_portal_ativo' => true,
            'platform_access_enabled' => false,
        ]);

        Artisan::call('people:audit-member-model', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $codes = collect($payload['findings'])->pluck('code')->all();

        $this->assertSame(1, $payload['summary']['platform_access_granted_count']);
        $this->assertContains('platform_login_gate_restricted_to_explicit_access', $codes);
        $this->assertNotContains('platform_login_gate_too_permissive', $codes);
        $this->assertSame(1, $payload['summary']['platform_login_gate_restricted_count']);
    }

    public function test_inspection_shows_only_enabled_flag_as_access_granted(): void
    {
        $granted = $this->userWithPassword();
        $withPasswordOnly = $this->userWithPassword();
        $functional = $this->userWithPassword(['perfil' => 'financeiro']);
        app(PlatformAccessService::class)->grantPlatformAccess($granted);

        Artisan::call('people:inspect-member-platform-access', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $rows = collect($payload['rows'])->keyBy('user_id');

        $this->assertTrue($rows[$granted->id]['platform_access_granted']);
        $this->assertFalse($rows[$withPasswordOnly->id]['platform_access_granted']);
        $this->assertFalse($rows[$functional->id]['platform_access_granted']);
        $this->assertSame(1, $payload['summary']['platform_access_granted_count']);
    }

    public function test_member_audits_remain_read_only(): void
    {
        $this->userWithPassword();
        $before = $this->snapshot();

        Artisan::call('people:audit-member-model', ['--json' => true]);
        Artisan::call('people:inspect-member-platform-access', ['--json' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function userWithPassword(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('password'),
            'estado' => 'ativo',
            'email_utilizador' => null,
            'ativo_desportivo' => false,
        ], $overrides));

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => $user->nome_completo ?: $user->name,
            'data_nascimento' => $user->data_nascimento,
            'nif' => fake()->unique()->numerify('#########'),
            'documento_identificacao' => fake()->unique()->numerify('########'),
            'contacto' => '912345678',
        ]);

        return $user;
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
            'authenticated_user' => Auth::id() === null ? 0 : 1,
        ];
    }
}
