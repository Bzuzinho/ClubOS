<?php

namespace Tests\Feature\AccessControl;

use App\Models\DadosConfiguracao;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AccessControlReadinessAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_unresolved_platform_access_users_and_route_guard_gaps_without_writing(): void
    {
        $resolved = User::factory()->create(['perfil' => 'atleta']);
        $unresolved = User::factory()->create(['perfil' => 'sem_tipo_audit']);

        $athleteType = UserType::query()->create([
            'codigo' => 'atleta',
            'nome' => 'Atleta',
            'ativo' => true,
        ]);
        $resolved->userTypes()->attach($athleteType);

        foreach ([$resolved, $unresolved] as $user) {
            DadosConfiguracao::query()->create([
                'user_id' => $user->id,
                'platform_access_enabled' => true,
            ]);
        }

        $before = [
            'users' => User::query()->count(),
            'user_types' => UserType::query()->count(),
            'dados_configuracao' => DadosConfiguracao::query()->count(),
        ];

        $exitCode = Artisan::call('access:audit-readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('access-control-readiness-v1', $payload['contract']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame(2, $payload['summary']['platform_access_user_count']);
        $this->assertSame(1, $payload['summary']['resolved_user_type_count']);
        $this->assertSame(1, $payload['summary']['unresolved_user_type_count']);
        $this->assertSame(0, $payload['summary']['modules_without_granular_permission_tree_count']);
        $this->assertSame(0, $payload['summary']['mutating_routes_without_module_guard_count']);
        $this->assertGreaterThan(0, $payload['summary']['mutating_routes_with_module_only_guard_count']);

        $codes = collect($payload['findings'])->pluck('code');
        $this->assertTrue($codes->contains('platform_user_without_resolved_user_type'));
        $this->assertFalse($codes->contains('menu_module_without_granular_permission_tree'));
        $this->assertFalse($codes->contains('mutating_admin_route_without_module_guard'));
        $this->assertTrue($codes->contains('mutating_admin_route_with_module_only_guard'));

        $this->assertSame($before, [
            'users' => User::query()->count(),
            'user_types' => UserType::query()->count(),
            'dados_configuracao' => DadosConfiguracao::query()->count(),
        ]);
    }

    public function test_fail_on_critical_is_green_when_only_granular_route_warnings_remain(): void
    {
        $exitCode = Artisan::call('access:audit-readiness', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, $payload['summary']['mutating_routes_without_module_guard_count']);
        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertGreaterThan(0, $payload['summary']['warning_count']);
    }

    public function test_fail_on_critical_returns_failure_when_unresolved_platform_user_exists(): void
    {
        $user = User::factory()->create(['perfil' => 'sem_tipo_audit']);
        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'platform_access_enabled' => true,
        ]);

        $this->artisan('access:audit-readiness', [
            '--json' => true,
            '--fail-on-critical' => true,
        ])->assertExitCode(Command::FAILURE);
    }
}
